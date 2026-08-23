<?php
// admin-auth.php
// Shared helper: verifies a Supabase access_token belongs to a logged-in
// user AND that user is an admin/owner (profiles.is_admin or is_owner).
// Include this file, then call verify_admin_token($access_token).

require_once __DIR__ . '/supabase-config.php';

function supabase_curl($url, $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($response, true)];
}

/**
 * Returns the Supabase user's UUID if the access_token is valid AND that
 * user is an admin/owner in the `profiles` table. Returns null otherwise
 * (invalid token, expired token, or not an admin).
 */
function verify_admin_token($access_token) {
    if (!$access_token) return null;

    // Step 1: ask Supabase Auth who this token belongs to.
    list($code, $user) = supabase_curl(
        SUPABASE_URL . '/auth/v1/user',
        [
            'apikey: ' . SUPABASE_ANON_KEY,
            'Authorization: Bearer ' . $access_token
        ]
    );
    if ($code !== 200 || !isset($user['id'])) return null;
    $uid = $user['id'];

    // Step 2: is this user an admin/owner? Uses the service role key so
    // this check works reliably regardless of the profiles table's RLS
    // policies (the frontend already does its own check too, but the
    // backend must never trust the frontend alone).
    list($code2, $rows) = supabase_curl(
        SUPABASE_URL . '/rest/v1/profiles?id=eq.' . urlencode($uid) . '&select=is_admin,is_owner',
        [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY
        ]
    );
    if ($code2 !== 200 || !is_array($rows) || count($rows) === 0) return null;

    $profile = $rows[0];
    if (empty($profile['is_admin']) && empty($profile['is_owner'])) return null;

    return $uid;
}
