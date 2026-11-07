<?php
declare(strict_types=1);
// Use only against a disposable local server with test credentials outside the repo.
if (PHP_SAPI !== 'cli') exit(1);
$base = $argv[1] ?? 'http://127.0.0.1:5173';
if (!preg_match('~^http://127\.0\.0\.1:\d+$~D', $base)) throw new RuntimeException('Usare un server locale di prova.');
$credentials = json_decode(file_get_contents($argv[2] ?? '/tmp/iscrizioni-browser/credentials.json'), true, 16, JSON_THROW_ON_ERROR);
$jar = tempnam(sys_get_temp_dir(), 'iscrizioni-cookies-');
register_shutdown_function(static fn() => unlink($jar));
$passed = 0;
function verify(bool $ok, string $name): void { global $passed; if (!$ok) throw new RuntimeException("FAIL: $name"); $passed++; echo "PASS: $name\n"; }
function http(string $path, mixed $body = null, ?string $csrf = null, bool $form = false): array
{
    global $base, $jar;
    $curl = curl_init($base . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $headers = [];
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR));
        $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
    }
    if ($csrf) $headers[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $text = curl_exec($curl);
    if ($text === false) throw new RuntimeException(curl_error($curl));
    $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    return ['code' => $code, 'text' => $text, 'json' => json_decode($text, true)];
}
verify(http('index.php?action=state')['code'] === 401, 'Dati non accessibili senza login');
$login = http('index.php');
verify(str_contains($login['text'], 'Accesso riservato'), 'Login richiesto');
preg_match('/name="csrf" value="([^"]+)"/', $login['text'], $matches);
$token = $matches[1];
verify(http('index.php?action=login', $credentials, null, true)['code'] === 403, 'Login richiede CSRF');
verify(http('index.php?action=login', $credentials + ['csrf' => $token], null, true)['code'] === 302, 'Accesso con credenziali corrette');
$state = http('index.php?action=state')['json']; $token = $state['csrf'];
verify(isset($state['events'][0]['name']) && isset($state['stats']), 'Stato autenticato con eventi e rilevazioni');
verify(http('index.php?action=save', ['revision' => $state['revision'], 'events' => $state['events']])['code'] === 403, 'Salvataggio richiede CSRF');
$events = $state['events'];
$events = array_values(array_filter($events, static fn($e) => $e['id'] !== 'http-test'));
$events[] = ['id' => 'http-test', 'name' => 'Gara futura di prova', 'url' => '', 'history' => null];
$saved = http('index.php?action=save', ['revision' => $state['revision'], 'events' => $events], $token);
verify($saved['code'] === 200, 'Crea evento senza JSON');
verify(http('index.php?action=save', ['revision' => $state['revision'], 'events' => $events], $token)['code'] === 409, 'Rileva modifica concorrente');
$today = new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));
$yesterday = $today->modify('-1 day')->format('Y-m-d');
$daily = http('index.php?action=daily', ['eventId' => 'http-test', 'text' => "data;iscrizioni\n$yesterday;42"], $token);
verify($daily['code'] === 200, 'Importa iscrizioni giornaliere');
$latest = http('index.php?action=state')['json'];
verify($latest['stats']['http-test']['yesterday']['count'] === 42 && $latest['stats']['http-test']['count'] === null, 'Ieri importato funziona anche senza JSON');
$bad = $latest['events']; $bad[0]['url'] = 'https://example.com/entrants.json';
verify(http('index.php?action=save', ['events' => $bad, 'revision' => $latest['revision']], $token)['code'] === 400, 'Non salva URL esterni');
$refresh = http('index.php?action=refresh', ['force' => false], $token);
verify($refresh['code'] === 200 && $refresh['json']['stats']['pisa']['count'] > 0, 'JSON Endu reale letto da PHP');
verify(http('../.git/config')['code'] === 404 && http('../config.local.php')['code'] === 404, 'Document root non espone file riservati');
$export = http('index.php?action=export');
verify($export['code'] === 200 && count($export['json']['events']) === count($events), 'Backup eventi disponibile dopo accesso');
verify(http('index.php?action=logout', ['csrf' => $token], null, true)['code'] === 302, 'Logout');
verify(http('index.php?action=state')['code'] === 401, 'Logout revoca accesso');
echo "\n$passed verifiche HTTP superate.\n";
