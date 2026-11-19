<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Iscrizioni\Endu;
use Iscrizioni\Store;

$passed = 0;
function groupCheck(bool $ok, string $name): void { global $passed; if (!$ok) throw new RuntimeException("FAIL: $name"); $passed++; echo "PASS: $name\n"; }
function groupRejects(callable $call, string $name): void { try { $call(); } catch (InvalidArgumentException|RuntimeException $error) { groupCheck(true, $name); return; } throw new RuntimeException("FAIL: $name"); }
function groupTime(string $day): int { return (new DateTimeImmutable("$day 04:00:00", new DateTimeZone('Europe/Rome')))->getTimestamp(); }
function groupCleanup(string $dir): void { foreach (glob($dir . '/*') ?: [] as $file) { if (is_dir($file)) groupCleanup($file); else unlink($file); } rmdir($dir); }
$directory = sys_get_temp_dir() . '/iscrizioni-groups-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
register_shutdown_function(static fn() => groupCleanup($directory));
$payload = ['data' => [
    ['id'=>46997, 'name'=>'Maratona - Tesserati', 'count'=>'948'],
    ['id'=>46998, 'name'=>'Maratona - Stranieri tesserati', 'count'=>'106'],
    ['id'=>46999, 'name'=>'Maratona - Non competitiva', 'count'=>'1046'],
    ['id'=>47000, 'name'=>'Mezza Maratona - Tesserati', 'count'=>'1274'],
    ['id'=>47001, 'name'=>'Mezza Maratona - Stranieri tesserati', 'count'=>'44'],
    ['id'=>47002, 'name'=>'Mezza Maratona - Non competitiva', 'count'=>'636'],
    ['id'=>47003, 'name'=>'Christmas Run', 'count'=>'326'],
]];
groupCheck(Endu::validate(Endu::PISA_GROUPS) === Endu::PISA_GROUPS, 'Accetta il JSON per gruppi');
groupCheck(Endu::validate(Endu::PISA) === Endu::PISA, 'Rimane compatibile con il totale precedente');
groupCheck(Endu::count($payload) === 4380, 'Somma tutti i gruppi nel totale');
groupCheck(Endu::count($payload, [46997,46998,46999]) === 2100, 'Calcola la sola Maratona');
groupCheck(Endu::count($payload, [47000,47001,47002]) === 1954, 'Calcola la sola Mezza Maratona');
groupCheck(Endu::count($payload, [47003]) === 326, 'Calcola la sola Christmas Run');
groupRejects(static fn()=>Endu::count($payload,[999999]), 'Un gruppo mancante non diventa zero');
groupRejects(static fn()=>Endu::count(['data'=>[['count'=>'4380']]],[46997]), 'Il vecchio JSON totale non può filtrare gare');
groupCheck(Endu::count(['data'=>[]]) === 0, 'Gruppi vuoti: totale zero');
groupRejects(static fn()=>Endu::count(['data'=>[]],[46997]), 'Gruppi vuoti con selezione: dato non disponibile');
$duplicate = $payload; $duplicate['data'][] = $duplicate['data'][0];
groupRejects(static fn()=>Endu::groups($duplicate), 'Rifiuta ID duplicati');
$invalid = $payload; $invalid['data'][1]['count'] = '-2';
groupRejects(static fn()=>Endu::count($invalid), 'Rifiuta conteggi malformati prima di salvare parziali');
groupRejects(static fn()=>Endu::validate(str_replace('entrants-by-fee-group', 'entrants-by-fee', Endu::PISA_GROUPS)), 'Non ammette file diversi dai due JSON supportati');
$total = ['id'=>'totale', 'name'=>'Pisa · Totale', 'url'=>Endu::PISA_GROUPS, 'history'=>null];
$marathon = ['id'=>'maratona', 'name'=>'Pisa · Maratona', 'url'=>Endu::PISA_GROUPS, 'history'=>null, 'groupIds'=>[46999,46997,46998]];
$half = ['id'=>'mezza', 'name'=>'Pisa · Mezza', 'url'=>Endu::PISA_GROUPS, 'history'=>null, 'groupIds'=>[47000,47001,47002]];
groupCheck(Endu::selection($marathon) === [46997,46998,46999], 'Normalizza ordine dei gruppi');
$reordered = $marathon; $reordered['groupIds'] = ['46998','46999','46997'];
groupCheck(Endu::source($marathon) === Endu::source($reordered), 'Ordine e ID stringa non interrompono lo storico delle letture');
groupCheck(Endu::source($marathon) !== Endu::source($half) && Endu::source($marathon) !== Endu::source($total), 'Ogni gara ha una fonte di rilevazione distinta');
groupCheck(Endu::source(['url'=>Endu::PISA]) === hash('sha256',Endu::PISA), 'Le fonti esistenti non cambiano identità');
groupRejects(static fn()=>Endu::selection(['url'=>Endu::PISA,'groupIds'=>[46997]]), 'Selezione gruppi consentita solo sul JSON per gruppi');
$store = new Store($directory . '/db');
$store->save([$total,$marathon,$half],0);
$total = $store->configuration()['events'][0];
$calls = 0;
$fetch = static function ($url) use (&$calls,$payload) { $calls++; return str_ends_with($url, '/entrants.json') ? ['data'=>[['count'=>'4380']]] : $payload; };
$store->collect(false,groupTime('2026-11-01'),null,$fetch);
groupCheck($calls === 2, 'Una richiesta al JSON totale e una al JSON condiviso dalle gare');
$state=$store->state('2026-11-01');
groupCheck($state['stats']['maratona']['count'] === 2100 && $state['stats']['mezza']['count'] === 1954 && $state['stats']['totale']['count'] === 4380, 'Rilevazioni separate per gara');
$groups=(array)$state['groups'];
groupCheck(count($groups[Endu::PISA_GROUPS]['items']) === 7, 'I gruppi vengono conservati per la configurazione nella dashboard');
$next=$payload; $next['data'][0]['count']='958'; $next['data'][3]['count']='1281'; $next['data'][6]['count']='327';
$store->collect(false,groupTime('2026-11-02'),null,static fn($url)=>str_ends_with($url, '/entrants.json') ? ['data'=>[['count'=>'4398']]] : $next);
groupCheck($store->yesterday($marathon,'2026-11-02')['count'] === 10 && $store->yesterday($half,'2026-11-02')['count'] === 7 && $store->yesterday($total,'2026-11-02')['count'] === 18, 'Il saldo delle 04:00 è calcolato separatamente per ogni gara');
$other=$marathon;$other['groupIds']=[47003];
groupCheck($store->yesterday($other,'2026-11-02')['count'] === null, 'Modificare i gruppi non riutilizza le vecchie letture della stessa riga');
$historyMarathon=$marathon;$historyMarathon['history']=['mode'=>'cumulative','text'=>"data;iscrizioni\n2025-11-02;1000"];
$store->save([$total,$historyMarathon,$half],1);
$state=$store->state('2026-11-02');
groupCheck($state['stats']['maratona']['comparison']['past'] === 1000 && $state['stats']['mezza']['comparison'] === null, 'Lo storico della Maratona non è applicato alla Mezza');
$calls=0;
$store->collect(true,groupTime('2026-11-03'),null,static function()use(&$calls){$calls++;throw new RuntimeException('upstream down');});
groupCheck($calls===2 && (int)$store->db->query('SELECT count(*) FROM statuses')->fetchColumn()===3, 'Un errore delle fonti non genera richieste ripetute per le gare né cancella i dati');
$old=$store->state('2026-11-02');
groupCheck($old['stats']['maratona']['count']===2110,'I conteggi storici restano disponibili dopo un errore di rete');
echo "\n$passed verifiche gruppi superate.\n";
