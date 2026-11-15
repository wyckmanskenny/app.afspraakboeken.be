<?php
// HOSTINGER: duplicate this file as config.php in this same directory.
// Edit only the values in the return array. Keep this PHP guard intact.
// Never put completed configuration, copies or backups in GitHub or text files.
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
