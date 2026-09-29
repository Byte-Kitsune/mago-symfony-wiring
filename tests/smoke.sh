#!/bin/sh
set -eu
php tests/check.php
php tests/real-container-parity.php
php tests/resource-container-parity.php
php tests/container-reference.php
cd tests/corpus
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > /tmp/mago-symfony-wiring-report.json
php -r '$r=json_decode(file_get_contents("/tmp/mago-symfony-wiring-report.json"),true,512,JSON_THROW_ON_ERROR); $codes=array_column($r["issues"],"code"); if(count(array_filter($codes,fn($c)=>$c==="byte-kitsune/symfony-wiring/unresolved-target"))!==1) exit(1); $markers=array_values(array_filter($r["issues"],fn($i)=>$i["code"]==="byte-kitsune/symfony-wiring/analysis-attestation")); if(count($markers)!==1) exit(1); $note=$markers[0]["notes"][0]??""; if(!str_starts_with($note,"extension-attestation: ")) exit(1); $attestation=json_decode(substr($note,strlen("extension-attestation: ")),true,512,JSON_THROW_ON_ERROR); if(($attestation["extension"]??null)!=="byte-kitsune/symfony-wiring" || ($attestation["version"]??null)!=="0.1.0-beta.8" || ($attestation["capability"]??null)!=="service_wiring" || ($attestation["complete"]??null)!==true || ($attestation["source_files"]??0)<1) exit(1); echo "Mago wiring corpus passed\n";'
