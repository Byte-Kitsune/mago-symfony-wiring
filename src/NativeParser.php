<?php

declare(strict_types=1);

namespace ByteKitsune\MagoNative;

/** Compatibility facade; tokenization and parsing run in the Rust engine. */
final class Parser
{
    private static array $reflections = [];
    public static int $nativeParses = 0;
    public static int $fallbackParses = 0;

    public static function create(): object
    {
        return Engine::enabled() ? new self() : (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
    }

    public function parse(string $source): array
    {
        try {
            $nodes = Engine::ast($source);
            ++self::$nativeParses;
            return self::hydrate($nodes);
        } catch (\PhpParser\Error $error) {
            if (!str_starts_with($error->getMessage(), 'Unsupported native syntax:') || getenv('MAGO_NATIVE_STRICT') === '1') throw $error;
            // Unsupported grammar remains explicit in counters. It never produces
            // a clean result from a missing syntax model.
            ++self::$fallbackParses;
            return (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        }
    }

    private static function hydrate(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (isset($value['_comment'])) {
            $class = $value['_comment'] === 'Doc' ? \PhpParser\Comment\Doc::class : \PhpParser\Comment::class;
            return new $class($value['text'], $value['startLine'], $value['startFilePos'], -1, $value['endLine'], $value['endFilePos']);
        }
        if (!isset($value['nodeType'])) return array_map(self::hydrate(...), $value);
        $type = $value['nodeType'];
        if (!is_string($type) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $type)) throw new \RuntimeException('Invalid native syntax record.');
        $class = 'PhpParser\\Node\\' . str_replace('_', '\\', $type);
        if (!class_exists($class)) $class .= '_';
        if (!is_subclass_of($class, \PhpParser\Node::class)) throw new \RuntimeException('Unknown native syntax node: ' . $type);
        $reflection = self::$reflections[$class] ??= new \ReflectionClass($class);
        $node = $reflection->newInstanceWithoutConstructor();
        $defaults = $reflection->getDefaultProperties();
        foreach ($defaults as $key => $default) if ($reflection->hasProperty($key) && $reflection->getProperty($key)->isPublic()) $node->$key = $default;
        $constructor = $reflection->getConstructor();
        if ($constructor !== null) foreach ($constructor->getParameters() as $parameter) {
            $key = $parameter->getName();
            if ($parameter->isDefaultValueAvailable() && $reflection->hasProperty($key) && $reflection->getProperty($key)->isPublic()) $node->$key = $parameter->getDefaultValue();
        }
        foreach ($value as $key => $child) {
            if ($key === 'nodeType' || $key === 'attributes') continue;
            if (!$reflection->hasProperty($key) || !$reflection->getProperty($key)->isPublic()) throw new \RuntimeException('Invalid native node field: ' . $type . '::' . $key);
            $node->$key = self::hydrate($child);
        }
        $node->setAttributes(self::hydrate($value['attributes'] ?? []));
        return $node;
    }
}
