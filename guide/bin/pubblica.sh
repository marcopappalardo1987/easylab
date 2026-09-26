#!/usr/bin/env bash
# Porta le guide montate sul disco da cui l'applicazione le serve.
#
# Senza argomenti pubblica sul disco locale (sviluppo). Per un ambiente remoto:
#   ./bin/pubblica.sh remoto
# che richiede GUIDE_REMOTO_* nel .env (copiate da LARAVEL_CLOUD_DISK_CONFIG).
#
# ⚠️ NON esiste più una copia in `public/`: staging e produzione girano su
# Laravel Cloud, che costruisce l'immagine da git — ciò che non è versionato
# non arriva, e 64 MB di mp4 in git non ci vanno.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ "${1:-locale}" = "remoto" ]; then
  cd .. && GUIDE_DISK=guide_remoto php artisan easylab:pubblica-guide
else
  cd .. && php artisan easylab:pubblica-guide
fi
