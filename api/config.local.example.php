<?php
/**
 * Copy this file to api/config.local.php and fill in your real values.
 * config.local.php is git-ignored so credentials never reach the repository.
 */

return [
    'db_host' => 'sql100.infinityfree.com',
    'db_user' => 'your_db_user',
    'db_pass' => 'your_db_password',
    'db_name' => 'your_db_name',

    // 'development' or 'production'
    'app_env' => 'production',

    // Long random strings (e.g. 32+ characters)
    'install_key'    => '',
    'webhook_secret' => '',

    // Optional: absolute site URL used in emails, e.g. 'https://lipalink.example.com'
    'app_url' => '',
];
