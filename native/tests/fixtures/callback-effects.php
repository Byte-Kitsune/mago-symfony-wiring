<?php
namespace CallbackEffects;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
const QUERY_CALLBACK = static function (Connection $db): void { $db->executeQuery('SELECT 1'); };
function queryFunction(Connection $db): void { $db->executeQuery('SELECT 1'); }
final class Query { public function execute(): void {} }
final class QueryBuilder { public function getQuery(): Query { return new Query(); } }
class DerivedConnection extends Connection {}
class QuietConnection extends Connection { public function executeQuery(string $sql): void {} }
class QuietDerived extends QuietConnection {}
interface EntityManagerPort extends EntityManagerInterface {}
final class QueryCaster implements \Stringable {
    public function __construct(private Connection $db) {}
    public function __toString(): string { $this->db->executeQuery('SELECT 1'); return 'value'; }
}
final class QueryTask {
    public function __construct(private Connection $db) {}
    public function __invoke(): void { $this->db->executeQuery('SELECT 1'); }
}
final class EffectsController {
    public const CALLBACK = static function (Connection $db): void { $db->executeQuery('SELECT 1'); };
    public const FIRST_CLASS = queryFunction(...);
    public function __construct(private Connection $db) {}
    private function query(): void { $this->db->executeQuery('SELECT 1'); }
    private function forward(callable $callback): void { $callback(); }
    private function defaultTarget(callable $callback = self::CALLBACK): void { $callback($this->db); }
    private function replaceCallback(callable &$callback): void { $callback = $this->query(...); }
    private function factory(): \Closure { return $this->query(...); }
    private function effectfulFactory(): \Closure { $this->query(); return $this->query(...); }
    private function connection(): Connection { return $this->db; }
    public function create(): void { $a = $this->query(...); $b = fn() => $this->query(); }
    public function variable(): void { $cb = $this->query(...); $cb(); }
    public function captured(): void { $db = $this->db; $cb = function () use ($db): void { $db->executeQuery('SELECT 1'); }; $cb(); }
    public function arrow(): void { $db = $this->db; $cb = fn() => $db->executeQuery('SELECT 1'); $cb(); }
    public function immediate(): void { (fn() => $this->query())(); }
    public function forwarding(): void { $this->forward($this->query(...)); }
    public function namedForwarding(): void { $this->forward(callback: $this->query(...)); }
    public function arrayTarget(): void { $cb = [$this, 'query']; $cb(); }
    public function stringTarget(): void { $cb = 'CallbackEffects\\queryFunction'; $cb($this->db); }
    public function invokable(QueryTask $task): void { $task(); }
    public function map(): void { \array_map($this->query(...), [1, 2, 3]); }
    public function namedMap(): void { \array_map(array: [1, 2], callback: $this->query(...)); }
    public function storedArray(): void { $items = [1, 2]; \array_walk($items, fn() => $this->query()); }
    public function emptyMap(): void { \array_map($this->query(...), []); }
    public function unknownMap(array $items): void { \array_map($this->query(...), $items); }
    public function shortCircuit(): void { \array_any([1, 2, 3], fn() => $this->query()); }
    public function callUser(): void { \call_user_func($this->query(...)); }
    public function callArray(): void { \call_user_func_array('CallbackEffects\\queryFunction', [$this->db]); }
    public function pipe(): void { $this->connection() |> queryFunction(...); }
    public function defaultCallback(): void { $this->defaultTarget(); }
    public function returnedCallback(): void { $cb = $this->factory(); $cb(); }
    public function callbackInvoke(): void { $cb = $this->query(...); $cb->__invoke(); }
    public function nullsafeInvoke(): void { $cb = $this->query(...); $cb?->__invoke(); }
    public function effectfulInvocation(): void { ($this->effectfulFactory())(); }
    public function scalarCallbacks(): void { \array_map(\strlen(...), ['a', 'bb']); \array_map(static fn($value) => \trim($value), ['a', 'bb']); }
    public function opaqueScalar(\Stringable $value): void { $cb = \strval(...); $cb($value); }
    public function referenceAlias(): void { $items = ['value']; $other =& $items; $other[] = new QueryCaster($this->db); \array_map(\strval(...), $items); }
    public function referenceLoop(): void { $items = ['value']; foreach ($items as &$value) {} $value = new QueryCaster($this->db); \array_map(\strval(...), $items); }
    public function tryBinding(): void { $cb = static fn() => 0; try { $cb = $this->query(...); } catch (\Throwable) {} $cb(); }
    public function foreachBinding(): void { $cb = static fn() => 0; foreach ([$this->query(...)] as $cb) {} $cb(); }
    public function mutatedArray(): void { $items = ['value']; $items[] = new QueryCaster($this->db); \array_map(\strval(...), $items); }
    public function referenceWalk(): void { $items = ['value']; \array_walk($items, function (&$value): void { $value = new QueryCaster($this->db); }); \array_map(\strval(...), $items); }
    public function fromCallable(): void { $cb = \Closure::fromCallable([$this, 'query']); $cb(); }
    public function fromCallableCreation(): void { $cb = \Closure::fromCallable($this->directCycle(...)); }
    public function referenceParameter(): void { $cb = static fn() => 0; $this->replaceCallback($cb); $cb(); }
    public function calleeBeforeArgument(): void { $cb = static fn($ignored) => 0; $query = $this->query(...); $cb($cb = $query); }
    public function queryCalleeBeforeArgument(): void { $cb = $this->query(...); $cb($cb = static fn() => 0); }
    public function mapCalleeBeforeArgument(): void { $cb = static fn($ignored) => 0; \array_map($cb, [$cb = $this->query(...), 1]); }
    public function mapQueryBeforeArgument(): void { $cb = $this->query(...); \array_map($cb, [$cb = static fn() => 0, 1]); }
    public function constants(): void { (self::CALLBACK)($this->db); (self::FIRST_CLASS)($this->db); (QUERY_CALLBACK)($this->db); }
    public function byReference(): void { $cb = static fn() => 0; $run = function () use (&$cb): void { $cb(); }; $cb = $this->query(...); $run(); }
    public function overwritten(): void { $cb = static fn() => 0; $cb = $this->query(...); $cb(); }
    public function invalidated(): void { $cb = $this->query(...); $cb = 42; $cb(); }
    public function branched(bool $yes): void { $cb = static fn() => 0; if ($yes) { $cb = $this->query(...); } $cb(); }
    public function recursiveTree(array $children): void { \array_map($this->recursiveTree(...), $children); }
    public function directCycle(): void { $this->directCycle(); }
    public function deferred(\Symfony\Component\EventDispatcher\EventDispatcherInterface $events): void { $events->addListener('ready', $this->directCycle(...)); }
    public function cache(\Symfony\Contracts\Cache\CacheInterface $cache): void { $cache->get(callback: $this->query(...), key: 'value'); }
    public function unrelated(): void { $builder = new QueryBuilder(); $builder->getQuery()->execute(); }
    public function external(\SearchClient\Query $query): void { $query->execute(); $this->db->executeQuery('SELECT 1'); }
    public function inherited(DerivedConnection $db): void { $db->executeQuery('SELECT 1'); }
    public function override(QuietConnection $db): void { $db->executeQuery('SELECT 1'); }
    public function inheritedOverride(quietderived $db): void { $db->executeQuery('SELECT 1'); }
    public function statement(): void { $this->db->prepare('SELECT 1')->executeQuery()->fetchAllAssociative(); }
    public function dbalBuilder(): void { $this->db->createQueryBuilder()->select('id')->from('items')->executeQuery()->fetchAllAssociative(); }
    public function transaction(): void { $this->db->transactional(function (Connection $db): void { $db->executeQuery('SELECT 1'); }); }
    public function transactionCallback(): void { $this->db->transactional($this->directCycle(...)); }
    public function ormTransaction(EntityManagerPort $manager): void { $manager->wrapInTransaction(function (EntityManagerInterface $em): void { $em->flush(); }); }
    public function unitOfWork(EntityManagerPort $manager, object $entity): void { $manager->persist($entity); $manager->remove($entity); $manager->flush(); }
    public function orm(EntityManagerPort $manager): void { $manager->createQueryBuilder()->select('e')->getQuery()->getSingleScalarResult(); }
}
