<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
// Also works against the SDK dependencies from a separate installed toolchain.
spl_autoload_register(function (string $class): void {
    $prefix = 'ByteKitsune\\MagoSymfonyWiring\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
}, true, true);

use ByteKitsune\MagoSymfonyWiring\Security\ConfigSecretInspector;
use ByteKitsune\MagoSymfonyWiring\Security\PhpSecretInspector;
use ByteKitsune\MagoSymfonyWiring\Security\SecretPolicy;
use ByteKitsune\MagoSymfonyWiring\Security\YamlSecretInspector;

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$policy = new SecretPolicy();
foreach (['%env(FOO)%', '%env(resolve:FOO)%', '%env(key:0:json:DATA)%', '%env(default::APP_PASSWORD)%', '%env(default:app.secret:APP_PASSWORD)%', '%env(enum:App\\Enum\\Foo:ENV)%', '', '   '] as $value) check(!$policy->hardcoded($value), 'Allowed placeholder/empty was rejected.');
foreach (['%env()%', '%env(:FOO)%', '%env(resolve:)%', '%env(resolve::FOO)%', '%env(FOO)%x', 'x%env(FOO)%', '%env(FOO BAR)%', 'literal', 0] as $value) check($policy->hardcoded($value), 'Unsafe literal was allowed.');
$php = <<<'SOURCE'
<?php
$password = 'SENTINEL_SECRET';
$dbPassword = '%env(resolve:DB_PASSWORD)%';
$token = env('TOKEN');
$token = other_env('TOKEN'); // Unknown dynamic calls are outside literal-only detection.
$arr = ['client_secret' => 'SENTINEL_SECRET', 'key' => 'metadata', 'password' => '', 'secret' => '%env(SECRET)%'];
$x['api_key'] = 'SENTINEL_SECRET';
$x->accessToken = 'SENTINEL_SECRET';
f(password: 'SENTINEL_SECRET');
class C { public $password = 'SENTINEL_SECRET'; const PRIVATE_KEY = 'SENTINEL_SECRET'; public function f($pwd = 'SENTINEL_SECRET') {} }
$password = 'SENTINEL' . '_SECRET';
$text = '$password = "SENTINEL_SECRET"';
// $token = 'SENTINEL_SECRET';
SOURCE;
$phpIssues = (new PhpSecretInspector($policy))->inspect($php);
check(count($phpIssues) === 9, 'PHP coverage count mismatch: ' . count($phpIssues));
$yaml = <<<'SOURCE'
parameters:
  password: SENTINEL_SECRET
  db_password: '%env(resolve:DB_PASSWORD)%'
  api_key: ''
  metadata: {key: metadata, token: "SENTINEL_SECRET", secret: '%env(SECRET)%'}
  client_secret: |
    SENTINEL_SECRET
    MORE_SECRET
  private_key: >-
    SENTINEL_SECRET
  access_token: "%env(ACCESS_TOKEN)%suffix"
  pwd: 42
  multiline_token: "SENTINEL_SECRET
    CONTINUED"
  base: &credential SENTINEL_SECRET
  inherited_secret: *credential
SOURCE;
$yi = (new YamlSecretInspector($policy))->inspect($yaml);
check(count($yi['spans']) === 8, 'YAML coverage count mismatch: ' . count($yi['spans']));
check($yi['incomplete'] === [], 'Simple scalar alias must resolve.');
foreach ($yi['spans'] as $span) check(str_contains(substr($yaml, $span['start'], $span['end'] - $span['start']), 'SENTINEL_SECRET') || str_contains(substr($yaml, $span['start'], $span['end'] - $span['start']), 'suffix') || substr($yaml, $span['start'], $span['end'] - $span['start']) === '42' || substr($yaml, $span['start'], $span['end'] - $span['start']) === '*credential', 'Incorrect YAML exact token span.');
$tmp = sys_get_temp_dir() . '/wiring-security-' . bin2hex(random_bytes(8));
mkdir($tmp);
try {
    file_put_contents($tmp . '/config.php', $php);
    file_put_contents($tmp . '/config.yaml', $yaml);
    file_put_contents($tmp . '/bad.yaml', "password: [SENTINEL_SECRET\n");
    $report = ConfigSecretInspector::inspectConfigFiles($tmp, ['config.php', 'config.yaml', 'bad.yaml']);
    check(count($report['issues']) === 17 && count($report['incomplete']) === 1, 'Public report mismatch.');
    check(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'SENTINEL_SECRET'), 'Secret leaked into report.');
    check(ConfigSecretInspector::inspectConfigFiles($tmp, ['config.php'], ['excludePaths' => ['config.php']])['issues'] === [], 'Exclusion ignored.');
    check((new SecretPolicy(['sensitiveKeys' => ['credential']]))->sensitive('dbCredential'), 'Custom key normalization failed.');
    try { ConfigSecretInspector::inspectConfigFiles($tmp, ['../escape.yaml']); throw new RuntimeException('Traversal allowed.'); } catch (InvalidArgumentException) {}
} finally { foreach (glob($tmp . '/*') as $file) unlink($file); rmdir($tmp); }
echo "Configuration security tests passed\n";

use ByteKitsune\MagoSymfonyWiring\SecurityExtension;
foreach ([
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension as Sec; return [Sec::create(__DIR__)];',
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension; use Mago\\Sdk\\Worker; (new Worker(SecurityExtension::create(__DIR__, ["excludePaths"=>["tests/*"]])))->run();',
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension; use Mago\\Sdk\\Worker; $extensions=[SecurityExtension::create(__DIR__)]; (new Worker(...$extensions))->run();',
] as $source) check(SecurityExtension::inspectConfiguration($source)['status'] === 'enabled', 'Literal registration was not recognized.');
foreach ([
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension; if (false) { SecurityExtension::create(__DIR__); }',
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension; return [SecurityExtension::create(__DIR__, getenv("OPTS"))];',
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension; function hidden(){return SecurityExtension::create(__DIR__);}',
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension; $unused=SecurityExtension::create(__DIR__);',
] as $source) check(SecurityExtension::inspectConfiguration($source)['status'] === 'unresolved', 'Unproven registration was accepted.');
check(SecurityExtension::inspectConfiguration('<?php return [];')['status'] === 'absent', 'Absent registration was not absent.');
echo "Configuration opt-in inspection tests passed\n";

$blockNoise = (new YamlSecretInspector($policy))->inspect("description: |\n  password: text, not a config key\npassword: '%env(default::APP_PASSWORD)%'\n");
check($blockNoise['spans'] === [] && $blockNoise['incomplete'] === [], 'Block text was confused with YAML keys.');
$flow = (new YamlSecretInspector($policy))->inspect('{"password":"SENTINEL_SECRET", "token":"%env(TOKEN)%"}');
check(count($flow['spans']) === 1, 'Flow map without colon whitespace was missed.');
check(SecurityExtension::inspectConfiguration('<?php $extensions=require __DIR__."/extension.php";')['status'] === 'unresolved', 'External config falsely absent.');
echo "YAML lexical boundary tests passed\n";

foreach ([
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension; $extensions=[SecurityExtension::create(__DIR__)]; mutate($extensions); return $extensions;',
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension; $extensions=[SecurityExtension::create(__DIR__)]; if ($x) {$extensions=[];} return $extensions;',
    '<?php use ByteKitsune\\MagoSymfonyWiring\\SecurityExtension; $e=[]; $extensions=[$e]; $e=SecurityExtension::create(__DIR__); return $extensions;',
] as $source) check(SecurityExtension::inspectConfiguration($source)['status'] === 'unresolved', 'Mutated registration was accepted.');
echo "Static registration mutation tests passed\n";

$alias = (new YamlSecretInspector($policy))->inspect("base: &safe '%env(default::PASSWORD)%'\npassword: *safe\nbase2: &unsafe sentinel\napi_token: *unsafe\nbase3: &map {a: b}\nprivate_key: *map\n");
check(count($alias['spans']) === 1 && count($alias['incomplete']) === 1, 'Scalar alias policy or complex anchor incompleteness wrong.');
echo "YAML scalar alias tests passed\n";

$host= <<<'HOST'
<?php
use ByteKitsune\MagoSymfonyWiring\SymfonyWiringExtension;
use Mago\Sdk\Worker;
require __DIR__.'/vendor/autoload.php';
$root=dirname(__DIR__);
$extensions=require $root.'/.mago/extension.php';
if(is_file($root.'/.mago/container.json')) $extensions[]=SymfonyWiringExtension::fromContainerReference($root,'.mago/container.json');
(new Worker(...$extensions))->run();
HOST;
$linked=SecurityExtension::inspectConfiguration($host, '/project/tools/worker.php');
check(($linked['references']??[]) === ['/project/.mago/extension.php'], 'Active canonical require data flow not proven.');
$mutated=str_replace('(new Worker', 'mutate($extensions); (new Worker', $host);
check(!isset(SecurityExtension::inspectConfiguration($mutated, '/project/tools/worker.php')['references']), 'Mutated required list was accepted.');
$unused=str_replace('(new Worker(...$extensions))->run();', '', $host);
check(!isset(SecurityExtension::inspectConfiguration($unused, '/project/tools/worker.php')['references']), 'Unused canonical list was accepted.');
echo "Active configuration reference tests passed\n";

$multilineFlow=(new YamlSecretInspector($policy))->inspect("{password:\n  'SENTINEL_SECRET', token:\n '%env(TOKEN)%'}");
check(count($multilineFlow['spans'])===1, 'Flow scalar following newline was missed.');
$condition=str_replace('if(is_file(', 'if(mutate($extensions) || is_file(', $host);
check(!isset(SecurityExtension::inspectConfiguration($condition,'/project/tools/worker.php')['references']), 'Mutating condition was accepted.');

$authorizationPhp=(new PhpSecretInspector($policy))->inspect('<?php $headers=["Authorization"=>"Bearer SENTINEL_SECRET"];');
$authorizationYaml=(new YamlSecretInspector($policy))->inspect("headers: {Authorization: 'Bearer SENTINEL_SECRET'}");
check(count($authorizationPhp)===1 && count($authorizationYaml['spans'])===1, 'Authorization literals were missed.');
check((new YamlSecretInspector($policy))->inspect("Authorization: '%env(AUTH_HEADER)%'")['spans'] === [], 'Authorization env placeholder rejected.');
echo "Authorization credential tests passed\n";
