#!/usr/bin/env bash
# Riporta il DB dimostrativo al seme.
#
# Serve perché il copione SCRIVE (registra un intervento): senza, ogni giratura
# lascia la propria riga e gli scatti divergono. È già successo alla seconda
# passata, con lo storico che mostrava due interventi identici.
#
# Ricreazione da template invece che da dump: è una copia di file lato server,
# ~2s contro ~30s di ripristino SQL.
set -euo pipefail
PSQL="psql -U postgres -h 127.0.0.1 -q"

if [ "${1:-}" = "--semina" ]; then
  $PSQL -d postgres -c "DROP DATABASE IF EXISTS easylab_demo_seme"
  $PSQL -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='easylab_demo' AND pid<>pg_backend_pid()" >/dev/null
  $PSQL -d postgres -c "CREATE DATABASE easylab_demo_seme TEMPLATE easylab_demo"
  echo "Seme aggiornato dallo stato attuale di easylab_demo."
  exit 0
fi

$PSQL -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IN ('easylab_demo') AND pid<>pg_backend_pid()" >/dev/null
$PSQL -d postgres -c "DROP DATABASE IF EXISTS easylab_demo"
$PSQL -d postgres -c "CREATE DATABASE easylab_demo TEMPLATE easylab_demo_seme"
echo "easylab_demo riportato al seme."
