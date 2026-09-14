<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required']);
    exit;
}

require_once __DIR__ . '/../db.php';

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken = isset($data['access_token']) ? trim($data['access_token']) : '';
$credits = isset($data['credits']) && is_array($data['credits']) ? $data['credits'] : [];

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}
if (!count($credits)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No credits provided']);
    exit;
}

/* Verify requester. */
$ch = curl_init(rtrim($supabaseUrl, '/') . '/auth/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);
$authResponse = curl_exec($ch);
$authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$requester = json_decode($authResponse, true);
if ($authResponse === false || $authCode !== 200 || empty($requester['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session']);
    exit;
}
$requesterId = $requester['id'];

/* Check admin/owner. */
$ch = curl_init(
    rtrim($supabaseUrl, '/') .
    '/rest/v1/profiles?id=eq.' . urlencode($requesterId) .
    '&select=is_admin,is_owner'
);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);
$profileResponse = curl_exec($ch);
$profileCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$rows = json_decode($profileResponse, true);
$profile = (is_array($rows) && count($rows) > 0) ? $rows[0] : null;
$isAdmin = $profile && (!empty($profile['is_admin']) || !empty($profile['is_owner']));

if ($profileResponse === false || $profileCode < 200 || $profileCode >= 300 || !$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

/* Credit each winner. Prize money is withdrawable (unlike deposits).
   A wallet_prize_credits row (reference = prize_<lobby_id>_<team_id>,
   UNIQUE) tracks what's already been credited for this exact team/lobby.

   - First time this reference is seen: insert the row and credit the
     full amount.
   - If this reference already exists (host edited the prize and saved
     again): only the DIFFERENCE between the new and old amount is
     applied to the wallet (e.g. 150 -> 200 credits +50, 200 -> 150
     debits -50), and the stored amount is updated to the new value.
     A downward adjustment is blocked (skipped, balance untouched) if it
     would take withdrawable_balance below zero.
   - If the amount is unchanged, nothing happens (no-op skip).

   IMPORTANT Postgres difference from MySQL: once any statement inside a
   transaction errors, Postgres aborts the WHOLE transaction — every
   further statement fails until a ROLLBACK, even unrelated ones. MySQL
   just fails that one statement. So each attempt here runs inside its
   own SAVEPOINT: on a duplicate-key race (SQLSTATE 23505, two requests
   inserting the same brand-new reference at once) we roll back only to
   that savepoint, keeping the outer transaction alive for the rest of
   the loop. */
$conn->beginTransaction();

try {
    $refStmt = $conn->prepare(
        "INSERT INTO wallet_prize_credits (reference, user_id, amount) VALUES (:reference, :ref_user_id, :ref_amount)"
    );
    $existingStmt = $conn->prepare(
        "SELECT amount FROM wallet_prize_credits WHERE reference = :reference FOR UPDATE"
    );
    $updateCreditStmt = $conn->prepare(
        "UPDATE wallet_prize_credits SET amount = :new_amount WHERE reference = :reference"
    );
    // The "withdrawable_balance + :diff3 >= 0" guard means a downward
    // adjustment that would push the balance negative simply matches zero
    // rows (rowCount() === 0 below) instead of ever creating a negative
    // balance.
    $applyDiffStmt = $conn->prepare(
        "UPDATE wallet_users
         SET withdrawable_balance = withdrawable_balance + :diff1,
             balance = balance + :diff2
         WHERE supabase_uid = :uid
           AND withdrawable_balance + :diff3 >= 0"
    );
    $lookupStmt = $conn->prepare(
        "SELECT id FROM wallet_users WHERE supabase_uid = :lookup_uid LIMIT 1"
    );
    $insertTxStmt = $conn->prepare(
        "INSERT INTO wallet_transactions
            (user_id, type, amount, description, status, reference)
         VALUES (:tx_user_id, 'prize', :tx_amount, :tx_description, 'completed', :tx_reference)"
    );

    $credited = [];
    $skipped = [];

    foreach ($credits as $c) {
        $uid = isset($c['supabase_uid']) ? trim($c['supabase_uid']) : '';
        $amount = isset($c['amount']) ? (float)$c['amount'] : 0;
        $lobbyId = isset($c['lobby_id']) ? trim((string)$c['lobby_id']) : '';
        $teamId = isset($c['team_id']) ? trim((string)$c['team_id']) : '';

        if ($uid === '' || $amount <= 0 || $lobbyId === '' || $teamId === '') {
            $skipped[] = $c;
            continue;
        }

        // Resolve the integer wallet_users.id first (needed for the
        // prize_credits row and the transactions log).
        $lookupStmt->execute(['lookup_uid' => $uid]);
        $row = $lookupStmt->fetch();
        if (!$row) {
            $skipped[] = $c; // no wallet_users row for this uid
            continue;
        }
        $walletUserId = (int)$row['id'];
        $reference = 'prize_' . $lobbyId . '_' . $teamId;

        $conn->exec('SAVEPOINT prize_credit_sp');

        try {
            $existingStmt->execute(['reference' => $reference]);
            $existingRow = $existingStmt->fetch();

            if ($existingRow === false) {
                // First time crediting this exact team/lobby.
                $refStmt->execute([
                    'reference'   => $reference,
                    'ref_user_id' => $walletUserId,
                    'ref_amount'  => $amount
                ]);
                $diff = $amount;
                $isAdjustment = false;
            } else {
                // Already credited before — only apply the difference.
                $oldAmount = (float)$existingRow['amount'];
                $diff = round($amount - $oldAmount, 2);

                if (abs($diff) < 0.005) {
                    $conn->exec('RELEASE SAVEPOINT prize_credit_sp');
                    $skipped[] = $c + ['reason' => 'already credited (amount unchanged)'];
                    continue;
                }

                $updateCreditStmt->execute(['new_amount' => $amount, 'reference' => $reference]);
                $isAdjustment = true;
            }
        } catch (PDOException $ex) {
            $conn->exec('ROLLBACK TO SAVEPOINT prize_credit_sp');
            $isDuplicateRace = (($ex->errorInfo[0] ?? null) === '23505');
            if ($isDuplicateRace) {
                $skipped[] = $c + ['reason' => 'already credited (concurrent request)'];
                continue;
            }
            throw new Exception('Could not reserve/update prize reference: ' . $ex->getMessage());
        }

        // Reference claimed/updated — now actually move the (possibly
        // negative, for a downward adjustment) diff amount.
        $applyDiffStmt->execute([
            'diff1' => $diff,
            'diff2' => $diff,
            'diff3' => $diff,
            'uid'   => $uid
        ]);

        if ($applyDiffStmt->rowCount() === 0) {
            // Would have pushed withdrawable_balance negative — refuse
            // this specific adjustment, leave everything as it was.
            $conn->exec('ROLLBACK TO SAVEPOINT prize_credit_sp');
            $skipped[] = $c + ['reason' => 'adjustment blocked — would make balance negative'];
            continue;
        }

        $insertTxStmt->execute([
            'tx_user_id'    => $walletUserId,
            'tx_amount'     => $diff,
            'tx_description' => $isAdjustment ? 'Tournament prize adjustment' : 'Tournament prize',
            'tx_reference'  => $reference
        ]);

        $conn->exec('RELEASE SAVEPOINT prize_credit_sp');
        $credited[] = ['supabase_uid' => $uid, 'amount' => $diff, 'adjustment' => $isAdjustment];
    }

    if (!count($credited) && !count($skipped)) {
        throw new Exception('No wallet accounts found for the given users');
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => count($credited) . ' winner(s) credited/adjusted.',
        'credited' => $credited,
        'skipped' => $skipped
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
