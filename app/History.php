<?php
declare(strict_types=1);

namespace Iscrizioni;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class History
{
    public static function date(string $value): string
    {
        $format = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? '!Y-m-d' : '!d/m/Y';
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Europe/Rome'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) ||
            $date->format(substr($format, 1)) !== $value) {
            throw new InvalidArgumentException('Data non valida: usare AAAA-MM-GG o GG/MM/AAAA.');
        }
        return $date->format('Y-m-d');
    }

    public static function count(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d+$/D', (string)$value) ||
            strlen((string)$value) > 15 || (float)$value > 9007199254740991) {
            throw new InvalidArgumentException('Numero di iscrizioni non valido.');
        }
        return (int)$value;
    }

    /** @return list<array{date:string,count:int}> */
    public static function parse(string $text, string $mode): array
    {
        if (!in_array($mode, ['daily', 'weekly', 'cumulative'], true) || strlen($text) > 2_000_000) {
            throw new InvalidArgumentException('Formato o dimensione dello storico non validi.');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $delimiter = str_contains($lines[0] ?? '', ';') ? ';' : ',';
        $header = array_map(static fn($s) => strtolower(trim($s)), str_getcsv(array_shift($lines) ?? '', $delimiter, '"', ''));
        if ($header !== ['data', 'iscrizioni']) {
            throw new InvalidArgumentException('Intestazioni richieste: data;iscrizioni.');
        }
        $rows = [];
        foreach ($lines as $line) {
            if (!trim($line)) continue;
            $cells = str_getcsv($line, $delimiter, '"', '');
            if (count($cells) !== 2) throw new InvalidArgumentException('Ogni riga deve contenere data e iscrizioni.');
            $date = self::date(trim($cells[0]));
            if (isset($rows[$date])) throw new InvalidArgumentException("Data duplicata: $date.");
            $rows[$date] = ['date' => $date, 'count' => self::count(trim($cells[1]))];
        }
        if (!$rows) throw new InvalidArgumentException('Il file non contiene dati.');
        ksort($rows);
        $rows = array_values($rows);
        for ($i = 1; $i < count($rows); $i++) {
            if ($mode === 'cumulative' && $rows[$i]['count'] < $rows[$i - 1]['count']) {
                throw new InvalidArgumentException('I cumulati devono essere non decrescenti.');
            }
        }
        return $rows;
    }

    public static function previousYear(string $date): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', self::date($date)));
        if ($month === 2 && $day === 29) $day = 28;
        return sprintf('%04d-%02d-%02d', $year - 1, $month, $day);
    }

    public static function compare(array $history, ?int $current, string $date): array
    {
        $mode = $history['mode'];
        $all = self::parse($history['text'], $mode);
        $target = self::previousYear($date);
        $candidates = array_values(array_filter($all, static fn($r) => substr($r['date'], 0, 4) === substr($target, 0, 4)));
        $cutoff = $target;
        if ($mode === 'weekly') {
            $nearest = null;
            $distance = PHP_INT_MAX;
            foreach ($candidates as $row) {
                $d = abs(self::days($target, $row['date']));
                if ($d < $distance) { $distance = $d; $nearest = $row['date']; }
            }
            if ($nearest === null || $distance > 3) throw new InvalidArgumentException('Nessuna settimana storica vicina alla data di confronto.');
            $cutoff = $nearest;
        }
        $rows = array_values(array_filter($candidates, static fn($r) => $r['date'] <= $cutoff));
        if (!$rows || $rows[count($rows) - 1]['date'] !== $cutoff) throw new InvalidArgumentException("Manca il dato storico del $target.");
        if ($mode !== 'cumulative') {
            $interval = $mode === 'weekly' ? 7 : 1;
            for ($i = 1; $i < count($rows); $i++) {
                if (self::days($rows[$i - 1]['date'], $rows[$i]['date']) !== $interval) {
                    throw new InvalidArgumentException('Date mancanti nello storico: includere anche i periodi con zero iscrizioni.');
                }
            }
        }
        $past = $mode === 'cumulative' ? $rows[count($rows) - 1]['count'] : array_sum(array_column($rows, 'count'));
        if (!is_int($past) || $past > 9007199254740991) throw new InvalidArgumentException('Totale storico troppo grande.');
        return ['target' => $target, 'weekDate' => $mode === 'weekly' ? $cutoff : null, 'past' => $past,
            'delta' => $current === null ? null : $current - $past,
            'percent' => $current === null || $past === 0 ? null : ($current - $past) / $past * 100];
    }

    private static function days(string $a, string $b): int
    {
        return (int)(new DateTimeImmutable($a, new DateTimeZone('UTC')))->diff(new DateTimeImmutable($b, new DateTimeZone('UTC')))->format('%r%a');
    }
}
