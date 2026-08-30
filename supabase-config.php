<?php
// supabase-config.php
// Shared Supabase constants for backend scripts that need to verify a
// logged-in user's access_token (e.g. admin-only endpoints).

define('SUPABASE_URL', 'https://myfficbwcbgbxbdqjexv.supabase.co');
define('SUPABASE_ANON_KEY', 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5');

// The service role key is required to read the `profiles` table (is_admin /
// is_owner) from the backend without being blocked by Row Level Security.
// NEVER expose this key in any frontend file — only here.
// Get it from: Supabase dashboard -> Project Settings -> API -> service_role key.
// Set it as a Render Environment Variable named SUPABASE_SERVICE_KEY.
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: 'PASTE_YOUR_SERVICE_ROLE_KEY_HERE');
