<?php
// Preferred: copy OUTSIDE public_html to ../afspraakboeken-private/config.php.
// Restricted hosting: api/private/config.php, AFTER checking that the entire
// private directory returns HTTP 403. See docs/HOSTINGER.md.
// Never add the completed file, database password or setup key to GitHub.
// Keep this guard intact, including when copying the file.
if (!defined('AFSPRAAKBOEKEN_CONFIG_LOAD') || AFSPRAAKBOEKEN_CONFIG_LOAD !== true) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit;
}
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'HOSTINGER_DATABASE_NAME',
        'user' => 'HOSTINGER_DATABASE_USER',
        'password' => 'SET_ON_HOSTINGER_ONLY',
    ],
    'app_url' => 'https://app.afspraakboeken.be',
    'contact_origins' => ['https://afspraakboeken.be', 'https://www.afspraakboeken.be'],
    // Two DIFFERENT random secrets, each at least 32 characters.
    'app_key' => '',
    'setup_key' => '',
    'secure_cookies' => true,
];
