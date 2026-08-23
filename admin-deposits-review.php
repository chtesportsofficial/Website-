<?php
// admin-deposits-review.php
// Called by admin.html when an admin clicks Approve/Decline on a request.
// Approve: atomically credits wallet_users.balance + logs wallet_transactions.
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
        $stmt = $conn->prepare("SELECT balance FROM wallet_users WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $req['user_id']);
        $stmt->execute();
        $wallet = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$wallet) {
            throw new Exception('Wallet user not found');
        }

        $balance_before = (float)$wallet['balance'];
        $balance_after = $balance_before + (float)$req['amount'];

        $stmt = $conn->prepare("UPDATE wallet_users SET balance = ? WHERE id = ?");
        $stmt->bind_param('di', $balance_after, $req['user_id']);
        $stmt->execute();
        $stmt->close();

        $type = 'deposit';
        $description = 'Manual bKash/Nagad deposit approval';
        $tx_status = 'completed';
        $stmt = $conn->prepare(
            "INSERT INTO wallet_transactions
                (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param(
            'isdddsss',
            $req['user_id'], $type, $req['amount'], $balance_before, $balance_after,
            $req['trx_id'], $description, $tx_status
        );
        $stmt->execute();
        $stmt->close();
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
