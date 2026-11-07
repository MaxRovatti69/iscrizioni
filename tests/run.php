<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Iscrizioni\Endu;
use Iscrizioni\History;
use Iscrizioni\Store;

$passed = 0;
function check(bool $condition, string $name): void { global $passed; if (!$condition) throw new RuntimeException("FAIL: $name"); $passed++; echo "PASS: $name\n"; }
function rejects(callable $fn, string $name): void { try { $fn(); } catch (InvalidArgumentException $e) { check(true, $name); return; } throw new RuntimeException("FAIL: $name"); }
function at(string $value): int { return (new DateTimeImmutable($value, new DateTimeZone('Europe/Rome')))->getTimestamp(); }
function cleanup(string $path): void { foreach (glob($path . '/*') ?: [] as $file) { if (is_dir($file)) cleanup($file); else unlink($file); } rmdir($path); }
$temporary = sys_get_temp_dir() . '/iscrizioni-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
register_shutdown_function(static fn() => cleanup($temporary));

check(Endu::count(['data' => [['count' => '3410']]]) === 3410, 'Totale Endu stringa');
check(Endu::count(['data' => [['count' => '0']]]) === 0, 'Zero Endu distinto da dato mancante');
foreach (['', '-1', '2.5', 'abc', null] as $value) rejects(static fn() => History::count($value), 'Rifiuta conteggio ' . json_encode($value));
rejects(static fn() => Endu::validate('https://example.com/entrants.json'), 'Rifiuta host esterno');
rejects(static fn() => Endu::validate(Endu::PISA . '?redirect=x'), 'Rifiuta query arbitrarie');
rejects(static fn() => Endu::validate(str_replace('entrants.json', 'counters.json', Endu::PISA)), 'Legge soltanto entrants.json');
check(History::previousYear('2024-02-29') === '2023-02-28', 'Confronto bisestile');
rejects(static fn() => History::date('2025-02-29'), 'Rifiuta data impossibile');
rejects(static fn() => History::parse("data;iscrizioni\n2025-11-02;1\n2025-11-02;2", 'daily'), 'Rifiuta duplicati');
rejects(static fn() => History::parse("data;iscrizioni\n2025-11-02;2\n2025-11-03;1", 'cumulative'), 'Rifiuta cumulato decrescente');
$csv = file_get_contents(dirname(__DIR__) . '/data/pisa-2025-settimane.csv');
$comparison = History::compare(['mode' => 'weekly', 'text' => $csv], 3410, '2026-11-04');
check($comparison['past'] === 1418 && $comparison['weekDate'] === '2025-11-02', 'Somma fino alla settimana Pisa più vicina');
check($comparison['delta'] === 1992 && abs($comparison['percent'] - 140.47954866008462) < .00001, 'Differenza e percentuale Pisa');
$future = History::compare(['mode' => 'weekly', 'text' => "data;iscrizioni\n2025-11-02;10\n2025-11-09;20"], 40, '2026-11-07');
check($future['past'] === 30 && $future['weekDate'] === '2025-11-09', 'Può scegliere la settimana successiva più vicina');
check(History::compare(['mode' => 'cumulative', 'text' => "data;iscrizioni\n2025-11-04;0"], 10, '2026-11-04')['percent'] === null, 'Base zero: nessuna percentuale inventata');
rejects(static fn() => History::compare(['mode' => 'daily', 'text' => "data;iscrizioni\n2025-11-02;1\n2025-11-04;2"], 10, '2026-11-04'), 'Rifiuta lacune giornaliere');

$store = new Store($temporary . '/database');
$config = $store->configuration();
$pisa = $config['events'][0];
$new = ['id' => 'future', 'name' => 'Golfo 2027', 'url' => '', 'history' => ['mode' => 'weekly', 'text' => $csv]];
$store->save([$pisa, $new], $config['revision']);
check(count((new Store($temporary . '/database'))->configuration()['events']) === 2, 'Eventi e storici persistono al riavvio');
try { $store->save([$pisa], $config['revision']); throw new RuntimeException('Conflitto non rilevato'); } catch (RuntimeException $error) { check($error->getCode() === 409, 'Protegge modifiche concorrenti'); }
$calls = 0;
$fetch = static function ($url) use (&$calls): int { $calls++; return 100; };
$store->collect(false, at('2026-11-04 12:00:00'), $fetch);
check($calls === 1, 'Evento senza JSON: nessuna richiesta');
$store->collect(false, at('2026-11-04 17:59:00'), $fetch);
check($calls === 1, 'Rispetta intervallo di 6 ore');
$store->collect(false, at('2026-11-04 18:00:00'), $fetch);
check($calls === 2, 'Aggiorna dopo sei ore');
check($store->yesterday($pisa, '2026-11-04')['count'] === null, 'Nessuno zero inventato se ieri non è disponibile');
$store->record($pisa, 1000, at('2026-11-05 00:00:12'));
$store->record($pisa, 1030, at('2026-11-06 00:00:20'));
$daily = $store->yesterday($pisa, '2026-11-06');
check($daily['count'] === 30 && $daily['kind'] === 'estimated' && $daily['date'] === '2026-11-05', 'Ieri stimato da due mezzanotti consecutive');
$store->record($pisa, 1100, at('2026-11-06 12:00:00'));
check($store->yesterday($pisa, '2026-11-06')['count'] === 30, 'Non confonde i dati di oggi con quelli di ieri');
$store->importDaily('pisa', "data;iscrizioni\n2026-11-05;34");
check($store->yesterday($pisa, '2026-11-06')['count'] === 34 && $store->yesterday($pisa, '2026-11-06')['kind'] === 'imported', 'Conteggio importato sostituisce la stima');
check($store->yesterday($new, '2026-11-06')['count'] === null, 'Conteggi giornalieri isolati per evento');
$store->importDaily('future', "data;iscrizioni\n2025-12-31;7\n2026-03-29;8");
check($store->yesterday($new, '2026-01-01')['count'] === 7, 'Ieri attraversa correttamente il cambio anno');
check($store->yesterday($new, '2026-03-30')['count'] === 8, 'Ieri rispetta il cambio ora legale italiano');
$store->importDaily('pisa', "data;iscrizioni\n2026-11-05;0");
check($store->yesterday($pisa, '2026-11-06')['count'] === 0, 'Zero giornaliero importato valido');
$changed = $pisa; $changed['url'] = str_replace('00102708', '00102709', Endu::PISA);
check($store->yesterday($changed, '2026-11-05')['count'] === null, 'Cambio JSON non riutilizza le vecchie rilevazioni');
$store->record($pisa, 1090, at('2026-11-07 00:00:00'));
check($store->yesterday($pisa, '2026-11-07')['kind'] === 'estimated', 'Stima del giorno prima riferita alla data visualizzata');
$store->record($changed, 100, at('2026-11-01 00:00:00'));
$store->record($changed, 90, at('2026-11-02 00:00:00'));
check($store->yesterday($changed, '2026-11-02')['count'] === null, 'Saldo negativo non presentato come iscrizioni ricevute');
$store->record($changed, 120, at('2026-11-03 00:06:00'));
check($store->yesterday($changed, '2026-11-03')['count'] === null, 'Rilevazioni tardive non qualificate come mezzanotte');
$before = $calls;
$store->collect(false, at('2026-11-08 00:00:00'), $fetch);
$store->collect(false, at('2026-11-08 00:04:00'), $fetch);
check($calls === $before + 1, 'Cron a mezzanotte raccoglie una sola volta');
$store->collect(false, at('2026-11-08 06:00:00'), static fn() => throw new RuntimeException('upstream error'));
check($store->db->query('SELECT count(*) FROM statuses')->fetchColumn() == 1, 'Errori di rete registrati senza cancellare dati');
$nowState = $store->state('2026-11-04');
check($nowState['stats']['pisa']['count'] === 100, 'Selezione data: totale rilevato in quel giorno');
for ($i = 0; $i < 10; $i++) check($store->loginAllowed('ip', 1000), 'Login entro il limite ' . ($i + 1));
check(!$store->loginAllowed('ip', 1001), 'Limita tentativi login');
check($store->loginAllowed('ip', 2000), 'Ripristina login dopo 15 minuti');
echo "\n$passed verifiche superate.\n";
