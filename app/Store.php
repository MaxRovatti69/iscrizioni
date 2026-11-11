<?php
declare(strict_types=1);
namespace Iscrizioni;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class Store
{
    public PDO $db;
    public function __construct(public readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Archivio non scrivibile.');
        $public = realpath(dirname(__DIR__) . '/public');
        $resolved = realpath($directory);
        if ($resolved === $public || ($public && str_starts_with($resolved, $public . DIRECTORY_SEPARATOR))) throw new RuntimeException('L’archivio deve essere fuori dalla cartella pubblica.');
        $this->db = new PDO('sqlite:' . $directory . '/dashboard.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS configuration (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, events TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS samples (id INTEGER PRIMARY KEY, event_id TEXT NOT NULL, source TEXT NOT NULL, observed INTEGER NOT NULL, count INTEGER NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS sample_lookup ON samples(event_id, source, observed)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS boundaries_04 (event_id TEXT NOT NULL, source TEXT NOT NULL, day TEXT NOT NULL, count INTEGER NOT NULL, observed INTEGER NOT NULL, PRIMARY KEY(event_id, source, day))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS daily_counts (event_id TEXT NOT NULL, day TEXT NOT NULL, count INTEGER NOT NULL, PRIMARY KEY(event_id, day))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS statuses (event_id TEXT PRIMARY KEY, source TEXT NOT NULL, error TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS login_attempts (identity TEXT PRIMARY KEY, since INTEGER NOT NULL, count INTEGER NOT NULL)');
        $seed = [['id' => 'pisa', 'name' => 'Maratona di Pisa', 'url' => Endu::PISA, 'history' => null]];
        $this->db->prepare('INSERT OR IGNORE INTO configuration VALUES(1,0,?)')->execute([json_encode($seed, JSON_THROW_ON_ERROR)]);
    }

    public function configuration(): array
    {
        $row = $this->db->query('SELECT revision,events FROM configuration WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        return ['revision' => (int)$row['revision'], 'events' => json_decode($row['events'], true, 64, JSON_THROW_ON_ERROR)];
    }

    public function save(array $events, int $revision): void
    {
        if (!$events || count($events) > 100 || !array_is_list($events)) throw new InvalidArgumentException('Servono da 1 a 100 eventi.');
        $ids = [];
        foreach ($events as $event) {
            if (!is_array($event) || !is_string($event['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $event['id']) || isset($ids[$event['id']])) throw new InvalidArgumentException('ID evento non valido o duplicato.');
            $ids[$event['id']] = true;
            if (!is_string($event['name'] ?? null) || !trim($event['name']) || strlen($event['name']) > 300 || !is_string($event['url'] ?? null)) throw new InvalidArgumentException('Nome o link evento non valido.');
            if ($event['url'] !== '') Endu::validate($event['url']);
            if (isset($event['history'])) {
                if (!is_array($event['history']) || !is_string($event['history']['text'] ?? null) || !is_string($event['history']['mode'] ?? null)) throw new InvalidArgumentException('Storico non valido.');
                History::parse($event['history']['text'], $event['history']['mode']);
            }
        }
        $query = $this->db->prepare('UPDATE configuration SET events=?,revision=revision+1 WHERE id=1 AND revision=?');
        $query->execute([json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $revision]);
        if ($query->rowCount() !== 1) throw new RuntimeException('La configurazione è stata modificata da un’altra pagina. Ricarica prima di salvare.', 409);
    }

    private function one(string $sql, array $params): array|false
    {
        $query = $this->db->prepare($sql); $query->execute($params); return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function record(array $event, int $count, int $time): void
    {
        $source = hash('sha256', $event['url']);
        $local = (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('Europe/Rome'));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO samples(event_id,source,observed,count) VALUES(?,?,?,?)')->execute([$event['id'], $source, $time, $count]);
            // Keep 04:00 observations separate from legacy midnight samples.
            if ($local->format('H') === '04' && (int)$local->format('i') < 5) {
                $this->db->prepare('INSERT OR IGNORE INTO boundaries_04 VALUES(?,?,?,?,?)')->execute([$event['id'], $source, $local->format('Y-m-d'), $count, $time]);
            }
            $this->db->prepare('DELETE FROM statuses WHERE event_id=?')->execute([$event['id']]);
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    public function collect(bool $force = false, ?int $now = null, ?callable $fetch = null): array
    {
        $now ??= time(); $fetch ??= Endu::fetch(...);
        $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Rome'));
        $results = [];
        foreach ($this->configuration()['events'] as $event) {
            if (!$event['url']) continue;
            $source = hash('sha256', $event['url']);
            $lock = fopen($this->directory . '/collect-' . hash('sha256', $event['id']) . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); continue; }
            try {
                $last = $this->one('SELECT observed FROM samples WHERE event_id=? AND source=? ORDER BY observed DESC LIMIT 1', [$event['id'], $source]);
                $boundary = $local->format('H') === '04' && (int)$local->format('i') < 5 && !$this->one('SELECT day FROM boundaries_04 WHERE event_id=? AND source=? AND day=?', [$event['id'], $source, $local->format('Y-m-d')]);
                if (!$force && !$boundary && $last && $now - (int)$last['observed'] < 21600) continue;
                try {
                    $count = History::count($fetch($event['url']));
                    $this->record($event, $count, $now); $results[$event['id']] = 'ok';
                } catch (\Throwable $error) {
                    $this->db->prepare('INSERT OR REPLACE INTO statuses VALUES(?,?,?)')->execute([$event['id'], $source, 'Lettura Endu non riuscita. Riprova più tardi.']);
                    $results[$event['id']] = 'errore';
                }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        return $results;
    }

    public function importDaily(string $eventId, string $csv): int
    {
        $events = $this->configuration()['events'];
        if (!in_array($eventId, array_column($events, 'id'), true)) throw new InvalidArgumentException('Evento non trovato.');
        $rows = History::parse($csv, 'daily');
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('INSERT OR REPLACE INTO daily_counts VALUES(?,?,?)');
            foreach ($rows as $row) $query->execute([$eventId, $row['date'], $row['count']]);
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
        return count($rows);
    }

    public function yesterday(array $event, string $date): array
    {
        $day = (new DateTimeImmutable($date, new DateTimeZone('Europe/Rome')))->modify('-1 day')->format('Y-m-d');
        $manual = $this->one('SELECT count FROM daily_counts WHERE event_id=? AND day=?', [$event['id'], $day]);
        if ($manual) return ['date' => $day, 'count' => (int)$manual['count'], 'kind' => 'imported', 'note' => 'Conteggio giornaliero importato.'];
        $source = hash('sha256', $event['url']);
        $start = $this->one('SELECT count,observed FROM boundaries_04 WHERE event_id=? AND source=? AND day=?', [$event['id'], $source, $day]);
        $end = $this->one('SELECT count,observed FROM boundaries_04 WHERE event_id=? AND source=? AND day=?', [$event['id'], $source, $date]);
        if (!$start || !$end) return ['date' => $day, 'count' => null, 'kind' => 'unavailable', 'note' => 'Servono i conteggi giornalieri o due rilevazioni alle 04:00 di giorni consecutivi (ora italiana).'];
        $delta = (int)$end['count'] - (int)$start['count'];
        if ($delta < 0) return ['date' => $day, 'count' => null, 'kind' => 'unavailable', 'note' => 'Il totale è diminuito: possibili rettifiche o cancellazioni.'];
        return ['date' => $day, 'count' => $delta, 'kind' => 'estimated', 'periodStart' => gmdate('c', (int)$start['observed']), 'periodEnd' => gmdate('c', (int)$end['observed']), 'note' => 'Stima del saldo netto dalle 04:00 del giorno indicato alle 04:00 del giorno successivo (ora italiana, tolleranza 5 minuti); può includere rettifiche e cancellazioni.'];
    }

    public function state(string $date): array
    {
        $date = History::date($date);
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
        if ($date > $today) throw new InvalidArgumentException('Non è possibile visualizzare date future.');
        $start = new DateTimeImmutable($date, new DateTimeZone('Europe/Rome'));
        $end = $start->modify('+1 day');
        $config = $this->configuration(); $stats = [];
        foreach ($config['events'] as $event) {
            $source = hash('sha256', $event['url']);
            $sample = $this->one('SELECT count,observed FROM samples WHERE event_id=? AND source=? AND observed>=? AND observed<? ORDER BY observed DESC,id DESC LIMIT 1', [$event['id'], $source, $start->getTimestamp(), $end->getTimestamp()]);
            $stale = false;
            if (!$sample && $date === $today) {
                $sample = $this->one('SELECT count,observed FROM samples WHERE event_id=? AND source=? ORDER BY observed DESC,id DESC LIMIT 1', [$event['id'], $source]);
                $stale = (bool)$sample;
            }
            if ($sample && $date === $today && time() - (int)$sample['observed'] > 21600 + 300) $stale = true;
            $count = $sample && !$stale ? (int)$sample['count'] : null;
            $comparison = null; $historyError = null;
            if ($event['history'] ?? null) {
                try { $comparison = History::compare($event['history'], $count, $date); }
                catch (InvalidArgumentException $error) { $historyError = $error->getMessage(); }
            }
            $status = $date === $today ? $this->one('SELECT error FROM statuses WHERE event_id=? AND source=?', [$event['id'], $source]) : false;
            $stats[$event['id']] = ['count' => $sample ? (int)$sample['count'] : null,
                'observedAt' => $sample ? gmdate('c', (int)$sample['observed']) : null, 'stale' => $stale,
                'error' => $status ? $status['error'] : null, 'comparison' => $comparison, 'historyError' => $historyError,
                'yesterday' => $this->yesterday($event, $date)];
        }
        return $config + ['date' => $date, 'today' => $today, 'stats' => $stats];
    }

    public function loginAllowed(string $identity, int $now): bool
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE since<?')->execute([$now - 900]);
        $this->db->prepare('INSERT INTO login_attempts VALUES(?,?,1) ON CONFLICT(identity) DO UPDATE SET count=count+1')->execute([$identity, $now]);
        return (int)$this->one('SELECT count FROM login_attempts WHERE identity=?', [$identity])['count'] <= 10;
    }

    public function resetLogin(string $identity): void { $this->db->prepare('DELETE FROM login_attempts WHERE identity=?')->execute([$identity]); }
}
