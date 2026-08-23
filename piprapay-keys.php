<?php
// piprapay-keys.php
// Lives alongside db.php in the chtesportsofficial/Website- repo
// (same repo chteo-api on Render is deployed from), so
// piprapay-webhook.php can require both from one place.
//
// NOTE: ideally these come from Render Environment Variables too,
// same as the DB credentials — see the security note in chat.

define('PIPRAPAY_API_KEY', getenv('PIPRAPAY_API_KEY') ?: '14969413126a8aba77ee4b46984233238351753916a8aba77ee4b71430401613');
define('PIPRAPAY_BASE_URL', getenv('PIPRAPAY_BASE_URL') ?: 'https://chteo-wallet-piprapay-1.onrender.com/api');
