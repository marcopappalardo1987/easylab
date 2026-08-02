#!/usr/bin/env python3
"""PreToolUse/Bash — protegge il database di SVILUPPO (easylab, vedi .env).

Perché esiste: la suite applica RefreshDatabase a tutti i test Feature, quindi
`php artisan test` con DB_DATABASE/DB_CONNECTION puntati al DB di sviluppo fa un
migrate:fresh e cancella tutto. È già successo, senza backup disponibili.

NON blocca il percorso legittimo: `DB_DATABASE=easylab_test` resta permesso ed è
il modo giusto per verificare comportamenti specifici di Postgres.

Niente dipendenze esterne (jq non è installato su questa macchina): solo stdlib.
"""

import json
import re
import sys

DEV_DB = "easylab"


def deny(reason: str) -> None:
    json.dump({
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "deny",
            "permissionDecisionReason": reason,
        }
    }, sys.stdout)
    sys.exit(0)


def main() -> None:
    try:
        payload = json.load(sys.stdin)
    except Exception:
        sys.exit(0)  # payload illeggibile: non interferire

    cmd = (payload.get("tool_input") or {}).get("command") or ""
    if not cmd:
        sys.exit(0)

    # 1. Comandi che ricreano o svuotano lo schema. Si richiede la forma
    # `artisan <comando>`: cercare il solo nome bloccherebbe anche chi si limita
    # a NOMINARLO (un messaggio di commit, un grep, questa documentazione).
    if re.search(r"\bartisan\s+(?:--\S+\s+)*(migrate:fresh|migrate:refresh|db:wipe)\b", cmd):
        deny(
            f"Bloccato: migrate:fresh / migrate:refresh / db:wipe distruggono i dati "
            f"del database di sviluppo ({DEV_DB}). Se serve davvero, chiedilo "
            f"esplicitamente all'utente. Per i test usa 'php artisan test' "
            f"(SQLite in memoria)."
        )

    # 2. Test runner con override del database.
    is_test_runner = re.search(
        r"(artisan\s+test\b|vendor/bin/pest\b|(?:^|[\s;&|])pest\b|phpunit\b)", cmd
    )
    if not is_test_runner:
        sys.exit(0)

    db_match = re.search(r"\bDB_DATABASE=[\"']?([^\s\"';]+)", cmd)
    db = db_match.group(1) if db_match else None
    has_conn = re.search(r"\bDB_CONNECTION=", cmd) is not None

    if db == DEV_DB:
        deny(
            f"Bloccato: i test usano RefreshDatabase, quindi 'DB_DATABASE={DEV_DB}' "
            f"farebbe migrate:fresh sul database di SVILUPPO cancellandone i dati. "
            f"Usa 'DB_DATABASE=easylab_test', oppure semplicemente "
            f"'php artisan test' (SQLite in memoria)."
        )

    if db is None and has_conn:
        deny(
            f"Bloccato: DB_CONNECTION è impostato senza DB_DATABASE, quindi i test "
            f"userebbero il database di .env ({DEV_DB}, quello di SVILUPPO) e "
            f"RefreshDatabase lo cancellerebbe. Aggiungi 'DB_DATABASE=easylab_test'."
        )

    sys.exit(0)


if __name__ == "__main__":
    main()
