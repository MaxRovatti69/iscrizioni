<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
umask(0077);
try {
    $config = dashboard_config();
    $store = new \Iscrizioni\Store($config['data_dir']);
    $results = $store->collect(in_array('--force', $argv, true));
    foreach ($results as $id => $status) echo "$id: $status\n";
    exit(in_array('errore', $results, true) ? 1 : 0);
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
