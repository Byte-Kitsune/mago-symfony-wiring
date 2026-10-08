#!/bin/sh
set -eu
root=$(mktemp -d)
trap 'rmdir "$root" 2>/dev/null || true' EXIT
printf "password: '%%env(resolve:APP_PASSWORD)%%'\n" > "$root/clean.yaml"
printf "api_token: SENTINEL_SECRET\n" > "$root/secret.yaml"
printf "private_key: {nested: value}\n" > "$root/complex.yaml"
php bin/check-config-secrets.php --root="$root" --file=clean.yaml > "$root/report.json"
if php bin/check-config-secrets.php --root="$root" --file=secret.yaml > "$root/report.json"; then
    exit 1
else
    code=$?
    test "$code" -eq 1
fi
php -r '$v=file_get_contents($argv[1]); if(str_contains($v,"SENTINEL_SECRET") || count(json_decode($v,true,512,JSON_THROW_ON_ERROR)["issues"])!==1) exit(1);' "$root/report.json"
if php bin/check-config-secrets.php --root="$root" --file=complex.yaml > "$root/report.json"; then
    exit 1
else
    code=$?
    test "$code" -eq 2
fi
php -r 'foreach(glob($argv[1]."/*") as $file) unlink($file);' "$root"
echo 'Standalone configuration security CLI passed'
