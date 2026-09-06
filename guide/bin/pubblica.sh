#!/usr/bin/env bash
# Porta le guide montate dentro `public/guide/`, da dove l'applicazione le serve.
#
# ⚠️ `public/guide/` è IGNORATO da git: cinque mp4 sono ~64 MB, e un repository
# non è un CDN. In produzione la stessa cartella andrà su Backblaze come il
# resto degli allegati; finché si sta in locale e su staging, la copia basta.
set -euo pipefail
cd "$(dirname "$0")/.."
DEST="../public/guide"

for MANIFEST in out/*/manifest.json; do
  SLUG="$(basename "$(dirname "$MANIFEST")")"
  VIDEO="out/${SLUG}/${SLUG}.mp4"

  if [ ! -f "$VIDEO" ]; then
    echo "salto ${SLUG}: manca l'mp4 (gira.sh non è stato lanciato?)" >&2
    continue
  fi

  mkdir -p "${DEST}/${SLUG}"
  cp "$MANIFEST" "${DEST}/${SLUG}/manifest.json"
  cp "$VIDEO" "${DEST}/${SLUG}/${SLUG}.mp4"
  # La copertina: senza, il lettore mostra da fermo il fotogramma zero, che è
  # il fondo della testata prima che il titolo entri — un rettangolo vuoto.
  [ -f "out/${SLUG}/copertina.jpg" ] && cp "out/${SLUG}/copertina.jpg" "${DEST}/${SLUG}/copertina.jpg"
  printf '%-18s %s\n' "$SLUG" "$(du -h "$VIDEO" | cut -f1)"
done
