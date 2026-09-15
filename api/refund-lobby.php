<?php
// refund-lobby.php
// Called by tournament-management.html's cancelLobby() right after an admin
// cancels a lobby. Refunds every team in that lobby that actually paid its
// entry fee from wallet balance (payment_method === 'balance' on the
// Supabase lobby_teams row) — manually-added/offline teams never touched
// the wallet and are filtered out on the frontend before this is called.
//
// Request body:
//   { access_token, lobby_id, refunds: [ {team_id, supabase_uid, amount}, ... ] }
//
// IDEMPOTENCY: each refund is checked individually (by a reference string
// unique to that lobby+team) before crediting, inside its own SAVEPOINT —
// so calling this endpoint twice for the same lobby (retry after a network
// error, or clicking "Cancel Lobby" again) never double-refunds anyone.
// Teams already refunded are reported back as "skipped", not re-credited.
//
// BALANCE BUCKET NOTE: deduct-balance.php (join-lobby's debit) consumes
// non_withdrawable_balance first, then withdrawable_balance for the
// remainder — and one deduct-balance.php call can cover several entries/
// lobbies at once, so the exact withdrawable/non-withdrawable split for
// ONE team's share of that call isn't separately recorded anywhere. To
// avoid ever upgrading non-withdrawable (bonus) money into real
// withdrawable cash through a join+cancel cycle, every refund here is
// credited entirely to non_withdrawable_balance. If you need exact
// bucket-for-bucket reversal instead, deduct-balance.php and the
// lobby_teams insert would need to start persisting the split per team
// at join time — flag it and we can add that.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db.php';          // exposes $conn (PDO, Postgres)
require_once __DIR__ . '/../admin-auth.php';  // exposes verify_admin_token()

$input        = json_decode(file_get_contents('php://input'), true) ?: [];
$access_token = $input['access_token'] ?? '';
$lobby_id     = trim((string)($input['lobby_id'] ?? ''));
$refunds      = isset($input['refunds']) && is_array($input['refunds']) ? $input['refunds'] : [];

if ($lobby_id === '') {
    echo json_encode(['success' => false, 'message' => 'Missing lobby_id']);
    exit();
}

$admin_uid = verify_admin_token($access_token);
if (!$admin_uid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied — admin only.']);
    exit();
}

if (!$refunds) {
    // Nothing to refund (e.g. every team in the lobby was a manual/offline
    // entry) — not an error, just a no-op.
    echo json_encode(['success' => true, 'refunded' => [], 'skipped' => [], 'failed' => []]);
    exit();
}

$refunded = [];
$skipped  = [];
$failed   = [];

$conn->beginTransaction();

try {
    foreach ($refunds as $r) {
        $team_id      = trim((string)($r['team_id'] ?? ''));
        $supabase_uid = trim((string)($r['supabase_uid'] ?? ''));
        $amount       = isset($r['amount']) ? (float)$r['amount'] : 0;

        if ($team_id === '' || $supabase_uid === '' || $amount <= 0) {
            $failed[] = ['team_id' => $team_id, 'message' => 'Invalid refund item'];
            continue;
        }

        // Unique per lobby+team, so this exact refund can only ever be
        // logged once no matter how many times the endpoint is called.
        $reference = 'lobby_cancel:' . $lobby_id . ':team:' . $team_id;

        // Postgres aborts the WHOLE transaction on any single failed
        // statement, so each item gets its own SAVEPOINT — a failure or a
        // deliberate ROLLBACK TO for one team never blocks the rest.
        $conn->exec('SAVEPOINT refund_item');

        try {
            $stmt = $conn->prepare(
                "SELECT id FROM wallet_transactions WHERE reference = ? AND type = 'refund' LIMIT 1"
            );
            $stmt->execute([$reference]);
            if ($stmt->fetch()) {
                $skipped[] = $team_id; // already refunded — do nothing
                $conn->exec('RELEASE SAVEPOINT refund_item');
                continue;
            }

            $stmt = $conn->prepare(
                "SELECT id, balance, non_withdrawable_balance
                 FROM wallet_users WHERE supabase_uid = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$supabase_uid]);
            $wallet = $stmt->fetch();

            if (!$wallet) {
                $failed[] = ['team_id' => $team_id, 'message' => 'Wallet not found for this account'];
                $conn->exec('ROLLBACK TO SAVEPOINT refund_item');
                continue;
            }

            $balance_before          = (float)$wallet['balance'];
            $non_withdrawable_before = (float)$wallet['non_withdrawable_balance'];
            $balance_after           = $balance_before + $amount;
            $non_withdrawable_after  = $non_withdrawable_before + $amount;

            $stmt = $conn->prepare(
                "UPDATE wallet_users
                 SET balance = ?, non_withdrawable_balance = ?
                 WHERE id = ?"
            );
            $stmt->execute([$balance_after, $non_withdrawable_after, $wallet['id']]);

            $stmt = $conn->prepare(
                "INSERT INTO wallet_transactions
                    (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at)
                 VALUES (?, 'refund', ?, ?, ?, ?, ?, 'completed', NOW())"
            );
            $stmt->execute([
                $wallet['id'], $amount, $balance_before, $balance_after,
                $reference, 'Lobby cancelled — entry fee refund'
            ]);

            $refunded[] = $team_id;
            $conn->exec('RELEASE SAVEPOINT refund_item');
        } catch (Exception $inner) {
            $conn->exec('ROLLBACK TO SAVEPOINT refund_item');
            $failed[] = ['team_id' => $team_id, 'message' => $inner->getMessage()];
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'refunded' => $refunded, 'skipped' => $skipped, 'failed' => $failed]);
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
