# Easy Lab — istruzioni per Codex

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

⛔ **E non solo sulle date: anche sull'ORDINE.** A parità di chiave di ordinamento l'ordine fra due pagine è una **proprietà del motore** — SQLite scansiona in modo stabile, Postgres può riordinare i pari fra la query di pagina 1 e quella di pagina 2, e una riga esce da **entrambe**. Ogni `orderBy` paginato vuole quindi un **tie-break su una colonna unica** (l'id). Trovato il 25 Ago 2026 girando la suite su `easylab_test`: 46 righe raccolte su 47, con SQLite verde.

⚠️ E la prova va fatta **sull'SQL, non sui dati**: togliendo il tie-break la suite resta verde su entrambi i driver a seconda del piano scelto. Un test che coglie un difetto una volta su tre non è una rete, è un aneddoto — stessa conclusione già raggiunta sul tie-break del registro di audit.

Su SQLite le colonne `date` sono memorizzate come stringhe `'YYYY-MM-DD 00:00:00'` e i confronti con `'YYYY-MM-DD'` sono **lessicografici**; su Postgres sono vere date. Conseguenza pratica: un confine sbagliato (`<` vs `<=`) su `data_scadenza` **passa in locale e fallisce in CI**, che gira su Postgres. Se una regola dipende da un confine di date, verificarla anche con `DB_DATABASE=easylab_test`.

## Convenzioni del progetto

- **Lingua**: tabelle, colonne, valori enum e classi di dominio in **italiano** (`strumenti`, `data_scadenza`, `Intervento`); namespace, trait, scope e classi di supporto in **inglese** (`BelongsToTenant`, `TenantScope`, `CurrentTenant`).
- **Docblock**: ogni classe cita l'ERD e gli ADR di riferimento (`docs/Architettura/`). Le decisioni non ovvie si motivano nel commento, non solo nel codice.
- **Multi-tenancy** (ADR-001/006/018): nessun ruolo bypassa i global scope; scoping fail-closed. Ogni nuovo modello di business usa `BelongsToTenant` — un meta-test lo verifica.
- **Test**: Pest, descrizioni in inglese e fixture in italiano. Per le aree "rosse" della Policy di Code Review (autorizzazioni, tenancy, migrazioni) servono **test negativi**, non solo il caso felice.
- **Prova di mutazione**: dopo aver scritto una guardia, verificarla rompendo il codice apposta e controllando che il test giusto diventi rosso. Assicurarsi che la mutazione sia stata **davvero applicata** (un `assert` che il pattern esista): è già capitato di "verificare" con replace che non sostituivano nulla.
  - ⛔ **Mai `git checkout -- <file>` per ripristinare**: se il lavoro non è ancora committato, quel comando non annulla la mutazione — **annulla il blocco**. È successo due volte il 25 Ago 2026, a due agenti diversi nello stesso giro. Si ripristina da una copia (`cp file /tmp/… && … && cp /tmp/… file`), e si **committa ogni blocco appena chiude**, che è la sola rete vera.
  - ⚠️ Dopo una mutazione su un file **Blade** serve `php artisan view:clear`, o la cache resta alla versione mutata e la misura successiva è falsa.
- ⛔ **`expect()->toContain()` è VARIADICO, non accetta un messaggio.** Un testo passato come secondo argomento diventa un **secondo ago**, e se non compare mai nell'oggetto sotto esame l'asserzione negativa è soddisfatta **sempre**: il test non può fallire. È già costato un guardrail di privacy interamente vuoto (25 Ago 2026, ADR-020 sulla dashboard), e lo stesso errore è stato **rifatto tre ore dopo** in un altro file. La spiegazione va nel nome del test o in un commento, mai lì dentro.
- ⛔ **«Free», «gratis» e «gratuito» non si scrivono**, in nessun testo che l'applicazione mostra (ADR-050, 10 Ott 2026): il piano senza canone è in **comodato d'uso**. `free` resta il codice del piano e `gratuito` il nome della colonna, che il cliente non vede. `LessicoCommercialeTest` legge viste, sorgenti e guide e diventa rosso alla prima frase che le contiene; il listino rifiuta un'etichetta che ne porta una.
- **«Laboratori», non «Anagrafica»; «laboratorio», non «dipartimento»** (ADR-053, 10 Ott 2026): sono i nomi che l'applicazione mostra, e li dà `TipoUnitaOrganizzativa` (`SEZIONE`, `etichetta()`, `plurale()`). `dipartimento` resta il valore della colonna `tipo`, `anagrafica.*` il nome delle rotte e `App\Livewire\Anagrafica` il namespace: identificatori. La linguetta «Anagrafica» della scheda di una macchina è un'altra cosa, e resta. `LessicoCommercialeTest` diventa rosso se una delle due parole torna in una vista, in una frase o in una guida.
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
  - ⚠️ **Da S6 le sorgenti di Tailwind sono dichiarate a mano** (`app.css` importa con `source(none)`), quindi una classe scritta in una cartella nuova alla radice **non genera nulla** finché non si aggiunge un `@source`. Non dà errore: dà una pagina senza colore. `SorgentiTailwindGuardrailTest` lo rende rosso, e spiega nel messaggio dove intervenire.
  - Il motivo per cui non ci si affida più alla scoperta automatica: leggeva anche `tests/`, quindi le classi delle fixture — comprese quelle scritte apposta *sbagliate* per le prove di mutazione — finivano nel CSS dei clienti; e leggeva `storage/framework/views`, cioè le viste **compilate**, rendendo il bundle diverso a seconda che si fosse lanciata la suite prima del build.
- **Le migration vanno applicate anche al DB di sviluppo** (`php artisan migrate`): i test girano su SQLite ricreato da zero, quindi una migration mancante su Postgres non emerge dalla suite — è già successo.
- **Le modifiche a `config/rbac.php` vanno riseminate** (`php artisan db:seed --class=RolesAndPermissionsSeeder`): quella config è il **bootstrap**, e dopo il primo seeding la fonte di verità è il DB (ADR-016 §7). Nessun test se ne accorgerà mai, perché la suite ricrea il DB dalla config, che è già corretta. È la stessa forma dell'errore della riga sopra, spostata dalle migration al seeding: **ciò che vive nel DB e non nello schema non viene allineato da `php artisan migrate`.**
  - ⛔ **Ma da S6 quel comando è un'arma carica, e va letto per intero prima di lanciarlo.** `syncPermissions()` **detacha tutto e riattacca dalla config**: non fonde, non preserva. Dal rilascio dell'editor permessi (`/piattaforma/ruoli`) la matrice si modifica **a runtime**, quindi riseminare **cancella ogni personalizzazione** — in *entrambe* le direzioni: un permesso concesso dalla UI sparisce, e uno revocato dalla UI **torna**. Un cliente a cui era stato tolto qualcosa per contratto se lo ritrova.
  - **Il confronto ruolo per ruolo non si fa più a mano: si guarda `/piattaforma/ruoli`.** Le celle in cui DB e config non concordano portano il marcatore «personalizzato» coi **due valori affiancati**, c'è un interruttore «mostra solo le differenze» e il conteggio in cima. Costa zero query. È il posto in cui si decide *se* riseminare — e da cui si riparte, a mano, per rimettere ciò che serviva.
  - Il seeder si difende da parte sua: **stampa il diff** (`+ concessi dal reset` / `- revocati dal reset`) *prima* di sincronizzare e lascia una riga nel registro di audit, senza causer. **Non chiede conferma**, e non deve impararlo: `$this->seed()` mocka `OutputStyle`, quindi un `confirm()` lì dentro fa **esplodere le **91 classi di test** che seminano** — e il diff non è la guardia, perché su un DB appena creato i ruoli non esistono e il diff è *massimo*. Sul bootstrap il comando resta muto apposta.
  - **Non tutta `config/rbac.php` è la matrice**: il seeder legge **solo** `permissions` e `roles`, quindi `protected_roles`, `two_factor_required_roles` e — ⚠️ soprattutto — **`locked`** non sono lette, quindi cambiarle **non richiede** un riseeding — e riseminare «per riflesso» dopo averle toccate distruggerebbe la matrice di runtime per niente. ⚠️ `locked` è la voce che conta: allargare il set bloccato è la modifica che arriverà con la Dashboard Developer, e chi la fa legge questa riga — se non ci trovasse `locked` fra le esenzioni, riseminerebbe, cioè farebbe esattamente il gesto distruttivo che questo punto esiste per impedire.
  - Il seeder non cancella i permessi che non conosce più (usa `firstOrCreate`), quindi le righe orfane vanno rimosse a parte — le stacca però da ogni ruolo. *È già successo: i permessi `letture_contaore.*`, tolti dalla config in S3-bis, sono rimasti su quattro ruoli del DB di sviluppo fino all'8 Ago 2026.* La striscia in fondo a `/piattaforma/ruoli` li elenca.
