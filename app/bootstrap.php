<?php
declare(strict_types=1);
require_once __DIR__ . '/History.php';
require_once __DIR__ . '/Endu.php';
require_once __DIR__ . '/Store.php';

function dashboard_config(): array
{
    $local = is_file(dirname(__DIR__) . '/config.local.php') ? require dirname(__DIR__) . '/config.local.php' : [];
    $config = array_replace(['username' => 'admin', 'password_hash' => '', 'data_dir' => dirname(__DIR__) . '/var', 'cookie_secure' => true], $local);
    foreach (['DASHBOARD_USER' => 'username', 'DASHBOARD_PASSWORD_HASH' => 'password_hash', 'DASHBOARD_DATA_DIR' => 'data_dir'] as $env => $key) {
        if (($value = getenv($env)) !== false) $config[$key] = $value;
    }
    if (($secure = getenv('DASHBOARD_COOKIE_SECURE')) !== false) $config['cookie_secure'] = $secure !== 'false';
    if (!is_string($config['password_hash']) || (password_get_info($config['password_hash'])['algo'] ?? null) === null) throw new RuntimeException('Configurare password_hash in config.local.php. Vedere DEPLOY.md.');
    if (!extension_loaded('pdo_sqlite') || !extension_loaded('curl')) throw new RuntimeException('Abilitare le estensioni PHP pdo_sqlite e curl.');
    return $config;
}

function dashboard_json(array $value, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
