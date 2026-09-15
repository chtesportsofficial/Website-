<?php
// admin-deposits-review.php
// Called by admin.html when an admin clicks Approve/Decline on a request.
// Approve: atomically credits wallet_users.balance + logs wallet_transactions,
//          then credits referral commission to the depositor's referrer (if any):
//            - 10% on the depositor's FIRST ever approved deposit (withdrawable
//              immediately), plus the depositor themselves gets +10% bonus
//              (non-withdrawable)
//            - 5% on every deposit after that (withdrawable immediately, no
//              hold), but ONLY for 6 months from the depositor's own join
//              date (wallet_users.created_at — set once at signup, this is
//              the "referred" date). After 6 months from when the friend
//              joined, the referrer stops earning on that friend's deposits,
//              no matter how many more deposits they make.
// Decline: just marks the request rejected with the admin's note.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php';       // exposes $conn (PDO, Postgres)
require_once __DIR__ . '/admin-auth.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$access_token = $input['access_token'] ?? '';
$request_id   = isset($input['request_id']) ? (int)$input['request_id'] : 0;
$action       = $input['action'] ?? '';
$admin_note   = trim($input['admin_note'] ?? '');

if (!in_array($action, ['approve', 'decline'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}
if ($request_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request_id']);
    exit();
}

$admin_uid = verify_admin_token($access_token);
if (!$admin_uid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied — admin only.']);
    exit();
}

$conn->beginTransaction();

try {
    // Lock the request row so two admins can't both act on it at once.
    $stmt = $conn->prepare("SELECT id, user_id, amount, trx_id, status FROM wallet_deposit_requests WHERE id = ? FOR UPDATE");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();

    if (!$req) {
        throw new Exception('Request not found');
    }
    if ($req['status'] !== 'pending') {
        throw new Exception('This request was already reviewed');
    }

    $new_status = $action === 'approve' ? 'approved' : 'rejected';

    if ($action === 'approve') {
        // Lock the user's wallet row too, then credit the balance.
        $stmt = $conn->prepare("SELECT balance, withdrawable_balance, non_withdrawable_balance, referred_by, created_at
             FROM wallet_users WHERE id = ? FOR UPDATE");
        $stmt->execute([$req['user_id']]);
        $wallet = $stmt->fetch();

        if (!$wallet) {
            throw new Exception('Wallet user not found');
        }

        $balance_before = (float)$wallet['balance'];
        $withdrawable_before = (float)$wallet['withdrawable_balance'];
        $non_withdrawable_before = (float)$wallet['non_withdrawable_balance'];

        $deposit_amount = (float)$req['amount'];
        $referred_by = $wallet['referred_by'] ? (int)$wallet['referred_by'] : null;

        // Referral commissions on repeat deposits only run for 6 months
        // from the FRIEND's own join date (wallet_users.created_at is set
        // once at signup and never changes — that's the "referred" date).
        // After 6 months, the referrer stops earning on this friend's
        // deposits no matter how many more they make. The first-deposit
        // 10% bonus is unaffected — it only ever fires once, right after
        // signup, so it's always within the window in practice.
        $referred_join_date = new DateTime($wallet['created_at']);
        $sixMonthsAfterJoin = (clone $referred_join_date)->modify('+6 months');
        $within_commission_window = (new DateTime() <= $sixMonthsAfterJoin);

        /*
        |----------------------------------------------------------------
        | Is this the depositor's FIRST ever deposit?
        |----------------------------------------------------------------
        | Tied to which request was submitted earliest (created_at, id as
        | tiebreaker) among the user's non-rejected requests — NOT to
        | admin's approval order. Without this, if a user has two pending
        | requests and admin happens to approve the 2nd one first, that
        | one would wrongly grab the 10% bonus. This way the 10% always
        | lands on the same fixed request (the one the admin panel badges
        | as "1st deposit"), no matter which order they get approved in.
        | Rejected requests don't count as "the" deposit, so they're
        | skipped when finding the earliest one.
        */
        $is_first_deposit = true;

        if ($referred_by) {
            $stmt = $conn->prepare(
                "SELECT id FROM wallet_deposit_requests
                 WHERE user_id = ? AND status != 'rejected'
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1"
            );
            $stmt->execute([$req['user_id']]);
            $earliest = $stmt->fetch();

            $is_first_deposit = $earliest && ((int)$earliest['id'] === (int)$req['id']);
        }

        // Base deposit credit — always non-withdrawable.
        $bonus_amount = 0.00;

        if ($referred_by && $is_first_deposit) {
            // First-deposit referral bonus: depositor gets +10% extra,
            // also non-withdrawable (must be spent on tournament entries).
            $bonus_amount = round($deposit_amount * 0.10, 2);
        }

        $withdrawable_after = $withdrawable_before;
        $non_withdrawable_after = $non_withdrawable_before + $deposit_amount + $bonus_amount;
        $balance_after = $balance_before + $deposit_amount + $bonus_amount;

        $stmt = $conn->prepare(
            "UPDATE wallet_users
             SET balance = ?,
                 withdrawable_balance = ?,
                 non_withdrawable_balance = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $balance_after,
            $withdrawable_after,
            $non_withdrawable_after,
            $req['user_id']
        ]);

        $type = 'deposit';
        // Format without a trailing ".00" for whole-number amounts, matching
        // how amounts display elsewhere on the site (৳500, not ৳500.00).
        $fmtAmt = function ($n) {
            return (floor($n) == $n) ? number_format($n, 0) : number_format($n, 2);
        };
        $description = $bonus_amount > 0
            ? 'Manual bKash/Nagad deposit approval (৳' . $fmtAmt($deposit_amount) . ' + ৳' . $fmtAmt($bonus_amount) . ' referral bonus)'
            : 'Manual bKash/Nagad deposit approval';
        $tx_status = 'completed';
        $stmt = $conn->prepare(
            "INSERT INTO wallet_transactions
                (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $creditedAmount = $deposit_amount + $bonus_amount;
        $stmt->execute([
            $req['user_id'], $type, $creditedAmount, $balance_before, $balance_after,
            $req['trx_id'], $description, $tx_status
        ]);

        /*
        |----------------------------------------------------------------
        | Referral commission to the referrer (if any)
        |----------------------------------------------------------------
        */
        if ($referred_by && ($is_first_deposit || $within_commission_window)) {

            $commission_rate = $is_first_deposit ? 10 : 5;
            $commission_amount = round($deposit_amount * ($commission_rate / 100), 2);

            // Lock the referrer's wallet row before crediting.
            $stmt = $conn->prepare(
                "SELECT balance, withdrawable_balance
                 FROM wallet_users WHERE id = ? FOR UPDATE"
            );
            $stmt->execute([$referred_by]);
            $referrerWallet = $stmt->fetch();

            if ($referrerWallet) {

                $refBalanceBefore = (float)$referrerWallet['balance'];
                $refWithdrawableBefore = (float)$referrerWallet['withdrawable_balance'];

                $refWithdrawableAfter = $refWithdrawableBefore + $commission_amount;
                $refBalanceAfter = $refBalanceBefore + $commission_amount;

                $stmt = $conn->prepare(
                    "UPDATE wallet_users
                     SET balance = ?,
                         withdrawable_balance = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $refBalanceAfter,
                    $refWithdrawableAfter,
                    $referred_by
                ]);

                $refType = 'referral_commission';
                $refDescription = $is_first_deposit
                    ? 'Referral commission (10% — first deposit)'
                    : 'Referral commission (5% — repeat deposit)';
                $stmt = $conn->prepare(
                    "INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', NOW())"
                );
                $stmt->execute([
                    $referred_by, $refType, $commission_amount, $refBalanceBefore, $refBalanceAfter,
                    $req['trx_id'], $refDescription
                ]);

                $stmt = $conn->prepare(
                    "INSERT INTO referral_commissions
                        (referrer_id, referred_id, deposit_request_id, deposit_amount,
                         commission_rate, commission_amount, is_first_deposit, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $isFirstDepositInt = $is_first_deposit ? 1 : 0;
                $stmt->execute([
                    $referred_by, $req['user_id'], $request_id, $deposit_amount,
                    $commission_rate, $commission_amount, $isFirstDepositInt
                ]);
            }
            // If the referrer's wallet row somehow doesn't exist, we
            // silently skip commission crediting rather than failing the
            // whole deposit approval — the deposit itself still goes through.
        }
    }

    $stmt = $conn->prepare("UPDATE wallet_deposit_requests SET status = ?, admin_note = ?, reviewed_at = NOW() WHERE id = ?");
    $stmt->execute([$new_status, $admin_note, $request_id]);

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
