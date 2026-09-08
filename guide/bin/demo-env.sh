#!/usr/bin/env bash
# Variabili dell'ambiente dimostrativo delle guide.
#
# ⛔ Il DB di sviluppo `easylab` non va MAI toccato da questi script.
# ⚠️ CACHE_STORE=array è obbligatorio: Redis è condiviso e la cache dei
#    permessi di spatie salva gli id di ruoli e permessi (vedi CLAUDE.md).
export DB_CONNECTION=pgsql
export DB_DATABASE=easylab_demo
export CACHE_STORE=array
export SESSION_DRIVER=file
export QUEUE_CONNECTION=sync
export MAIL_MAILER=log
export APP_ENV=local
export APP_URL=http://127.0.0.1:8123

# ⚠️ La pagina pubblica di registrazione esiste solo con questa accesa: senza,
# `/registrati` risponde 404 e la guida `registrarsi` non è filmabile. Vale per
# il solo ambiente dimostrativo (🔗 ADR-039).
export REGISTRAZIONE_APERTA=true

if [ "$DB_DATABASE" = "easylab" ]; then
  echo "RIFIUTO: DB_DATABASE punta al database di sviluppo." >&2
  exit 1
fi
