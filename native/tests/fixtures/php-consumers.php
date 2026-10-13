<?php
namespace PhpConsumers;
use Doctrine\DBAL\Connection;
final class ConsumersController {
    public function __construct(private Connection $db) {}
    private function sql(): int { $this->db->executeQuery('select 1'); return 0; }
    private function generator(): \Generator { $this->db->executeQuery('select 1'); yield 1; yield 2 => 3; }
    private function nested(): \Generator { yield from $this->generator(); }
    // budget: objectConcatenation 0 null
    public function objectConcatenation(): void { $object=new StringCaster($this->db); $string='value: '.$object; }
    // budget: reflectionBaseBody 1 1
    public function reflectionBaseBody(): void { $target=new ReflectionChild($this->db); $r=new \ReflectionMethod(ReflectionBase::class,'run'); $r->invoke($target); }
    // budget: reflectionNamedArgs 1 1
    public function reflectionNamedArgs(): void { $r=new \ReflectionMethod(self::class,'sql'); $r->invokeArgs(args: [], object: $this); }
    // budget: dynamicBindingScope 0 null
    public function dynamicBindingScope(): void { $cb=function(){return $this->sql();}; $bound=$cb->bindTo($this,$dynamic); $bound(); }
    // budget: keyedWalk 2 2
    public function keyedWalk(): void { $items=['a'=>1,'b'=>2]; \array_walk_recursive($items,fn($v)=>$this->sql()); }
    // budget: duplicateKeyWalk 0 null
    public function duplicateKeyWalk(): void { $items=['a'=>1,'a'=>2]; \array_walk_recursive($items,fn($v)=>$this->sql()); }
    // budget: unknownNestedWalk 0 null
    public function unknownNestedWalk(array $unknown): void { $items=[$unknown]; \array_walk_recursive($items,fn($v)=>$this->sql()); }
    // budget: namedExtraWalk 1 1
    public function namedExtraWalk(): void { $items=[1]; \array_walk_recursive(arg: $this->sql(...), callback: fn($v,$k,$cb)=>$cb(), array: $items); }
    // budget: storedCallArgs 1 1
    public function storedCallArgs(): void { $args=['callback'=>$this->sql(...)]; \call_user_func_array(fn($callback)=>$callback(),$args); }
    // budget: readonlyProperty 1 1
    public function readonlyProperty(): void { $holder=new CallbackHolder($this->sql(...)); $holder->run(); }
    // budget: mutableProperty 0 null
    public function mutableProperty(): void { $holder=new MutableHolder($this->sql(...)); $holder->run(); }
    // budget: magicRead 0 null
    public function magicRead(): void { $holder=new MagicHolder($this->db); $unused=$holder->value; }
    // budget: magicWrite 0 null
    public function magicWrite(): void { $holder=new MagicHolder($this->db); $holder->value=1; }
    // budget: foreachGenerator 0 1
    public function foreachGenerator(): void { foreach($this->generator() as $item) {} }
    // budget: sortedArrayInvalidation 0 null
    public function sortedArrayInvalidation(): void { $items=['x','y']; \usort($items,fn($a,$b)=>0); \array_map(\strval(...),$items); }
    // budget: walkInvalidation 0 null
    public function walkInvalidation(): void { $items=['x']; \array_walk_recursive($items,fn($v)=>0); \array_map(\strval(...),$items); }
    // budget: sortQuery 0 null
    public function sortQuery(): void { $items=[2,1]; \usort($items, fn($a,$b)=>$this->sql()); }
    // budget: sortPure 0 0
    public function sortPure(): void { $items=[2,1]; \uasort(callback: fn($a,$b)=>0, array: $items); }
    // budget: keySort 0 null
    public function keySort(): void { $items=['b'=>2,'a'=>1]; \uksort($items, fn($a,$b)=>$this->sql()); }
    // budget: emptySort 0 0
    public function emptySort(): void { $items=[]; \usort($items, $this->sql(...)); }
    // budget: twoComparators 0 null
    public function twoComparators(): void { \array_udiff_uassoc([1,2],[3,4],fn($a,$b)=>$this->sql(),fn($a,$b)=>$this->sql()); }
    // budget: pureIntersect 0 0
    public function pureIntersect(): void { \array_uintersect([1],[2],fn($a,$b)=>0); }
    // budget: recursiveWalk 3 3
    public function recursiveWalk(): void { $items=[1,[2,3]]; \array_walk_recursive($items,fn($v)=>$this->sql()); }
    // budget: recursiveWalkPure 0 0
    public function recursiveWalkPure(): void { $items=[1,[2,3]]; \array_walk_recursive($items,fn($v)=>0); }
    // budget: pregQuery 0 null
    public function pregQuery(): void { \preg_replace_callback('/a/',fn($m)=>$this->sql(),'aaa'); }
    // budget: pregMap 0 null
    public function pregMap(): void { \preg_replace_callback_array(['/a/'=>fn($m)=>$this->sql(),'/b/'=>fn($m)=>$this->sql()],'ab'); }
    // budget: shutdown 0 null
    public function shutdown(): void { \register_shutdown_function($this->sql(...)); }
    // budget: errorHandler 0 null
    public function errorHandler(): void { \set_error_handler($this->sql(...)); }
    // budget: exceptionHandler 0 null
    public function exceptionHandler(): void { \set_exception_handler($this->sql(...)); }
    // budget: autoload 0 null
    public function autoload(): void { \spl_autoload_register($this->sql(...)); }
    // budget: generatorCreation 0 0
    public function generatorCreation(): void { $unused=$this->generator(); }
    // budget: generatorCount 1 1
    public function generatorCount(): void { $values=$this->generator(); \iterator_count($values); }
    // budget: generatorArray 1 1
    public function generatorArray(): void { \iterator_to_array($this->generator()); }
    // budget: nestedGenerator 1 1
    public function nestedGenerator(): void { \iterator_count($this->nested()); }
    // budget: generatorClosure 1 1
    public function generatorClosure(): void { $cb=function(){ $this->db->executeQuery('select 1'); yield 1; }; \iterator_count($cb()); }
    // budget: repeatedGenerator 1 null
    public function repeatedGenerator(): void { $values=$this->generator(); $alias=$values; \iterator_count($values); \iterator_count($alias); }
    // budget: iteratorCallback 0 null
    public function iteratorCallback(): void { \iterator_apply($this->generator(),fn()=>$this->sql()); }
    // budget: closureCall 1 1
    public function closureCall(): void { $cb=function(){return $this->sql();}; $cb->call($this); }
    // budget: closureBinding 0 0
    public function closureBinding(): void { $cb=function(){return $this->sql();}; $bound=$cb->bindTo($this); }
    // budget: closureBindToInvoke 1 1
    public function closureBindToInvoke(): void { $cb=function(){return $this->sql();}; $bound=$cb->bindTo($this); $bound(); }
    // budget: closureBindInvoke 1 1
    public function closureBindInvoke(): void { $cb=function(){return $this->sql();}; $bound=\Closure::bind($cb,$this); $bound(); }
    // budget: reflectionClosure 1 1
    public function reflectionClosure(): void { $reflection=new \ReflectionFunction(fn()=>$this->sql()); $reflection->invoke(); }
    // budget: reflectionMethod 1 1
    public function reflectionMethod(): void { $reflection=new \ReflectionMethod(self::class,'sql'); $reflection->invoke($this); }
    // budget: reflectionArgs 1 1
    public function reflectionArgs(): void { $reflection=new \ReflectionFunction(fn($callback)=>$callback()); $reflection->invokeArgs([$this->sql(...)]); }
    // budget: opaqueContinuation 1 null
    public function opaqueContinuation(): void { \iterator_count($unknown); $this->sql(); }
}

final class CallbackHolder {
    public function __construct(private readonly \Closure $callback) {}
    public function run(): void { ($this->callback)(); }
}
final class MutableHolder {
    public function __construct(private \Closure $callback) {}
    public function run(): void { ($this->callback)(); }
}
final class MagicHolder {
    public function __construct(private Connection $db) {}
    public function __get(string $name): int { $this->db->executeQuery('select 1'); return 0; }
    public function __set(string $name,mixed $value): void { $this->db->executeQuery('select 1'); }
}

class ReflectionBase {
    public function __construct(protected Connection $db) {}
    public function run(): void { $this->query(); }
    protected function query(): void {}
}
final class ReflectionChild extends ReflectionBase {
    public function run(): void {}
    protected function query(): void { $this->db->executeQuery('select 1'); }
}

final class StringCaster {
    public function __construct(private Connection $db) {}
    public function __toString(): string { $this->db->executeQuery('select 1'); return ''; }
}
