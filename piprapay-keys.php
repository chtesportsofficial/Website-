<?php
// piprapay-keys.php
// Lives alongside db.php in the chtesportsofficial/Website- repo
// (same repo chteo-api on Render is deployed from), so
// piprapay-webhook.php can require both from one place.
//
// SECURITY: no hardcoded fallback key here anymore. The old fallback key
// was committed to a public repo and must be treated as compromised —
// rotate it in the PipraPay dashboard, then set the new value as a Render
// Environment Variable named PIPRAPAY_API_KEY. Same pattern as db.php:
// this now fails loudly instead of silently using an exposed key.

$required = ['PIPRAPAY_API_KEY'];
$missing = [];
foreach ($required as $key) {
    if (getenv($key) === false || getenv($key) === '') {
        $missing[] = $key;
    }
}
if (!empty($missing)) {
    error_log('piprapay-keys.php: missing required environment variable(s): ' . implode(', ', $missing));
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'message' => 'Server misconfigured (missing PipraPay environment variables). Contact admin.'
    ]));
}

define('PIPRAPAY_API_KEY', getenv('PIPRAPAY_API_KEY'));
define('PIPRAPAY_BASE_URL', getenv('PIPRAPAY_BASE_URL') ?: 'https://chteo-wallet-piprapay-1.onrender.com/api');
