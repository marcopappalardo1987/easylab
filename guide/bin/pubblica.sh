#!/usr/bin/env bash
# Porta le guide montate sul disco da cui l'applicazione le serve.
#
#   ./bin/pubblica.sh                    → disco locale (sviluppo)
#   ./bin/pubblica.sh staging [slug]     → bucket di staging
#   ./bin/pubblica.sh produzione [slug]  → bucket di produzione
#
# Le credenziali stanno nel .env come GUIDE_STAGING_* e GUIDE_PRODUZIONE_*,
# copiate da LARAVEL_CLOUD_DISK_CONFIG del pannello di quell'ambiente.
#
# ⛔ L'ambiente si NOMINA, non si eredita da una variabile già impostata: il
# 27 Set 2026 due guide sono finite su staging credendo di pubblicarle in
# produzione, perché il disco si chiamava «remoto» e il nome non diceva dove
# portava. Un bucket sbagliato non dà errore: accetta i file e tace.
#
# ⚠️ NON esiste più una copia in `public/`: staging e produzione girano su
# Laravel Cloud, che costruisce l'immagine da git — ciò che non è versionato
# non arriva, e 64 MB di mp4 in git non ci vanno.
set -euo pipefail
cd "$(dirname "$0")/../.."

dove="${1:-locale}"
solo=""
[ -n "${2:-}" ] && solo="--solo=$2"

case "$dove" in
  locale)     exec php artisan easylab:pubblica-guide $solo ;;
  staging)    exec env GUIDE_DISK=guide_staging php artisan easylab:pubblica-guide $solo ;;
  produzione) exec env GUIDE_DISK=guide_produzione php artisan easylab:pubblica-guide $solo ;;
  *) echo "Ambiente sconosciuto: $dove — usa locale, staging o produzione." >&2; exit 1 ;;
esac
