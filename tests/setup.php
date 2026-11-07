<?php
declare(strict_types=1);
$root = sys_get_temp_dir() . '/iscrizioni-setup-' . bin2hex(random_bytes(6));
mkdir($root . '/bin', 0700, true);
copy(dirname(__DIR__) . '/bin/setup.php', $root . '/bin/setup.php');
register_shutdown_function(static function () use ($root): void {
    foreach (glob($root . '/bin/*') ?: [] as $file) unlink($file);
    foreach (glob($root . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($root . '/bin'); rmdir($root);
});
$php = getenv('DASHBOARD_TEST_PHP') ?: PHP_BINARY;
function setupRun(array $args, string $input): array
{
    global $root, $php;
    $process = proc_open([$php, $root . '/bin/setup.php', ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out . $err];
}
function setupCheck(bool $ok, string $name): void { if (!$ok) throw new RuntimeException("FAIL: $name"); echo "PASS: $name\n"; }
$password = bin2hex(random_bytes(16));
[$code, $output] = setupRun(['--local'], "\n$password\n$password\n");
$config = require $root . '/config.local.php';
setupCheck($code === 0 && password_verify($password, $config['password_hash']), 'Crea automaticamente un hash valido');
setupCheck($config['username'] === 'admin' && $config['cookie_secure'] === false, 'Configura la prova locale senza cookie HTTPS');
setupCheck(!str_contains($output, $password) && !str_contains($output, $config['password_hash']), 'Non stampa password o hash');
setupCheck((fileperms($root . '/config.local.php') & 0777) === 0600, 'File privato con permessi 0600');
$before = file_get_contents($root . '/config.local.php');
[$code] = setupRun(['--local'], "\n$password\ndifferent\n");
setupCheck($code !== 0 && file_get_contents($root . '/config.local.php') === $before, 'Password diverse non sovrascrivono la configurazione');
[$code] = setupRun(['--local'], "\nshort\n");
setupCheck($code !== 0 && file_get_contents($root . '/config.local.php') === $before, 'Password corta non modifica il file');
$config['data_dir'] = $root . '/archivio-esistente';
file_put_contents($root . '/config.local.php', '<?php return ' . var_export($config, true) . ';');
[$code] = setupRun([], "utente\n$password\n$password\n");
$updated = require $root . '/config.local.php';
setupCheck($code === 0 && $updated['data_dir'] === $config['data_dir'] && $updated['username'] === 'utente' && $updated['cookie_secure'] === true, 'Preserva l’archivio e configura HTTPS senza --local');
