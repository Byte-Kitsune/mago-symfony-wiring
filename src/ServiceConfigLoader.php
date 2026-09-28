<?php

declare(strict_types=1);

namespace ByteKitsune\MagoSymfonyWiring;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Yaml\Yaml;

/** Reads only explicit shared/dev service files; PHP configuration is parsed, never run. */
final class ServiceConfigLoader
{
    private ?ServiceMap $cached = null;

    /** @param list<string> $files Ordered checkout-relative shared/dev service files. */
    public function __construct(private readonly string $root, private readonly array $files) {}

    public function load(): ServiceMap
    {
        if ($this->cached !== null) return $this->cached;
        $root = realpath($this->root);
        if ($root === false) {
            throw new \InvalidArgumentException('Service configuration root does not exist.');
        }
        $services = $aliases = $hashes = $resources = [];
        $incomplete = [];
        if (count($this->files) > 64) {
            throw new \InvalidArgumentException('At most 64 service files are supported.');
        }
        foreach ($this->files as $file) {
            if (!self::allowedPath($file)) {
                throw new \InvalidArgumentException('Unsafe or non-dev service path: ' . $file);
            }
            $path = $root . '/' . $file;
            if (is_link($path) || !is_file($path) || realpath($path) !== $path) {
                $incomplete[] = $file . ': missing or linked file';
                continue;
            }
            $size = filesize($path);
            if ($size === false || $size > 1_048_576) {
                $incomplete[] = $file . ': file exceeds one MiB';
                continue;
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                $incomplete[] = $file . ': unreadable file';
                continue;
            }
            $hashes[$file] = hash('sha256', $bytes);
            try {
                $entries = str_ends_with($file, '.php') ? $this->parsePhp($bytes) : $this->parseYaml($bytes);
            } catch (\Throwable $error) {
                $incomplete[] = $file . ': unsupported configuration (' . $error::class . ')';
                continue;
            }
            $defaults = $entries['_defaults'] ?? [];
            if (!is_array($defaults) || array_diff(array_keys($defaults), ['autowire', 'autoconfigure']) !== []
                || !is_bool($defaults['autowire'] ?? true) || !is_bool($defaults['autoconfigure'] ?? true)) {
                $incomplete[] = $file . ': unsupported _defaults';
                $defaults = [];
            }
            unset($entries['_defaults']);
            foreach ($entries as $id => $entry) {
                if (!is_string($id) || !is_array($entry) && !is_string($entry)) {
                    $incomplete[] = $file . ': unsupported service entry';
                    continue;
                }
                if (is_array($entry) && array_key_exists('resource', $entry)) {
                    $resource = $this->resource($root, $file, $id, $entry, $defaults);
                    if ($resource === null) $incomplete[] = $file . ': unsupported resource ' . $id;
                    else $resources[] = $resource;
                    continue;
                }
                if (is_string($entry)) {
                    if (str_starts_with($entry, '@') && !str_starts_with($entry, '@@')) {
                        $aliases[$id] = substr($entry, 1);
                    } else {
                        $incomplete[] = $file . ': unsupported alias ' . $id;
                    }
                    unset($services[$id]);
                    continue;
                }
                if (isset($entry['alias']) && is_string($entry['alias'])) {
                    $aliases[$id] = ltrim($entry['alias'], '@');
                    unset($services[$id]);
                    continue;
                }
                if (array_diff(array_keys($entry), ['class', 'arguments', 'autowire']) !== []) {
                    $incomplete[] = $file . ': unsupported fields for ' . $id;
                    continue;
                }
                $class = $entry['class'] ?? null;
                $arguments = $entry['arguments'] ?? [];
                if ($class !== null && !is_string($class) || !is_array($arguments) || !is_bool($entry['autowire'] ?? true)) {
                    $incomplete[] = $file . ': unsupported definition for ' . $id;
                    continue;
                }
                $services[$id] = ['class' => $class, 'arguments' => $arguments, 'autowire' => $entry['autowire'] ?? $defaults['autowire'] ?? true, 'origin' => $file];
                unset($aliases[$id]);
            }
        }
        $this->materializeResourceAliases($root, $resources, $services, $aliases, $hashes, $incomplete);
        ksort($services);
        ksort($aliases);
        ksort($hashes);
        return $this->cached = new ServiceMap($services, $aliases, $incomplete, $hashes);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $defaults
     * @return array{prefix: string, directory: string, excludes: list<string>, origin: string}|null
     */
    private function resource(string $root, string $file, string $prefix, array $entry, array $defaults): ?array
    {
        if (!str_ends_with($prefix, '\\') || array_diff(array_keys($entry), ['resource', 'exclude', 'autowire', 'autoconfigure']) !== []) return null;
        if (($entry['autowire'] ?? $defaults['autowire'] ?? true) !== true || !is_bool($entry['autoconfigure'] ?? true)) return null;
        $path = $entry['resource'];
        if (!is_string($path) || $path === '' || str_starts_with($path, '/') || preg_match('/[\x00*?\[\]{}]/', $path)) return null;
        $directory = realpath($root . '/' . dirname($file) . '/' . $path);
        if ($directory === false || !is_dir($directory) || !str_starts_with($directory . '/', $root . '/')) return null;
        $excludeEntries = $entry['exclude'] ?? [];
        if (is_string($excludeEntries)) $excludeEntries = [$excludeEntries];
        if (!is_array($excludeEntries) || !array_is_list($excludeEntries)) return null;
        $excludes = [];
        foreach ($excludeEntries as $exclude) {
            if (!is_string($exclude) || $exclude === '' || str_starts_with($exclude, '/') || preg_match('/[\x00*?\[\]]/', $exclude)) return null;
            $expanded = $this->expandBraces($exclude);
            if ($expanded === null) return null;
            foreach ($expanded as $part) {
                $path = $this->normalizePath($root . '/' . dirname($file) . '/' . $part);
                if (!str_starts_with($path, $root . '/')) return null;
                $real = realpath($path);
                if ($real !== false && $real !== $path) return null;
                $excludes[] = $path;
                if (count($excludes) > 128) return null;
            }
        }
        return ['prefix' => $prefix, 'directory' => $directory, 'excludes' => $excludes, 'origin' => $file];
    }

    /** @return list<string>|null */
    private function expandBraces(string $path): ?array
    {
        if (!str_contains($path, '{') && !str_contains($path, '}')) return [$path];
        if (!preg_match('/^(.*?)\{([^{}]+)\}(.*)$/D', $path, $match)) return null;
        $result = [];
        foreach (explode(',', $match[2]) as $option) {
            if ($option === '') return null;
            $expanded = $this->expandBraces($match[1] . $option . $match[3]);
            if ($expanded === null) return null;
            array_push($result, ...$expanded);
            if (count($result) > 128) return null;
        }
        return $result;
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') array_pop($parts);
            else $parts[] = $part;
        }
        return '/' . implode('/', $parts);
    }

    /**
     * @param list<array{prefix: string, directory: string, excludes: list<string>, origin: string}> $resources
     * @param array<string, array{class: ?string, arguments: array<int|string, mixed>, autowire: bool, origin: string}> $services
     * @param array<string, string> $aliases
     * @param array<string, string> $hashes
     * @param list<string> $incomplete
     */
    private function materializeResourceAliases(string $root, array $resources, array &$services, array $aliases, array &$hashes, array &$incomplete): void
    {
        if (count($aliases) > 4096) {
            $incomplete[] = 'resource alias count exceeds 4096';
            return;
        }
        foreach ($aliases as $alias => $target) {
            $seen = [$alias => true];
            while (isset($aliases[$target]) && !isset($seen[$target])) {
                $seen[$target] = true;
                $target = $aliases[$target];
            }
            if (isset($services[$target]) || isset($seen[$target])) continue;
            $matches = [];
            foreach ($resources as $resource) if (str_starts_with($target, $resource['prefix'])) $matches[] = $resource;
            if ($matches === []) continue;
            if (count($matches) !== 1) {
                $incomplete[] = 'ambiguous resource for ' . $target;
                continue;
            }
            $resource = $matches[0];
            $relative = substr($target, strlen($resource['prefix']));
            if ($relative === '' || !preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/D', $relative)) {
                $incomplete[] = 'invalid resource class ' . $target;
                continue;
            }
            $path = $resource['directory'] . '/' . str_replace('\\', '/', $relative) . '.php';
            $real = realpath($path);
            $excluded = false;
            foreach ($resource['excludes'] as $exclude) {
                if ($path === $exclude || str_starts_with($path, rtrim($exclude, '/') . '/')) $excluded = true;
            }
            $size = is_file($path) ? filesize($path) : false;
            if ($excluded || $real !== $path || $size === false || $size > 1_048_576) {
                $incomplete[] = 'unverified resource class ' . $target;
                continue;
            }
            $bytes = file_get_contents($path);
            if ($bytes === false || !$this->declaresClass($bytes, $target)) {
                $incomplete[] = 'unverified resource class ' . $target;
                continue;
            }
            $hashes[substr($path, strlen($root) + 1)] = hash('sha256', $bytes);
            $services[$target] = ['class' => $target, 'arguments' => [], 'autowire' => true, 'origin' => $resource['origin']];
        }
    }

    private function declaresClass(string $bytes, string $className): bool
    {
        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($bytes);
            if ($statements === null) return false;
            $statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
            foreach ((new NodeFinder())->findInstanceOf($statements, Node\Stmt\Class_::class) as $class) {
                if ($class->name === null || strcasecmp($class->namespacedName?->toString() ?? '', $className) !== 0) continue;
                if ($class->isAbstract()) return false;
                foreach ($class->attrGroups as $group) foreach ($group->attrs as $attribute) {
                    $name = ltrim(($attribute->name->getAttribute('resolvedName') ?? $attribute->name)->toString(), '\\');
                    if (in_array($name, ['Symfony\\Component\\DependencyInjection\\Attribute\\Exclude', 'Symfony\\Component\\DependencyInjection\\Attribute\\When'], true)) return false;
                }
                return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    private static function allowedPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $path)) {
            return false;
        }
        if (!preg_match('/\.(yaml|php)$/', $path)) {
            return false;
        }
        return !preg_match('~(^|[./_-])(test|prod|staging)([./_-]|$)~i', $path);
    }

    /** @return array<string, mixed> */
    private function parseYaml(string $bytes): array
    {
        $document = Yaml::parse($bytes);
        if (!is_array($document)) {
            throw new \UnexpectedValueException('Expected YAML mapping.');
        }
        if (!array_key_exists('services', $document)) {
            throw new \UnexpectedValueException('Missing services node.');
        }
        $services = $document['services'];
        if (!is_array($services)) {
            throw new \UnexpectedValueException('Expected services mapping.');
        }
        if (isset($document['when@dev']['services'])) {
            if (!is_array($document['when@dev']['services'])) {
                throw new \UnexpectedValueException('Expected dev services mapping.');
            }
            $services = array_replace($services, $document['when@dev']['services']);
        }
        return $services;
    }

    /** @return array<string, mixed> */
    private function parsePhp(string $bytes): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($bytes);
        if ($statements === null) {
            throw new \UnexpectedValueException('Empty PHP configuration.');
        }
        $traverser = new NodeTraverser(new NameResolver());
        $statements = $traverser->traverse($statements);
        foreach ((new NodeFinder())->findInstanceOf($statements, Node\Stmt\Return_::class) as $statement) {
            if (!$statement instanceof Node\Stmt\Return_ || !$statement->expr instanceof Node\Expr\StaticCall) {
                continue;
            }
            $call = $statement->expr;
            if (!$call->class instanceof Node\Name || $call->class->getLast() !== 'App' || !$call->name instanceof Node\Identifier || $call->name->toString() !== 'config') {
                continue;
            }
            $root = $this->literal($call->args[0]->value ?? null);
            if (!is_array($root) || !isset($root['services']) || !is_array($root['services'])) {
                throw new \UnexpectedValueException('No literal services node.');
            }
            return $root['services'];
        }
        throw new \UnexpectedValueException('No literal App::config return.');
    }

    private function literal(?Node $node): mixed
    {
        if ($node instanceof Node\Scalar\String_) return $node->value;
        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) { 'true' => true, 'false' => false, default => throw new \UnexpectedValueException('Unsupported constant.') };
        }
        if ($node instanceof Node\Expr\ClassConstFetch && $node->name instanceof Node\Identifier && $node->name->toString() === 'class' && $node->class instanceof Node\Name) {
            return ($node->class->getAttribute('resolvedName') ?? $node->class)->toString();
        }
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->literal($node->left);
            $right = $this->literal($node->right);
            if (is_string($left) && is_string($right)) return $left . $right;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && $node->name->getLast() === 'service' && count($node->args) === 1) {
            $id = $this->literal($node->args[0]->value);
            if (is_string($id)) return '@' . $id;
        }
        if ($node instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack) throw new \UnexpectedValueException('Array spread unsupported.');
                $key = $item->key === null ? count($result) : $this->literal($item->key);
                if (!is_string($key) && !is_int($key)) throw new \UnexpectedValueException('Dynamic key unsupported.');
                $result[$key] = $this->literal($item->value);
            }
            return $result;
        }
        throw new \UnexpectedValueException('Dynamic PHP expression unsupported.');
    }
}
