<?php
// Copiare come config.local.php (non incluso in Git, sempre fuori da public/).
return [
    'username' => 'admin',
    'password_hash' => '', // Generare con: php bin/password.php
    'data_dir' => __DIR__ . '/var',
    'cookie_secure' => true, // false solo per prova locale HTTP.
];
