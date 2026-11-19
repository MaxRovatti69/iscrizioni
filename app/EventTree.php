<?php
declare(strict_types=1);
namespace Iscrizioni;

use InvalidArgumentException;

final class EventTree
{
    public static function normalize(array $events): array
    {
        $byId = [];
        foreach ($events as $index => $event) $byId[$event['id']] = $index;
        // Link legacy race rows only when their Endu source has one unambiguous parent.
        foreach ($events as &$event) {
            if (array_key_exists('parentId', $event) || !Endu::selection($event) || !$event['url']) continue;
            $matches = array_values(array_filter($events, static fn($candidate) => $candidate['id'] !== $event['id'] && !Endu::selection($candidate) && empty($candidate['parentId']) && $candidate['url'] && Endu::groupUrl($candidate['url']) === Endu::groupUrl($event['url'])));
            if (count($matches) === 1) $event['parentId'] = $matches[0]['id'];
        }
        unset($event);
        foreach ($events as $event) {
            $parentId = $event['parentId'] ?? null;
            if ($parentId === null || $parentId === '') continue;
            if (!is_string($parentId) || !isset($byId[$parentId]) || $parentId === $event['id']) throw new InvalidArgumentException('Evento principale non valido.');
            $parent = $events[$byId[$parentId]];
            if (!empty($parent['parentId']) || Endu::selection($parent) || !Endu::selection($event)) throw new InvalidArgumentException('Le gare devono essere collegate direttamente a un evento principale.');
            if (!$parent['url'] || !$event['url'] || Endu::groupUrl($parent['url']) !== Endu::groupUrl($event['url'])) throw new InvalidArgumentException('La gara e l’evento principale devono usare la stessa fonte Endu.');
            $events[$byId[$parentId]]['url'] = Endu::totalUrl($parent['url']);
        }
        foreach ($events as &$event) {
            if ($event['url'] && !Endu::selection($event) && empty($event['parentId'])) $event['url'] = Endu::totalUrl($event['url']);
        }
        unset($event);
        return $events;
    }
}
