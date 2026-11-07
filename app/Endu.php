<?php
declare(strict_types=1);
namespace Iscrizioni;

use InvalidArgumentException;
use RuntimeException;

final class Endu
{
    public const PISA = 'https://egs-eventi.s3.eu-west-1.amazonaws.com/00000000/00100000/00102700/00102708/data/entrants.json';

    public static function validate(string $url): string
    {
        if (!preg_match('~^https://egs-eventi\.s3\.eu-west-1\.amazonaws\.com/(?:[0-9]{8}/){4}data/entrants\.json$~D', $url)) {
            throw new InvalidArgumentException('Usare un link HTTPS Endu entrants.json valido oppure lasciare il campo vuoto.');
        }
        return $url;
    }

    public static function count(array $payload): int
    {
        if (!isset($payload['data']) || !is_array($payload['data']) || count($payload['data']) !== 1 || !isset($payload['data'][0]['count'])) {
            throw new RuntimeException('Formato del totale Endu non valido.');
        }
        return History::count($payload['data'][0]['count']);
    }

    public static function fetch(string $url): int
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
        return self::count($payload);
    }
}
