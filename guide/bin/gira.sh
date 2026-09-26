#!/usr/bin/env bash
# Giro completo di una o più guide: per ciascuna azzera, cattura, monta.
#
# L'azzeramento è PER GUIDA, non una volta sola: ogni copione scrive (imposta
# una password, spegne un interruttore, cambia sede) e partirebbe da quello che
# ha lasciato il precedente.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f out/audio/tema.wav ] || node bin/musica.mjs

for SLUG in "${@:-intervento}"; do
  echo "── ${SLUG}"
  ./bin/azzera.sh
  npx playwright test "flussi/${SLUG}.spec.ts"
  node bin/monta.mjs "$SLUG"
done
