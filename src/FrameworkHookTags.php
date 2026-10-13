<?php

declare(strict_types=1);
namespace ByteKitsune\MagoSymfonyWiring;

/** Preserve only callback registration metadata, never arbitrary tag payloads. */
final class FrameworkHookTags
{
    public const TAGS = ['kernel.event_listener', 'kernel.event_subscriber', 'messenger.message_handler', 'messenger.middleware', 'doctrine.event_listener', 'doctrine.event_subscriber', 'doctrine.orm.entity_listener', 'console.command', 'validator.constraint_validator', 'serializer.normalizer', 'kernel.cache_warmer', 'kernel.cache_clearer', 'security.voter'];
    private const KEYS = ['event', 'method', 'priority', 'dispatcher', 'handles', 'bus', 'from_transport', 'entity', 'connection', 'entity_manager'];
    public static function sanitize(mixed $tags): array
    {
        if (!is_array($tags) || count($tags) > 512) throw new \UnexpectedValueException('Invalid service tag map.');
        $result = [];
        foreach ($tags as $name => $values) {
            if (!in_array($name, self::TAGS, true)) continue;
            if (!is_array($values) || !array_is_list($values) || count($values) > 512) throw new \UnexpectedValueException('Invalid hook tag attributes.');
            foreach ($values as $value) {
                if (!is_array($value)) throw new \UnexpectedValueException('Invalid hook tag attribute.');
                $attributes = [];
                foreach ($value as $key => $item) {
                    if (!in_array($key, self::KEYS, true)) continue;
                    if (!is_string($item) && !is_int($item) && !is_bool($item) && $item !== null) throw new \UnexpectedValueException('Dynamic hook tag attribute.');
                    if (is_string($item) && (strlen($item) > 512 || preg_match('/[\x00-\x1f\x7f]/', $item))) throw new \UnexpectedValueException('Invalid hook tag string.');
                    $attributes[$key] = $item;
                }
                ksort($attributes, SORT_STRING); $result[$name][] = $attributes;
            }
        }
        ksort($result, SORT_STRING); return $result;
    }
    public static function registrations(ServiceMap $map): array
    {
        $result = []; $unknown = [];
        foreach ($map->services as $id => $service) foreach ($service['tags'] ?? [] as $tag => $attributes) foreach ($attributes as $values) {
            $class = $service['class']; $method = $values['method'] ?? null; $event = $values['event'] ?? null;
            if ($method === null) $method = match ($tag) {
                'messenger.message_handler' => '__invoke', 'messenger.middleware' => 'handle',
                'validator.constraint_validator' => 'validate', 'security.voter' => 'vote',
                'kernel.cache_warmer' => 'warmUp', 'kernel.cache_clearer' => 'clear',
                'doctrine.event_listener' => $event,
                default => null,
            };
            // A listener's implicit onEventName/__invoke fallback needs source/reflection proof.
            // Subscribers need their source-visible getSubscribedEvents map, not guessed names.
            $target = is_string($class) && is_string($method) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $method) ? $class . '::' . $method : null;
            $result[] = ['service' => $id, 'tag' => $tag, 'target' => $target, 'event' => is_string($event) ? $event : null, 'activation' => 'registration-only', 'origin' => $service['origin']];
            if ($target === null) $unknown[] = 'Hook registration requires source method resolution: ' . $id . ' (' . $tag . ')';
        }
        return ['registrations' => $result, 'unknown' => $unknown];
    }
}
