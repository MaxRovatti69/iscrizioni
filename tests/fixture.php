<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
$directory = $argv[1] ?? sys_get_temp_dir() . '/iscrizioni-browser';
if (!is_dir($directory)) mkdir($directory, 0700, true);
if (is_file($directory . '/credentials.json') || is_file($directory . '/start.php')) throw new RuntimeException('Cartella di prova già utilizzata. Scegliere una nuova cartella.');
$password = bin2hex(random_bytes(24));
file_put_contents($directory . '/credentials.json', json_encode(['username' => 'admin', 'password' => $password], JSON_THROW_ON_ERROR));
$hash = password_hash($password, PASSWORD_DEFAULT);
$settings = ['DASHBOARD_PASSWORD_HASH' => $hash, 'DASHBOARD_DATA_DIR' => $directory . '/data', 'DASHBOARD_COOKIE_SECURE' => 'false'];
$code = "<?php\n";
foreach ($settings as $key => $value) $code .= 'putenv(' . var_export($key . '=' . $value, true) . ");\n";
file_put_contents($directory . '/start.php', $code);
echo "Fixture creata. Configurazione e credenziali di prova rimangono riservate nella cartella indicata.\n";
