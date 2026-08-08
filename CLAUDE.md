# Easy Lab — istruzioni per Claude

## ⛔ Database: mai test o migrazioni distruttive sul DB di sviluppo

Il database di sviluppo è **`easylab`** su Postgres (vedi `.env`) e contiene dati reali di lavoro.

La suite applica `RefreshDatabase` a tutti i test Feature (`tests/Pest.php`), quindi **eseguire i test con `DB_DATABASE` puntato a `easylab` fa `migrate:fresh` e cancella tutto**. È già successo una volta: un `DB_CONNECTION=pgsql DB_DATABASE=easylab php artisan test` ha svuotato l'intero database di sviluppo, senza backup disponibili.

Regole:

- **Mai** `php artisan test` / `vendor/bin/pest` con `DB_CONNECTION` o `DB_DATABASE` sovrascritti verso `easylab`.
- I test girano su **SQLite in memoria** (forzato da `phpunit.xml`): basta `php artisan test`, senza variabili d'ambiente.
- Per riprodurre un comportamento **specifico di Postgres** (es. confronti fra date, dove SQLite ha semantiche diverse) usare **`DB_DATABASE=easylab_test`**, mai il DB di sviluppo.
- **Mai** `migrate:fresh`, `migrate:refresh`, `db:wipe` o `migrate:rollback` senza che l'utente li abbia chiesti esplicitamente. `php artisan migrate` (solo in avanti) è sicuro.

## Attenzione: SQLite e Postgres non si comportano allo stesso modo sulle date

Su SQLite le colonne `date` sono memorizzate come stringhe `'YYYY-MM-DD 00:00:00'` e i confronti con `'YYYY-MM-DD'` sono **lessicografici**; su Postgres sono vere date. Conseguenza pratica: un confine sbagliato (`<` vs `<=`) su `data_scadenza` **passa in locale e fallisce in CI**, che gira su Postgres. Se una regola dipende da un confine di date, verificarla anche con `DB_DATABASE=easylab_test`.

## Convenzioni del progetto

- **Lingua**: tabelle, colonne, valori enum e classi di dominio in **italiano** (`strumenti`, `data_scadenza`, `Intervento`); namespace, trait, scope e classi di supporto in **inglese** (`BelongsToTenant`, `TenantScope`, `CurrentTenant`).
- **Docblock**: ogni classe cita l'ERD e gli ADR di riferimento (`docs/Architettura/`). Le decisioni non ovvie si motivano nel commento, non solo nel codice.
- **Multi-tenancy** (ADR-001/006/018): nessun ruolo bypassa i global scope; scoping fail-closed. Ogni nuovo modello di business usa `BelongsToTenant` — un meta-test lo verifica.
- **Test**: Pest, descrizioni in inglese e fixture in italiano. Per le aree "rosse" della Policy di Code Review (autorizzazioni, tenancy, migrazioni) servono **test negativi**, non solo il caso felice.
- **Prova di mutazione**: dopo aver scritto una guardia, verificarla rompendo il codice apposta e controllando che il test giusto diventi rosso. Assicurarsi che la mutazione sia stata **davvero applicata** (un `assert` che il pattern esista): è già capitato di "verificare" con replace che non sostituivano nulla.
- **Commit**: Conventional Commits con lo sprint come scope (`feat(s3): ...`). Branch `feature/<descrizione>`, PR verso `main`.

## Verifiche prima di dire "fatto"

- `php artisan test` (suite intera) e `vendor/bin/pint --dirty`.
- `npm run build` se sono state introdotte classi Tailwind nuove.
- **Le migration vanno applicate anche al DB di sviluppo** (`php artisan migrate`): i test girano su SQLite ricreato da zero, quindi una migration mancante su Postgres non emerge dalla suite — è già successo.
