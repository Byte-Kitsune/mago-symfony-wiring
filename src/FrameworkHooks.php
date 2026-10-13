<?php

declare(strict_types=1);
namespace ByteKitsune\MagoNative;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

/** Declaration evidence only; registration never proves dispatch or activation. */
final class FrameworkHooks
{
    private const ATTRIBUTES = [
        'Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener' => 'event-listener',
        'Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler' => 'message-handler',
        'Doctrine\\Bundle\\DoctrineBundle\\Attribute\\AsDoctrineListener' => 'doctrine-listener',
        'Doctrine\\Bundle\\DoctrineBundle\\Attribute\\AsEntityListener' => 'entity-listener',
        'Symfony\\Component\\Console\\Attribute\\AsCommand' => 'console-command',
    ];
    private const CONTRACTS = [
        'Doctrine\\DBAL\\Driver\\Middleware' => ['dbal-middleware', ['wrap']],
        'Twig\\Extension\\ExtensionInterface' => ['twig-extension', ['getFunctions', 'getFilters', 'getTests', 'getTokenParsers', 'getNodeVisitors', 'getOperators']],
        'Twig\\Extension\\AbstractExtension' => ['twig-extension', ['getFunctions', 'getFilters', 'getTests', 'getTokenParsers', 'getNodeVisitors', 'getOperators']],
        'Symfony\\Component\\Form\\FormTypeInterface' => ['form-type', ['buildForm', 'buildView', 'finishView', 'configureOptions', 'getParent', 'getBlockPrefix']],
        'Symfony\\Component\\Form\\AbstractType' => ['form-type', ['buildForm', 'buildView', 'finishView', 'configureOptions', 'getParent', 'getBlockPrefix']],
        'Symfony\\Component\\Form\\FormTypeExtensionInterface' => ['form-type-extension', ['buildForm', 'buildView', 'finishView', 'configureOptions', 'getExtendedTypes']],
        'Symfony\\Component\\ExpressionLanguage\\ExpressionFunctionProviderInterface' => ['expression-function-provider', ['getFunctions']],
        'Symfony\\Component\\Security\\Core\\User\\UserProviderInterface' => ['user-provider', ['loadUserByIdentifier', 'refreshUser', 'supportsClass']],

        'Symfony\\Component\\EventDispatcher\\EventSubscriberInterface' => ['event-subscriber', []],
        'Doctrine\\Common\\EventSubscriber' => ['doctrine-subscriber', []],
        'Symfony\\Component\\Messenger\\Handler\\MessageHandlerInterface' => ['message-handler', ['__invoke']],
        'Symfony\\Component\\Messenger\\Middleware\\MiddlewareInterface' => ['messenger-middleware', ['handle']],
        'Symfony\\Component\\Validator\\ConstraintValidatorInterface' => ['validator', ['validate']],
        'Symfony\\Component\\Validator\\ConstraintValidator' => ['validator', ['validate']],
        'Symfony\\Component\\Serializer\\Normalizer\\NormalizerInterface' => ['normalizer', ['normalize', 'supportsNormalization', 'getSupportedTypes']],
        'Symfony\\Component\\Serializer\\Normalizer\\DenormalizerInterface' => ['denormalizer', ['denormalize', 'supportsDenormalization', 'getSupportedTypes']],
        'Symfony\\Component\\Security\\Http\\Authenticator\\AuthenticatorInterface' => ['authenticator', ['supports', 'authenticate', 'createToken', 'onAuthenticationSuccess', 'onAuthenticationFailure']],
        'Symfony\\Component\\Security\\Http\\Authenticator\\AbstractAuthenticator' => ['authenticator', ['supports', 'authenticate', 'createToken', 'onAuthenticationSuccess', 'onAuthenticationFailure']],
        'Symfony\\Component\\Security\\Core\\Authorization\\Voter\\VoterInterface' => ['voter', ['vote']],
        'Symfony\\Component\\Security\\Core\\Authorization\\Voter\\Voter' => ['voter', ['vote', 'supports', 'voteOnAttribute']],
        'Symfony\\Component\\Console\\Command\\Command' => ['console-command', ['execute', 'interact', 'initialize']],
        'Symfony\\Component\\HttpKernel\\CacheWarmer\\CacheWarmerInterface' => ['cache-warmer', ['warmUp', 'isOptional']],
        'Symfony\\Component\\HttpKernel\\CacheClearer\\CacheClearerInterface' => ['cache-clearer', ['clear']],
        'Symfony\\Component\\DependencyInjection\\Compiler\\CompilerPassInterface' => ['container-compile', ['process']],
        'Symfony\\Component\\HttpKernel\\Bundle\\BundleInterface' => ['bundle-lifecycle', ['build', 'boot', 'shutdown']],
        'Symfony\\Component\\DependencyInjection\\Extension\\ExtensionInterface' => ['container-extension', ['load', 'prepend']],
        'Symfony\\Component\\Form\\DataTransformerInterface' => ['form-transformer', ['transform', 'reverseTransform']],
        'Symfony\\Component\\Form\\DataMapperInterface' => ['form-mapper', ['mapDataToForms', 'mapFormsToData']],
    ];
    private const MAGIC_HOOKS = ['__invoke', '__destruct', '__get', '__set', '__isset', '__unset', '__call', '__callStatic', '__toString', '__serialize', '__unserialize', '__sleep', '__wakeup', '__clone', '__debugInfo', '__set_state'];
    private const ORM_EVENTS = ['PrePersist', 'PostPersist', 'PreUpdate', 'PostUpdate', 'PreRemove', 'PostRemove', 'PostLoad', 'PreFlush'];

    /** @param array<string,string> $sources @return array{hooks:list<array<string,mixed>>,unknown:list<string>} */
    public static function discover(array $sources): array
    {
        if (count($sources) > 25000) throw new \InvalidArgumentException('Framework hook source count exceeds 25000.');
        $classes = []; $unknown = []; $bytes = 0; $pending = [];
        foreach ($sources as $file => $source) {
            $bytes += strlen($source);
            if ($bytes > 536870912) throw new \InvalidArgumentException('Framework hook source bytes exceed 512 MiB.');
            if (strlen($source) > 1048576) { $unknown[] = 'Oversized hook source: ' . $file; continue; }
            $pending[$file] = $source;
        }
        $needles = array_merge(array_keys(self::ATTRIBUTES), array_keys(self::CONTRACTS), array_map(static fn(string $event): string => 'Doctrine\\ORM\\Mapping\\' . $event, self::ORM_EVENTS), self::MAGIC_HOOKS);
        for ($round = 0; $round < 128; ++$round) {
            $changed = false;
            foreach ($pending as $file => $source) {
                if (!self::candidate($source, $needles)) continue;
                unset($pending[$file]); $changed = true;
            try {
                $nodes = Parser::create()->parse($source) ?? [];
                $nodes = (new NodeTraverser(new NameResolver()))->traverse($nodes);
                $finder = new NodeFinder();
                foreach ($finder->find($nodes, static fn(Node $node): bool => $node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_) as $node) {
                    if (!$node->namespacedName instanceof Node\Name) continue;
                    $name = $node->namespacedName->toString(); $key = strtolower($name);
                    if (array_key_exists($key, $classes)) { $unknown[] = 'Duplicate hook class: ' . $name; $classes[$key] = null; continue; }
                    $parents = $node instanceof Node\Stmt\Class_ ? array_merge($node->extends ? [$node->extends] : [], $node->implements) : $node->extends;
                    $resolvedParents = array_map(self::name(...), $parents);
                    $classes[$key] = ['name' => $name, 'node' => $node, 'file' => $file, 'parents' => $resolvedParents];
                    $needles = array_values(array_unique(array_merge($needles, [$name], $resolvedParents)));
                }
            } catch (\Throwable $error) { $unknown[] = 'Unresolved hook source ' . $file . ': ' . $error::class; }
            }
            if (!$changed) break;
            if ($round === 127) $unknown[] = 'Framework hook inheritance discovery depth exceeded';
        }
        return self::classHooks($classes, $unknown);
    }
    /** Native declaration metadata is already name-resolved and selected using the complete ancestry index. */
    public static function discoverIndexed(string $session): array
    {
        $classes = []; $unknown = [];
        foreach (Engine::frameworkHookClasses($session, array_merge(array_keys(self::CONTRACTS), array_keys(self::ATTRIBUTES), array_map(static fn(string $event): string => 'Doctrine\\ORM\\Mapping\\' . $event, self::ORM_EVENTS))) as $record) {
            if ($record['duplicate'] ?? false) { $unknown[] = 'Duplicate hook class: ' . $record['name']; continue; }
            $node = Parser::hydrate($record['node']);
            $parents = $node instanceof Node\Stmt\Class_ ? array_merge($node->extends ? [$node->extends] : [], $node->implements) : $node->extends;
            $classes[strtolower($record['name'])] = ['name' => $record['name'], 'node' => $node, 'file' => $record['file'], 'parents' => array_map(self::name(...), $parents)];
        }
        return self::classHooks($classes, $unknown);
    }
    private static function classHooks(array $classes, array $unknown): array
    {
        $hooks = [];
        foreach ($classes as $record) {
            if ($record === null || !$record['node'] instanceof Node\Stmt\Class_ || $record['node']->isAbstract()) continue;
            $node = $record['node'];
            $add = static function(string $method, string $kind, string $registration, ?string $event = null) use (&$hooks, &$unknown, $record): void {
                if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $method)) { $unknown[] = 'Dynamic hook method on ' . $record['name']; return; }
                $target = $record['name'] . '::' . $method;
                $key = strtolower($target) . '\0' . $kind . '\0' . ($event ?? '');
                $hooks[$key] = ['target' => $target, 'kind' => $kind, 'event' => $event, 'registration' => $registration, 'file' => $record['file'], 'activation' => 'declaration-only', 'budgetScope' => 'one-invocation'];
                if (count($hooks) > 100000) throw new \RuntimeException('Framework hook count exceeds 100000.');
            };
            foreach (self::MAGIC_HOOKS as $method) if ($node->getMethod($method) !== null) $add($method, 'php-language-hook', 'PHP magic method');
            foreach ($node->attrGroups as $group) foreach ($group->attrs as $attr) {
                $type = self::name($attr->name); $kind = self::attributeKind($type);
                if ($kind === null) continue;
                $method = in_array($kind, ['console-command', 'doctrine-listener'], true) ? null : self::literalArgument($attr, 'method', $kind === 'message-handler' ? 3 : 1);
                $event = $kind === 'message-handler' ? self::literalArgument($attr, 'handles', 2) : ($kind === 'console-command' ? null : self::literalArgument($attr, 'event', 0));
                if ($method === null && $kind !== 'doctrine-listener' && $kind !== 'console-command' && self::dynamicArgument($attr, 'method', $kind === 'message-handler' ? 3 : 1)) { $unknown[] = 'Dynamic hook method on ' . $record['name']; continue; }
                if ($method === null) {
                    if ($kind === 'console-command') $method = $node->getMethod('__invoke') ? '__invoke' : 'execute';
                    elseif ($kind === 'doctrine-listener' || $kind === 'entity-listener') $method = $event;
                    elseif ($kind === 'message-handler') $method = '__invoke';
                    elseif ($event !== null && ($default = self::listenerMethod($event)) !== null && $node->getMethod($default)) $method = $default;
                    elseif ($node->getMethod('__invoke')) $method = '__invoke';
                    else { $unknown[] = 'Unresolved default event listener method on ' . $record['name']; continue; }
                }
                if ($method === null) { $unknown[] = 'Unresolved hook method on ' . $record['name']; continue; }
                if ($event === null && in_array($kind, ['event-listener', 'message-handler'], true)) {
                    $parameter = $node->getMethod($method)?->params[0]->type ?? null;
                    if ($parameter instanceof Node\Name) $event = self::name($parameter);
                }
                $add($method, $kind, $type, $event);
            }
            foreach ($node->getMethods() as $method) foreach ($method->attrGroups as $group) foreach ($group->attrs as $attr) {
                $type = self::name($attr->name); $kind = self::attributeKind($type);
                if ($kind !== null) {
                    $event = $kind === 'message-handler' ? self::literalArgument($attr, 'handles', 2) : self::literalArgument($attr, 'event', 0);
                    if ($event === null && ($method->params[0]->type ?? null) instanceof Node\Name) $event = self::name($method->params[0]->type);
                    $add($method->name->toString(), $kind, $type, $event);
                }
                elseif (str_starts_with($type, 'Doctrine\\ORM\\Mapping\\') && in_array(substr($type, strlen('Doctrine\\ORM\\Mapping\\')), self::ORM_EVENTS, true)) {
                    $add($method->name->toString(), 'orm-lifecycle', $type, substr($type, strlen('Doctrine\\ORM\\Mapping\\')));
                }
            }
            foreach (self::CONTRACTS as $contract => [$kind, $methods]) {
                if (!self::inherits($record['name'], $contract, $classes)) continue;
                if ($kind === 'event-subscriber' || $kind === 'doctrine-subscriber') {
                    $subscriber = $node->getMethod('getSubscribedEvents');
                    $value = $subscriber !== null && count($subscriber->stmts ?? []) === 1 && $subscriber->stmts[0] instanceof Node\Stmt\Return_ ? $subscriber->stmts[0]->expr : null;
                    if (!$value instanceof Node\Expr\Array_) { $unknown[] = 'Dynamic subscriber events on ' . $record['name']; continue; }
                    foreach ($value->items as $item) {
                        if ($item === null || $item->unpack) { $unknown[] = 'Unpacked subscriber events on ' . $record['name']; continue; }
                        if ($kind === 'doctrine-subscriber' && ($eventName = self::stringValue($item->value)) !== null) { $add($eventName, $kind, $contract, $eventName); continue; }
                        $event = $item->key instanceof Node\Expr ? self::stringValue($item->key) : null;
                        if ($item->value instanceof Node\Scalar\String_) $add($item->value->value, $kind, $contract, $event);
                        elseif ($item->value instanceof Node\Expr\Array_) {
                            $first = $item->value->items[0]->value ?? null;
                            if ($first instanceof Node\Scalar\String_) $add($first->value, $kind, $contract, $event);
                            else foreach ($item->value->items as $listener) {
                                $name = $listener?->value instanceof Node\Expr\Array_ ? ($listener->value->items[0]->value ?? null) : null;
                                if ($name instanceof Node\Scalar\String_) $add($name->value, $kind, $contract, $event);
                                else $unknown[] = 'Dynamic subscriber callback on ' . $record['name'];
                            }
                        } else $unknown[] = 'Dynamic subscriber callback on ' . $record['name'];
                    }
                } else foreach ($methods as $method) if ($node->getMethod($method) !== null) $add($method, $kind, $contract);
            }
        }
        ksort($hooks, SORT_STRING); sort($unknown, SORT_STRING);
        return ['hooks' => array_values($hooks), 'unknown' => array_values(array_unique($unknown))];
    }
    public static function listenerMethod(string $event): ?string
    {
        $method = 'on' . preg_replace_callback('/(?<=\b|_)[a-z]|[^a-z0-9]/i', static fn(array $m): string => strtoupper($m[0]), $event);
        $method = preg_replace('/[^a-z0-9]/i', '', $method);
        return strlen($method) > 2 ? $method : null;
    }
    private static function attributeKind(string $type): ?string
    {
        foreach (self::ATTRIBUTES as $name => $kind) if (strcasecmp($type, $name) === 0) return $kind;
        return null;
    }
    private static function candidate(string $source, array $needles): bool
    {
        foreach ($needles as $needle) if (stripos($source, substr($needle, strrpos($needle, '\\') + 1)) !== false) return true;
        return false;
    }
    private static function name(Node\Name $name): string { return ($name->getAttribute('resolvedName') ?? $name)->toString(); }
    private static function dynamicArgument(Node\Attribute $attr, string $name, int $position): bool
    {
        foreach ($attr->args as $index => $arg) {
            if (($arg->name?->toString() ?? null) !== $name && !($arg->name === null && $index === $position)) continue;
            return !($arg->value instanceof Node\Expr\ConstFetch && strtolower($arg->value->name->toString()) === 'null');
        }
        return false;
    }
    private static function literalArgument(Node\Attribute $attr, string $name, int $position): ?string
    {
        foreach ($attr->args as $index => $arg) {
            if (($arg->name?->toString() ?? null) !== $name && !($arg->name === null && $index === $position)) continue;
            return self::stringValue($arg->value);
        }
        return null;
    }
    private static function stringValue(Node\Expr $value): ?string
    {
        if ($value instanceof Node\Scalar\String_) return $value->value;
        if ($value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier) {
            $owner = self::name($value->class); $name = $value->name->toString();
            if (strcasecmp($name, 'class') === 0) return $owner;
            if (strcasecmp($owner, 'Symfony\\Component\\HttpKernel\\KernelEvents') === 0 && in_array($name, ['REQUEST', 'EXCEPTION', 'VIEW', 'CONTROLLER', 'CONTROLLER_ARGUMENTS', 'RESPONSE', 'TERMINATE', 'FINISH_REQUEST'], true)) return 'kernel.' . strtolower($name);
            if (strcasecmp($owner, 'Symfony\\Component\\Console\\ConsoleEvents') === 0 && in_array($name, ['COMMAND', 'ERROR', 'TERMINATE'], true)) return 'console.' . strtolower($name);
            if (strcasecmp($owner, 'Symfony\\Component\\Form\\FormEvents') === 0 && in_array($name, ['PRE_SET_DATA', 'POST_SET_DATA', 'PRE_SUBMIT', 'SUBMIT', 'POST_SUBMIT'], true)) return 'form.' . strtolower($name);
            if (in_array(strtolower($owner), ['doctrine\\orm\\events', 'doctrine\\dbal\\events'], true) && in_array($name, ['preRemove', 'postRemove', 'prePersist', 'postPersist', 'preUpdate', 'postUpdate', 'postLoad', 'loadClassMetadata', 'preFlush', 'onFlush', 'postFlush', 'onClear', 'postConnect'], true)) return $name;
        }
        return null;
    }
    private static function inherits(string $owner, string $target, array $classes): bool
    {
        $queue = [$owner]; $seen = [];
        for ($depth = 0; $queue !== [] && $depth < 128; ++$depth) {
            $name = array_pop($queue); $key = strtolower($name);
            if (strcasecmp($name, $target) === 0) return true;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            foreach (($classes[$key]['parents'] ?? []) as $parent) $queue[] = $parent;
        }
        return false;
    }
}
