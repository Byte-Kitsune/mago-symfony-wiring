#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
: "${MAGO_NATIVE_ENGINE:?Set the statically linked musl engine path}"
docker run --rm --network none --user "$(id -u):$(id -g)" \
  -v "$root:$root:ro" -w "$root" \
  -e MAGO_NATIVE_ENGINE="$MAGO_NATIVE_ENGINE" -e MAGO_EXPECT_MUSL=1 \
  php:8.5-cli-alpine sh -c \
  'php tests/assert-native-platform.php && php tests/assert-native-install.php && php tests/assert-native-runtime.php'
