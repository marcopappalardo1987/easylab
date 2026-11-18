#!/usr/bin/env bash
# Cattura una guida aspettando il proprio turno: il DB dimostrativo è uno, e
# `azzera.sh` lo ricrea. Due catture insieme si cancellerebbero i dati a vicenda.
# Se una clip esce corta (vedi bin/misura-clip.mjs) rigira, fino a tre volte.
#   ./bin/gira-in-fila.sh fornitori
set -euo pipefail
cd "$(dirname "$0")/.."
LOCK=/tmp/easylab-guide-cattura.lock
until mkdir "$LOCK" 2>/dev/null; do sleep 2; done
trap 'rmdir "$LOCK"' EXIT
for TENTATIVO in 1 2 3; do
  ./bin/azzera.sh
  npx playwright test "flussi/$1.spec.ts" --reporter=line
  node bin/misura-clip.mjs "$1" && exit 0
  echo "── clip corte, giro di nuovo ($TENTATIVO/3)"
done
exit 1
