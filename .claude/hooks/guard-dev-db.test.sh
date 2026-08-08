#!/usr/bin/env bash
# Self-test di guard-dev-db.sh — `bash .claude/hooks/guard-dev-db.test.sh`.
#
# Esiste perché la guardia ha già sbagliato due volte: una prima versione usava
# jq (non installato) e lasciava passare tutto in silenzio; una seconda bloccava
# qualunque comando che NOMINASSE migrate:fresh, compresi i messaggi di commit.
# I casi vivono qui dentro e non sulla riga di comando, altrimenti sarebbe la
# guardia stessa a bloccare il proprio test.

set -uo pipefail
cd "$(dirname "$0")/../.."

GUARD=".claude/hooks/guard-dev-db.sh"
falliti=0

verifica() { # verifica <deny|allow> <comando>
    atteso="$1"; comando="$2"
    payload=$(python3 -c "import json,sys;print(json.dumps({'tool_name':'Bash','tool_input':{'command':sys.argv[1]}}))" "$comando")
    if echo "$payload" | "$GUARD" | grep -q '"deny"'; then ottenuto="deny"; else ottenuto="allow"; fi

    if [ "$ottenuto" = "$atteso" ]; then
        printf '  ok    %-6s %s\n' "$atteso" "$comando"
    else
        printf '  FALLITO atteso=%s ottenuto=%s : %s\n' "$atteso" "$ottenuto" "$comando"
        falliti=$((falliti + 1))
    fi
}

echo "Comandi distruttivi sul DB di sviluppo:"
verifica deny 'php artisan migrate:fresh --seed'
verifica deny 'php artisan migrate:refresh'
verifica deny 'php artisan db:wipe'
verifica deny 'DB_CONNECTION=pgsql DB_DATABASE=easylab php artisan test --filter=x'
verifica deny 'DB_CONNECTION=pgsql php artisan test'
verifica deny 'DB_DATABASE=easylab vendor/bin/pest'

echo "Comandi che si limitano a nominarli (niente falsi positivi):"
verifica allow 'git commit -m "documenta che migrate:fresh cancella tutto"'
verifica allow 'grep -rn "db:wipe" docs/'
verifica allow 'echo "mai usare migrate:refresh"'

echo "Percorsi legittimi:"
verifica allow 'php artisan test'
verifica allow 'php artisan test --filter=SemaforoTest'
verifica allow 'DB_DATABASE=easylab_test php artisan test --filter=x'
verifica allow 'DB_CONNECTION=pgsql DB_DATABASE=easylab_test php artisan test'
verifica allow 'php artisan migrate'
verifica allow 'php artisan migrate --force'
verifica allow 'php artisan db:seed --class=DemoSeeder'
verifica allow 'vendor/bin/pint --dirty'
verifica allow 'npm run build'

if [ "$falliti" -gt 0 ]; then
    echo "FALLITI: ${falliti}"
    exit 1
fi

echo "Tutti i casi superati."
