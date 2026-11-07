#!/usr/bin/env bash
# Cattura una guida aspettando il proprio turno: il DB dimostrativo è uno, e
# `azzera.sh` lo ricrea. Due catture insieme si cancellerebbero i dati a vicenda.
#   ./bin/gira-in-fila.sh fornitori
set -euo pipefail
cd "$(dirname "$0")/.."
LOCK=/tmp/easylab-guide-cattura.lock
until mkdir "$LOCK" 2>/dev/null; do sleep 2; done
trap 'rmdir "$LOCK"' EXIT
./bin/azzera.sh
npx playwright test "flussi/$1.spec.ts" --reporter=line
