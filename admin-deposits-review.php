<?php
// admin-deposits-review.php
// Called by admin.html when an admin clicks Approve/Decline on a request.
// Approve: atomically credits wallet_users.balance + logs wallet_transactions,
//          then credits referral commission to the depositor's referrer (if any):
//            - 10% on the depositor's FIRST ever approved deposit (withdrawable),
//              plus the depositor themselves gets +10% bonus (non-withdrawable)
//            - 5% on every deposit after that (withdrawable, but subject to a
//              6-month hold before it can be withdrawn — enforced at withdrawal
//              time by checking referral_commissions.created_at, no extra column
//              needed)
// Decline: just marks the request rejected with the admin's note.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php';       // exposes $conn (mysqli)
$conn->set_charset('utf8mb4');
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

$conn->begin_transaction();

try {
    // Lock the request row so two admins can't both act on it at once.
    $stmt = $conn->prepare("SELECT id, user_id, amount, trx_id, status FROM wallet_deposit_requests WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) {
        throw new Exception('Request not found');
    }
    if ($req['status'] !== 'pending') {
        throw new Exception('This request was already reviewed');
    }

    $new_status = $action === 'approve' ? 'approved' : 'rejected';

    if ($action === 'approve') {
        // Lock the user's wallet row too, then credit the balance.
        $stmt = $conn->prepare("SELECT balance, withdrawable_balance, non_withdrawable_balance, referred_by
             FROM wallet_users WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $req['user_id']);
        $stmt->execute();
        $wallet = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$wallet) {
            throw new Exception('Wallet user not found');
        }

        $balance_before = (float)$wallet['balance'];
        $withdrawable_before = (float)$wallet['withdrawable_balance'];
        $non_withdrawable_before = (float)$wallet['non_withdrawable_balance'];

        $deposit_amount = (float)$req['amount'];
        $referred_by = $wallet['referred_by'] ? (int)$wallet['referred_by'] : null;

        /*
        |----------------------------------------------------------------
        | Is this the depositor's FIRST ever approved deposit?
        |----------------------------------------------------------------
        | Checked against referral_commissions rather than
        | wallet_deposit_requests, since commission rows only exist for
        | referred users — this also naturally handles the case where
        | the depositor has no referrer at all (no rows either way).
        */
        $is_first_deposit = true;

        if ($referred_by) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS cnt FROM referral_commissions WHERE referred_id = ?"
            );
            $stmt->bind_param('i', $req['user_id']);
            $stmt->execute();
            $priorCount = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stmt->close();

            $is_first_deposit = ($priorCount === 0);
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
        $stmt->bind_param(
            'dddi',
            $balance_after,
            $withdrawable_after,
            $non_withdrawable_after,
            $req['user_id']
        );
        $stmt->execute();
        $stmt->close();

        $type = 'deposit';
        $description = $bonus_amount > 0
            ? 'Manual bKash/Nagad deposit approval (+10% referral bonus)'
            : 'Manual bKash/Nagad deposit approval';
        $tx_status = 'completed';
        $stmt = $conn->prepare(
            "INSERT INTO wallet_transactions
                (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $creditedAmount = $deposit_amount + $bonus_amount;
        $stmt->bind_param(
            'isdddsss',
            $req['user_id'], $type, $creditedAmount, $balance_before, $balance_after,
            $req['trx_id'], $description, $tx_status
        );
        $stmt->execute();
        $stmt->close();

        /*
        |----------------------------------------------------------------
        | Referral commission to the referrer (if any)
        |----------------------------------------------------------------
        */
        if ($referred_by) {

            $commission_rate = $is_first_deposit ? 10 : 5;
            $commission_amount = round($deposit_amount * ($commission_rate / 100), 2);

            // Lock the referrer's wallet row before crediting.
            $stmt = $conn->prepare(
                "SELECT balance, withdrawable_balance
                 FROM wallet_users WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param('i', $referred_by);
            $stmt->execute();
            $referrerWallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();

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
                $stmt->bind_param(
                    'ddi',
                    $refBalanceAfter,
                    $refWithdrawableAfter,
                    $referred_by
                );
                $stmt->execute();
                $stmt->close();

                $refType = 'referral_commission';
                $refDescription = $is_first_deposit
                    ? 'Referral commission (10% — first deposit)'
                    : 'Referral commission (5% — repeat deposit)';
                $stmt = $conn->prepare(
                    "INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', NOW())"
                );
                $stmt->bind_param(
                    'isdddss',
                    $referred_by, $refType, $commission_amount, $refBalanceBefore, $refBalanceAfter,
                    $req['trx_id'], $refDescription
                );
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO referral_commissions
                        (referrer_id, referred_id, deposit_request_id, deposit_amount,
                         commission_rate, commission_amount, is_first_deposit, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $isFirstDepositInt = $is_first_deposit ? 1 : 0;
                $stmt->bind_param(
                    'iiidddi',
                    $referred_by, $req['user_id'], $request_id, $deposit_amount,
                    $commission_rate, $commission_amount, $isFirstDepositInt
                );
                $stmt->execute();
                $stmt->close();
            }
            // If the referrer's wallet row somehow doesn't exist, we
            // silently skip commission crediting rather than failing the
            // whole deposit approval — the deposit itself still goes through.
        }
    }

    $stmt = $conn->prepare("UPDATE wallet_deposit_requests SET status = ?, admin_note = ?, reviewed_at = NOW() WHERE id = ?");
    $stmt->bind_param('ssi', $new_status, $admin_note, $request_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
