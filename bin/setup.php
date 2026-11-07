<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function setupInput(string $prompt, bool $secret = false): string
{
    fwrite(STDOUT, $prompt);
    $hidden = $secret && PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec') && stream_isatty(STDIN);
    if ($hidden) shell_exec('stty -echo');
    try {
        $line = fgets(STDIN);
        if ($line === false) throw new RuntimeException('Input interrotto. Nessun file modificato.');
        return rtrim($line, "\r\n");
    } finally {
        if ($hidden) shell_exec('stty echo');
        if ($secret) fwrite(STDOUT, "\n");
    }
}

umask(0077);
$temporary = null;
try {
    $root = dirname(__DIR__);
    $file = $root . '/config.local.php';
    if (is_link($file)) throw new RuntimeException('config.local.php è un collegamento simbolico: configurarlo manualmente.');
    $existing = is_file($file) ? require $file : [];
    if (!is_array($existing)) throw new RuntimeException('config.local.php non restituisce un array. Correggerlo prima di continuare.');
    $defaultUser = is_string($existing['username'] ?? null) && trim($existing['username']) ? $existing['username'] : 'admin';
    $user = trim(setupInput("Nome utente [$defaultUser]: ")) ?: $defaultUser;
    if (strlen($user) > 150) throw new RuntimeException('Nome utente troppo lungo. Nessun file modificato.');
    $password = setupInput('Scegli una password di almeno 12 caratteri: ', true);
    if (strlen($password) < 12) throw new RuntimeException('Password troppo corta. Nessun file modificato.');
    if (!hash_equals($password, setupInput('Ripeti la password: ', true))) throw new RuntimeException('Le password non coincidono. Nessun file modificato.');
    $config = array_replace(['data_dir' => $root . '/var'], $existing, [
        'username' => $user,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'cookie_secure' => !in_array('--local', $argv, true),
    ]);
    unset($password);
    $temporary = tempnam($root, '.config-local-');
    if ($temporary === false) throw new RuntimeException('La cartella del progetto non è scrivibile.');
    $content = "<?php\n// Configurazione privata generata da bin/setup.php. Non pubblicare.\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($temporary, $content) !== strlen($content)) throw new RuntimeException('Scrittura della configurazione non riuscita.');
    chmod($temporary, 0600);
    if (!rename($temporary, $file)) throw new RuntimeException('Impossibile aggiornare config.local.php.');
    $temporary = null;
    echo "Configurazione salvata in config.local.php. Password memorizzata solo come hash.\n";
    echo in_array('--local', $argv, true) ? "Modalità prova locale HTTP. Avvia il server PHP e accedi con la password scelta.\n" : "Modalità hosting HTTPS.\n";
    foreach (['DASHBOARD_USER', 'DASHBOARD_PASSWORD_HASH', 'DASHBOARD_COOKIE_SECURE'] as $name) {
        if (getenv($name) !== false) echo "Attenzione: la variabile $name già impostata ha precedenza sul file locale.\n";
    }
} catch (Throwable $error) {
    if ($temporary && is_file($temporary)) unlink($temporary);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
