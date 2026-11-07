<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Iscrizioni\History;
use Iscrizioni\Store;

umask(0077);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!str_ends_with($requestPath, '/') && basename($requestPath) !== 'index.php') {
    http_response_code(404); exit;
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
try {
    $config = dashboard_config();
    $store = new Store($config['data_dir']);
    $sessionDir = $config['data_dir'] . '/sessions';
    if (!is_dir($sessionDir)) mkdir($sessionDir, 0700, true);
    session_save_path($sessionDir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '43200');
    session_name('iscrizioni_session');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => (bool)$config['cookie_secure'], 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    $jsonActions = ['state', 'save', 'refresh', 'daily', 'seed', 'export'];
    if (isset($_SESSION['authenticated']) && ($_SESSION['expires'] ?? 0) < time()) {
        unset($_SESSION['authenticated']);
    }
    if ($method === 'POST') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            dashboard_json(['error' => 'Sessione non valida. Ricarica la pagina.'], 403);
        }
    }
    if (empty($_SESSION['authenticated'])) {
        if (in_array($action, $jsonActions, true)) dashboard_json(['error' => 'Accesso richiesto.'], 401);
        $loginError = '';
        if ($method === 'POST' && $action === 'login') {
            $identity = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if (!$store->loginAllowed($identity, time())) {
                http_response_code(429); $loginError = 'Troppi tentativi. Riprova tra 15 minuti.';
            } else {
                $valid = password_verify((string)($_POST['password'] ?? ''), $config['password_hash']);
                if ($valid && hash_equals($config['username'], (string)($_POST['username'] ?? ''))) {
                    $store->resetLogin($identity); session_regenerate_id(true);
                    $_SESSION = ['authenticated' => true, 'expires' => time() + 43200, 'csrf' => bin2hex(random_bytes(32))];
                    header('Location: index.php'); exit;
                }
                $loginError = 'Credenziali non valide.';
            }
        }
        require dirname(__DIR__) . '/app/login.php'; exit;
    }
    if ($action === 'logout' && $method === 'POST') {
        $_SESSION = []; session_destroy();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => (bool)$config['cookie_secure'], 'httponly' => true, 'samesite' => 'Strict']);
        header('Location: index.php'); exit;
    }
    $csrf = $_SESSION['csrf'];
    // Release the PHP session lock before network/database operations.
    session_write_close();
    $date = $_GET['date'] ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
    if ($action === 'state' && $method === 'GET') dashboard_json($store->state($date) + ['csrf' => $csrf]);
    if ($action === 'export' && $method === 'GET') {
        header('Content-Disposition: attachment; filename="iscrizioni-eventi.json"');
        dashboard_json($store->configuration());
    }
    if ($method === 'POST' && in_array($action, ['save', 'refresh', 'daily'], true)) {
        $body = file_get_contents('php://input', false, null, 0, 10_000_001);
        if (strlen($body) > 10_000_000) dashboard_json(['error' => 'Richiesta troppo grande.'], 413);
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new InvalidArgumentException('Richiesta non valida.');
        if ($action === 'save') {
            if (!is_array($payload['events'] ?? null) || !is_int($payload['revision'] ?? null)) throw new InvalidArgumentException('Configurazione non valida.');
            $store->save($payload['events'], $payload['revision']);
            dashboard_json($store->configuration());
        }
        if ($action === 'daily') {
            if (!is_string($payload['eventId'] ?? null) || !is_string($payload['text'] ?? null)) throw new InvalidArgumentException('Dati giornalieri non validi.');
            dashboard_json(['rows' => $store->importDaily($payload['eventId'], $payload['text'])]);
        }
        $store->collect((bool)($payload['force'] ?? false));
        dashboard_json($store->state($date));
    }
    if ($action === 'seed' && $method === 'GET') dashboard_json(['text' => file_get_contents(dirname(__DIR__) . '/data/pisa-2025-settimane.csv')]);
    if ($action !== '') dashboard_json(['error' => 'Operazione non disponibile.'], 404);
    require dirname(__DIR__) . '/app/shell.php';
} catch (InvalidArgumentException|JsonException $error) {
    dashboard_json(['error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    if ((int)$error->getCode() === 409) dashboard_json(['error' => $error->getMessage()], 409);
    error_log('Iscrizioni: ' . $error->getMessage());
    dashboard_json(['error' => 'Servizio non disponibile. Verificare la configurazione PHP e l’archivio sul server.'], 503);
}
