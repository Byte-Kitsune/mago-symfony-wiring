#!/bin/sh
set -eu
php tests/check.php
php tests/real-container-parity.php
php tests/resource-container-parity.php
php tests/container-reference.php
cd tests/corpus
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > /tmp/mago-symfony-wiring-report.json
MAGO_SYMFONY_WIRING_ATTESTATION=1 ../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > /tmp/mago-symfony-wiring-attested.json
php -r '
$normal=json_decode(file_get_contents("/tmp/mago-symfony-wiring-report.json"),true,512,JSON_THROW_ON_ERROR);
$attested=json_decode(file_get_contents("/tmp/mago-symfony-wiring-attested.json"),true,512,JSON_THROW_ON_ERROR);
$marker="byte-kitsune/symfony-wiring/analysis-attestation";
if(in_array($marker,array_column($normal["issues"],"code"),true)) exit(1);
if(count(array_filter(array_column($normal["issues"],"code"),fn($code)=>$code==="byte-kitsune/symfony-wiring/unresolved-target"))!==1) exit(1);
$markers=array_values(array_filter($attested["issues"],fn($issue)=>$issue["code"]===$marker));
if(count($markers)!==1) exit(1);
$note=$markers[0]["notes"][0]??"";
if(!str_starts_with($note,"extension-attestation: ")) exit(1);
$value=json_decode(substr($note,strlen("extension-attestation: ")),true,512,JSON_THROW_ON_ERROR);
if(($value["extension"]??null)!=="byte-kitsune/symfony-wiring" || ($value["version"]??null)!=="1.1.1" || ($value["capability"]??null)!=="service_wiring" || ($value["complete"]??null)!==true || ($value["source_files"]??0)<1) exit(1);
echo "Mago wiring corpus passed\n";
'
cd ../..
php tests/security.php
sh tests/security-cli.sh
cd tests/security-corpus
if ../../vendor/bin/mago lint --only byte-kitsune/symfony-wiring/no-hardcoded-secret --reporting-format json > /tmp/mago-symfony-security-report.json; then
    echo 'Expected hardcoded credential findings.' >&2
    exit 1
fi
php -r '
$report=json_decode(file_get_contents("/tmp/mago-symfony-security-report.json"),true,512,JSON_THROW_ON_ERROR);
$issues=$report["issues"]??[];
if(count($issues)!==4) exit(1);
foreach($issues as $issue) {
    if(($issue["code"]??null)!=="byte-kitsune/symfony-wiring/no-hardcoded-secret") exit(1);
    if(($issue["level"]??null)!=="Error") exit(1);
    if(str_contains(json_encode([$issue["message"],$issue["notes"]??[],$issue["help"]??null]),"SENTINEL_SECRET")) exit(1);
}
echo "Native Mago security corpus passed\n";
'
