# Easy Lab — istruzioni per Claude

## ⛔ Database: mai test o migrazioni distruttive sul DB di sviluppo

Il database di sviluppo è **`easylab`** su Postgres (vedi `.env`) e contiene dati reali di lavoro.

La suite applica `RefreshDatabase` a tutti i test Feature (`tests/Pest.php`), quindi **eseguire i test con `DB_DATABASE` puntato a `easylab` fa `migrate:fresh` e cancella tutto**. È già successo una volta: un `DB_CONNECTION=pgsql DB_DATABASE=easylab php artisan test` ha svuotato l'intero database di sviluppo, senza backup disponibili.

Regole:

- **Mai** `php artisan test` / `vendor/bin/pest` con `DB_CONNECTION` o `DB_DATABASE` sovrascritti verso `easylab`.
- I test girano su **SQLite in memoria** (forzato da `phpunit.xml`): basta `php artisan test`, senza variabili d'ambiente.
- Per riprodurre un comportamento **specifico di Postgres** (es. confronti fra date, dove SQLite ha semantiche diverse) usare **`DB_DATABASE=easylab_test`**, mai il DB di sviluppo.
- ⚠️ **Ogni comando manuale verso `easylab_test` deve portare anche `CACHE_STORE=array`.** Redis è **condiviso** fra i due database, e la cache dei permessi di spatie (`spatie.permission.cache`) salva gli **id** di ruoli e permessi: un `db:seed` o un check di permesso eseguito contro `easylab_test` con la cache di default scrive nella chiave condivisa gli id del DB di test, e da quel momento **l'app di sviluppo nega tutto a tutti** (menù vuoti, 403 ovunque) pur avendo il proprio DB intatto. È già successo il 18 Ago 2026: il Superadmin «non vedeva più nulla» dopo un seeding di prova su `easylab_test`. Rimedio: `php artisan permission:cache-reset` (dal contesto di sviluppo). `php artisan test` non c'entra: `phpunit.xml` forza già `CACHE_STORE=array`.
- **Mai** `migrate:fresh`, `migrate:refresh`, `db:wipe` o `migrate:rollback` senza che l'utente li abbia chiesti esplicitamente. `php artisan migrate` (solo in avanti) è sicuro.

## Attenzione: SQLite e Postgres non si comportano allo stesso modo sulle date

Su SQLite le colonne `date` sono memorizzate come stringhe `'YYYY-MM-DD 00:00:00'` e i confronti con `'YYYY-MM-DD'` sono **lessicografici**; su Postgres sono vere date. Conseguenza pratica: un confine sbagliato (`<` vs `<=`) su `data_scadenza` **passa in locale e fallisce in CI**, che gira su Postgres. Se una regola dipende da un confine di date, verificarla anche con `DB_DATABASE=easylab_test`.

## Convenzioni del progetto

- **Lingua**: tabelle, colonne, valori enum e classi di dominio in **italiano** (`strumenti`, `data_scadenza`, `Intervento`); namespace, trait, scope e classi di supporto in **inglese** (`BelongsToTenant`, `TenantScope`, `CurrentTenant`).
- **Docblock**: ogni classe cita l'ERD e gli ADR di riferimento (`docs/Architettura/`). Le decisioni non ovvie si motivano nel commento, non solo nel codice.
- **Multi-tenancy** (ADR-001/006/018): nessun ruolo bypassa i global scope; scoping fail-closed. Ogni nuovo modello di business usa `BelongsToTenant` — un meta-test lo verifica.
- **Test**: Pest, descrizioni in inglese e fixture in italiano. Per le aree "rosse" della Policy di Code Review (autorizzazioni, tenancy, migrazioni) servono **test negativi**, non solo il caso felice.
- **Prova di mutazione**: dopo aver scritto una guardia, verificarla rompendo il codice apposta e controllando che il test giusto diventi rosso. Assicurarsi che la mutazione sia stata **davvero applicata** (un `assert` che il pattern esista): è già capitato di "verificare" con replace che non sostituivano nulla.
- **Commit**: Conventional Commits con lo sprint come scope (`feat(s3): ...`).
- **Branch (dal 21 Ago 2026)**: si lavora **direttamente su `staging`**, e questa cartella resta puntata lì. Niente più `feature/*` né PR per il lavoro ordinario. **`main` è produzione**: ci si arriva solo **promuovendo** staging con una PR dedicata, dopo la verifica sull'ambiente. Mai un commit diretto su `main`.
  - ⚠️ **Conseguenza da tenere presente**: ogni push su `staging` **deploya**, e la CI gira *dopo* invece di fare da cancello. Un commit rotto arriva sull'ambiente prima che qualcuno lo sappia — quindi suite verde **in locale** prima di pushare, non dopo.
  - *(Prima, dal 19 Ago: `feature/*` → PR verso `staging`. Cambiato perché il giro della PR non pagava il proprio costo su un progetto a un solo sviluppatore che rivede da sé.)*

## Comandi di piattaforma (console, cross-tenant)

Vivono in console perché l'amministrazione degli account è cross-tenant per natura e la Dashboard Superadmin è materia di S6 (ADR-018/032).

- `easylab:provision-tenant {nome} [--account=]` — crea Ente, Account e Admin, e lo invita via email.
- `easylab:lockout {account} [--sblocca] --motivo=` — la leva **manuale** del blocco per insoluto.
- `easylab:abbona {account} [--piano=saas]` — attiva l'abbonamento su Stripe. ⚠️ Crea oggetti di fatturazione **veri**: in produzione chiede conferma.
- `easylab:notifica-scadenze [--senza-invio]` — il digest giornaliero. Il **primo run in produzione va fatto con `--senza-invio`**.

## Verifiche prima di dire "fatto"

- `php artisan test` (suite intera) e `vendor/bin/pint --dirty`.
- `npm run build` se sono state introdotte classi Tailwind nuove.
- **Le migration vanno applicate anche al DB di sviluppo** (`php artisan migrate`): i test girano su SQLite ricreato da zero, quindi una migration mancante su Postgres non emerge dalla suite — è già successo.
- **Le modifiche a `config/rbac.php` vanno riseminate** (`php artisan db:seed --class=RolesAndPermissionsSeeder`): quella config è il **bootstrap**, e dopo il primo seeding la fonte di verità è il DB (ADR-016 §7). Nessun test se ne accorgerà mai, perché la suite ricrea il DB dalla config, che è già corretta. È la stessa forma dell'errore della riga sopra, spostata dalle migration al seeding: **ciò che vive nel DB e non nello schema non viene allineato da `php artisan migrate`.** Prima di riseminare, confrontare ruolo per ruolo DB e config per accertarsi che non si perdano personalizzazioni; il seeder non cancella i permessi che non conosce più (usa `firstOrCreate`), quindi le righe orfane vanno rimosse a parte. *È già successo: i permessi `letture_contaore.*`, tolti dalla config in S3-bis, sono rimasti su quattro ruoli del DB di sviluppo fino all'8 Ago 2026.*
