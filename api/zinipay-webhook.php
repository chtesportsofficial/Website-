<?php
// zinipay-webhook.php
// Called by ZiniPay after a payment update: {invoice_id, status} as JSON body
// OR as query params (?invoice_id=...&status=...) — ZiniPay's docs show both.
// We NEVER trust the webhook body alone: we always call /v1/payment/verify
// ourselves before crediting anything, then run the exact same
// balance-credit + referral-commission logic as admin-deposits-review.php's
// "approve" branch, just auto-triggered instead of admin-clicked.
//
// Always responds 200 to ZiniPay (even on internal errors) so it doesn't
// keep retrying forever; real problems are written to error_log for us to check.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php'; // exposes $conn (PDO, Postgres)

$zinipayApiKey  = getenv('ZINIPAY_API_KEY') ?: '';
$zinipayBaseUrl = 'https://api.zinipay.com';

// ---- Read invoice_id from JSON body first, then fall back to query params ----
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true);

$invoiceId = '';
if (is_array($body) && !empty($body['invoice_id'])) {
    $invoiceId = trim($body['invoice_id']);
} elseif (!empty($_GET['invoice_id'])) {
    $invoiceId = trim($_GET['invoice_id']);
}

if ($invoiceId === '') {
    error_log('[zinipay-webhook] Missing invoice_id in payload: ' . $rawInput);
    http_response_code(200); // acknowledge anyway, nothing to retry
    echo json_encode(['success' => false, 'message' => 'Missing invoice_id']);
    exit;
}

if ($zinipayApiKey === '') {
    error_log('[zinipay-webhook] ZINIPAY_API_KEY not configured');
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Server not configured']);
    exit;
}

// ---- Verify the invoice directly with ZiniPay before trusting anything ----
$ch = curl_init($zinipayBaseUrl . '/v1/payment/verify');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['invoice_id' => $invoiceId]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'zini-api-key: ' . $zinipayApiKey
    ]
]);
$verifyResponse = curl_exec($ch);
$verifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($verifyResponse === false) {
    error_log('[zinipay-webhook] Could not reach ZiniPay verify endpoint for invoice ' . $invoiceId);
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Could not verify with ZiniPay']);
    exit;
}

$verifyData = json_decode($verifyResponse, true);

if ($verifyHttpCode < 200 || $verifyHttpCode >= 300 || !is_array($verifyData)) {
    error_log('[zinipay-webhook] Bad verify response for invoice ' . $invoiceId . ': ' . $verifyResponse);
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Verify failed']);
    exit;
}

$paymentStatus = $verifyData['status'] ?? '';

if ($paymentStatus !== 'COMPLETED') {
    // PENDING or FAILED — nothing to credit yet. Just acknowledge.
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Noted, not completed yet', 'status' => $paymentStatus]);
    exit;
}

$verifiedAmount     = isset($verifyData['amount']) ? (float)$verifyData['amount'] : 0;
$verifiedMethod     = $verifyData['payment_method'] ?? 'zinipay';
$verifiedTrxId      = $verifyData['transaction_id'] ?? null;

$conn->beginTransaction();

try {
    // Lock the matching deposit request row.
    $stmt = $conn->prepare(
        "SELECT id, user_id, amount, status FROM wallet_deposit_requests
         WHERE zinipay_invoice_id = ? FOR UPDATE"
    );
    $stmt->execute([$invoiceId]);
    $req = $stmt->fetch();

    if (!$req) {
        throw new Exception('No matching deposit request for invoice ' . $invoiceId);
    }

    // Idempotency: if this was already approved (e.g. webhook fired twice,
    // or an admin already approved it manually), don't credit again.
    if ($req['status'] !== 'pending') {
        $conn->commit();
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Already processed']);
        exit;
    }

    // Sanity check: the verified amount should match what we asked for.
    if (abs($verifiedAmount - (float)$req['amount']) > 0.01) {
        throw new Exception('Amount mismatch: requested ' . $req['amount'] . ' but ZiniPay verified ' . $verifiedAmount);
    }

    // ---- From here down: identical crediting + referral-commission logic
    //      to admin-deposits-review.php's "approve" branch ----
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

    $referred_join_date = new DateTime($wallet['created_at']);
    $sixMonthsAfterJoin = (clone $referred_join_date)->modify('+6 months');
    $within_commission_window = (new DateTime() <= $sixMonthsAfterJoin);

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

    $bonus_amount = 0.00;
    if ($referred_by && $is_first_deposit) {
        $bonus_amount = round($deposit_amount * 0.10, 2);
    }

    $withdrawable_after = $withdrawable_before;
    $non_withdrawable_after = $non_withdrawable_before + $deposit_amount + $bonus_amount;
    $balance_after = $balance_before + $deposit_amount + $bonus_amount;

    $stmt = $conn->prepare(
        "UPDATE wallet_users
         SET balance = ?, withdrawable_balance = ?, non_withdrawable_balance = ?
         WHERE id = ?"
    );
    $stmt->execute([$balance_after, $withdrawable_after, $non_withdrawable_after, $req['user_id']]);

    $fmtAmt = function ($n) {
        return (floor($n) == $n) ? number_format($n, 0) : number_format($n, 2);
    };
    $description = $bonus_amount > 0
        ? 'ZiniPay auto-verified deposit (৳' . $fmtAmt($deposit_amount) . ' + ৳' . $fmtAmt($bonus_amount) . ' referral bonus)'
        : 'ZiniPay auto-verified deposit';

    $stmt = $conn->prepare(
        "INSERT INTO wallet_transactions
            (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at)
         VALUES (?, 'deposit', ?, ?, ?, ?, ?, 'completed', NOW())"
    );
    $creditedAmount = $deposit_amount + $bonus_amount;
    $stmt->execute([
        $req['user_id'], $creditedAmount, $balance_before, $balance_after,
        $verifiedTrxId, $description
    ]);

    if ($referred_by && ($is_first_deposit || $within_commission_window)) {
        $commission_rate = $is_first_deposit ? 10 : 5;
        $commission_amount = round($deposit_amount * ($commission_rate / 100), 2);

        $stmt = $conn->prepare("SELECT balance, withdrawable_balance FROM wallet_users WHERE id = ? FOR UPDATE");
        $stmt->execute([$referred_by]);
        $referrerWallet = $stmt->fetch();

        if ($referrerWallet) {
            $refBalanceBefore = (float)$referrerWallet['balance'];
            $refWithdrawableBefore = (float)$referrerWallet['withdrawable_balance'];
            $refWithdrawableAfter = $refWithdrawableBefore + $commission_amount;
            $refBalanceAfter = $refBalanceBefore + $commission_amount;

            $stmt = $conn->prepare("UPDATE wallet_users SET balance = ?, withdrawable_balance = ? WHERE id = ?");
            $stmt->execute([$refBalanceAfter, $refWithdrawableAfter, $referred_by]);

            $refDescription = $is_first_deposit
                ? 'Referral commission (10% — first deposit)'
                : 'Referral commission (5% — repeat deposit)';
            $stmt = $conn->prepare(
                "INSERT INTO wallet_transactions
                    (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at)
                 VALUES (?, 'referral_commission', ?, ?, ?, ?, ?, 'completed', NOW())"
            );
            $stmt->execute([$referred_by, $commission_amount, $refBalanceBefore, $refBalanceAfter, $verifiedTrxId, $refDescription]);

            $stmt = $conn->prepare(
                "INSERT INTO referral_commissions
                    (referrer_id, referred_id, deposit_request_id, deposit_amount,
                     commission_rate, commission_amount, is_first_deposit, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $isFirstDepositInt = $is_first_deposit ? 1 : 0;
            $stmt->execute([$referred_by, $req['user_id'], $req['id'], $deposit_amount, $commission_rate, $commission_amount, $isFirstDepositInt]);
        }
    }

    // Fill in the real method/trx_id now that ZiniPay confirmed them, and mark approved.
    $stmt = $conn->prepare(
        "UPDATE wallet_deposit_requests
         SET status = 'approved', method = ?, trx_id = ?, sender_number = 'Auto-verified (ZiniPay)', admin_note = 'Auto-approved via ZiniPay webhook', reviewed_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([ucfirst($verifiedMethod), $verifiedTrxId, $req['id']]);

    $conn->commit();
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Deposit auto-approved']);

} catch (Exception $e) {
    $conn->rollBack();
    error_log('[zinipay-webhook] Failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
    http_response_code(200); // still 200 so ZiniPay doesn't hammer retries; we log for ourselves
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
