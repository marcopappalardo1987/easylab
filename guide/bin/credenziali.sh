#!/usr/bin/env bash
# Porta nel .env le credenziali di un bucket Laravel Cloud, prendendole dagli
# APPUNTI invece che a mano.
#
#   Copia dal pannello (bottone «copy»), poi:
#     ./bin/credenziali.sh produzione
#     ./bin/credenziali.sh staging
#
# Accetta le righe AWS_* che il pannello mette negli appunti — sia il blocco
# «Credentials» del bucket (AWS_BUCKET, AWS_ENDPOINT, AWS_DEFAULT_REGION) sia
# quello della chiave di accesso (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY) —
# e le riscrive come GUIDE_<AMBIENTE>_*. Si può lanciare due volte, una per
# blocco: aggiorna solo le righe che trova.
#
# ⛔ Non stampa MAI i valori: né a schermo né nei log. Dice solo quali voci ha
# aggiornato. Un segreto che compare in un terminale finisce nella cronologia,
# in uno screenshot o in una chat, e da lì non si toglie più.
#
# ⚠️ Le credenziali di due ambienti NON si mescolano: il 27 Set 2026 due guide
# sono finite sul bucket di staging perché il disco si chiamava «remoto» e non
# diceva dove portava (🔗 config/filesystems.php, guide/bin/pubblica.sh).
set -euo pipefail
cd "$(dirname "$0")/../.."

ambiente="${1:-}"
case "$ambiente" in
  produzione|staging) ;;
  *) echo "uso: ./bin/credenziali.sh produzione|staging   (dopo aver copiato dal pannello)" >&2; exit 1 ;;
esac

prefisso="GUIDE_$(echo "$ambiente" | tr '[:lower:]' '[:upper:]')"
appunti="$(pbpaste)"

if ! grep -q '^AWS_' <<<"$appunti"; then
  echo "Negli appunti non ci sono righe AWS_*. Copia dal pannello e rilancia." >&2
  exit 1
fi

aggiorna() {
  local da="$1" a="$prefisso$2"
  local valore
  valore="$(grep -m1 "^${da}=" <<<"$appunti" | cut -d= -f2- | tr -d '"'"'"'\r' || true)"
  [ -z "$valore" ] && return 0

  # In due tempi, con un file temporaneo: `sed -i` su .env davanti a un valore
  # con caratteri speciali (le chiavi ne hanno) rischia di storpiare la riga.
  if grep -q "^${a}=" .env; then
    grep -v "^${a}=" .env > .env.tmp
  else
    cp .env .env.tmp
  fi
  printf '%s=%s\n' "$a" "$valore" >> .env.tmp
  mv .env.tmp .env
  echo "  $a aggiornata (${#valore} caratteri)"
}

echo "$prefisso — dagli appunti:"
aggiorna AWS_ACCESS_KEY_ID _KEY
aggiorna AWS_SECRET_ACCESS_KEY _SECRET
aggiorna AWS_BUCKET _BUCKET
aggiorna AWS_ENDPOINT _ENDPOINT
aggiorna AWS_DEFAULT_REGION _REGION

mancano=""
for v in KEY SECRET BUCKET ENDPOINT; do
  grep -qE "^${prefisso}_${v}=.+" .env || mancano="$mancano ${prefisso}_${v}"
done
[ -n "$mancano" ] && echo "Ancora vuote:$mancano — copia l'altro blocco e rilancia."

exit 0
