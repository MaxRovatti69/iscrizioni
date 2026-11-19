<?php
declare(strict_types=1);
namespace Iscrizioni;

use InvalidArgumentException;
use RuntimeException;

final class Endu
{
    public const PISA = 'https://egs-eventi.s3.eu-west-1.amazonaws.com/00000000/00100000/00102700/00102708/data/entrants.json';
    public const PISA_GROUPS = 'https://egs-eventi.s3.eu-west-1.amazonaws.com/00000000/00100000/00102700/00102708/data/entrants-by-fee-group.json';

    public static function validate(string $url): string
    {
        if (!preg_match('~^https://egs-eventi\.s3\.eu-west-1\.amazonaws\.com/(?:[0-9]{8}/){4}data/entrants(?:-by-fee-group)?\.json$~D', $url)) {
            throw new InvalidArgumentException('Usare un link HTTPS Endu entrants.json o entrants-by-fee-group.json valido.');
        }
        return $url;
    }

    public static function selection(array $event): array
    {
        $ids = $event['groupIds'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500) throw new InvalidArgumentException('Selezione gruppi non valida.');
        $normalized = [];
        foreach ($ids as $id) { $id = History::count($id); if ($id === 0) throw new InvalidArgumentException('ID gruppo non valido.'); $normalized[] = $id; }
        $normalized = array_values(array_unique($normalized)); sort($normalized, SORT_NUMERIC);
        if ($normalized && !str_ends_with($event['url'] ?? '', '/entrants-by-fee-group.json')) throw new InvalidArgumentException('Per selezionare una gara usare entrants-by-fee-group.json.');
        return $normalized;
    }

    public static function groupUrl(string $url): string
    {
        self::validate($url);
        return preg_replace('~/entrants(?:-by-fee-group)?\.json$~', '/entrants-by-fee-group.json', $url);
    }

    public static function totalUrl(string $url): string
    {
        self::validate($url);
        return preg_replace('~/entrants(?:-by-fee-group)?\.json$~', '/entrants.json', $url);
    }

    public static function source(array $event): string
    {
        $ids = self::selection($event);
        return hash('sha256', $event['url'] . ($ids ? '|groups:' . json_encode($ids, JSON_THROW_ON_ERROR) : ''));
    }

    public static function groups(array $payload): array
    {
        if (!isset($payload['data']) || !is_array($payload['data']) || !array_is_list($payload['data'])) throw new RuntimeException('Formato dei gruppi Endu non valido.');
        $groups = []; $seen = [];
        foreach ($payload['data'] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || !trim($row['name'])) throw new RuntimeException('Nome del gruppo non valido.');
            $id = History::count($row['id'] ?? null);
            if (!$id || isset($seen[$id])) throw new RuntimeException('ID gruppo mancante o duplicato.');
            $seen[$id] = true;
            $groups[] = ['id' => $id, 'name' => trim($row['name']), 'count' => History::count($row['count'] ?? null)];
        }
        return $groups;
    }

    public static function count(array $payload, array $ids = []): int
    {
        $rows = $payload['data'] ?? null;
        if (is_array($rows) && array_is_list($rows) && count($rows) === 1 && is_array($rows[0]) && !array_key_exists('id', $rows[0]) && !array_key_exists('name', $rows[0])) {
            if ($ids) throw new RuntimeException('Il JSON totale non contiene i gruppi della gara.');
            return History::count($rows[0]['count'] ?? null);
        }
        if (!is_array($rows)) {
            throw new RuntimeException('Formato del totale Endu non valido.');
        }
        $groups = self::groups($payload);
        $found = []; $total = 0;
        foreach ($groups as $group) {
            if (!$ids || in_array($group['id'], $ids, true)) { $total += $group['count']; $found[] = $group['id']; }
        }
        if (array_diff($ids, $found)) throw new RuntimeException('Uno o più gruppi selezionati non sono presenti nel JSON.');
        if (!is_int($total) || $total > 9007199254740991) throw new RuntimeException('Totale dei gruppi troppo grande.');
        return $total;
    }

    public static function fetch(string $url, array $ids = []): int
    {
        return self::count(self::payload($url), $ids);
    }

    public static function payload(string $url): array
    {
        self::validate($url);
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $part) use (&$body): int {
                if (strlen($body) + strlen($part) > 2_000_000) return 0;
                $body .= $part; return strlen($part);
            }]);
        // Reuse the platform's normal HTTPS proxy; hosting without a proxy uses cURL defaults.
        $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy');
        if ($proxy) curl_setopt($curl, CURLOPT_PROXY, $proxy);
        $ca = getenv('CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE');
        if ($ca) curl_setopt($curl, CURLOPT_CAINFO, $ca);
        $ok = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false || $code !== 200) throw new RuntimeException("Lettura Endu non riuscita (HTTP $code). Riprova più tardi.");
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Risposta JSON Endu non valida.');
        return $payload;
    }
}
