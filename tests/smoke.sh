#!/bin/sh
set -eu
php tests/check.php
cd tests/corpus
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level warning > /tmp/mago-symfony-wiring-report.json
php -r '$r=json_decode(file_get_contents("/tmp/mago-symfony-wiring-report.json"),true,512,JSON_THROW_ON_ERROR); $codes=array_column($r["issues"],"code"); if(count(array_filter($codes,fn($c)=>$c==="byte-kitsune/symfony-wiring/unresolved-target"))!==1) exit(1); echo "Mago wiring corpus passed\n";'
