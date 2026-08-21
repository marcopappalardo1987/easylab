🏗️ Tech Stack Canvas: Easy Lab

**Documento di Architettura Tecnologica**

*Obiettivo: Sviluppo rapido, scalabile e mantenibile per rilascio V1 a fine Settembre.*

1. Core Framework (Backend)

Il motore dell'applicazione, scelto per la sua robustezza nella gestione di architetture SaaS e rapidità di sviluppo.

- **Framework:** Laravel 13
- **Linguaggio:** PHP 8.2 o superiore
- **Perché:** Ecosistema completo, sicurezza integrata, gestione nativa delle code (fondamentale per l'invio delle "email del futuro" e scadenze) e librerie SaaS già pronte.

2. Frontend & UI (L'Esperienza Utente)

Abbiamo scelto il **TALL Stack**. Questo permette di avere la reattività di una Single Page Application (SPA) senza la complessità di dover sviluppare e mantenere delle API separate.

- Tailwind CSS: Per uno styling rapido, responsivo (mobile-first per i tecnici con QR code) e coerente.
- Alpine.js: Per le interazioni UI leggere lato client (es. apertura modali, dropdown, toggle semafori).
- Laravel Livewire 4: Il cuore dinamico. Permette di creare tabelle filtrabili in tempo reale (es. ricerca del ricambio specifico su tutte le macchine) scrivendo solo codice PHP/Blade.
- Laravel Blade: Il motore di templating pulito e sicuro per le viste.

3. Gestione Dati e Database

La struttura gerarchica (Ente -> Dipartimento -> Sottolaboratorio -> Strumento) richiede un database relazionale solido.

- **Database:** PostgreSQL (consigliato per la gestione complessa di alberi gerarchici) o MySQL 8.0.
- **ORM:** Eloquent (Nativo di Laravel) per gestire le relazioni nidificate in modo elegante (es. $cliente->laboratori()->strumenti()).
- **Caching:** Redis (Fondamentale per memorizzare le sessioni utente, velocizzare il caricamento della dashboard principale e gestire le code delle email).

4. Ecosistema SaaS & Billing

Gli strumenti per gestire i pagamenti, gli abbonamenti e gli accessi in modo automatizzato.

- **Motore Pagamenti:** Stripe
- **Integrazione Laravel:** Laravel Cashier (Stripe) — **installato il 21 Ago 2026: `laravel/cashier v16.7.0` + `stripe/stripe-php v20.3.1`**. Il vincolo `^16.7` non è una preferenza: il supporto a Illuminate `^13` entra in Cashier 16.5.0, la 16.4 si ferma a `^12`. Installato **senza `--with-dependencies`** (stessa lezione del framework, sotto): 4 pacchetti aggiunti, zero update, `guzzlehttp/guzzle` fermo a 7.15.3 — che conta, perché ci sta sotto l'SDK AWS dei documenti B2.
- **Gestione Portale Clienti:** Stripe Hosted Billing Portal (Per far scaricare le fatture e gestire le carte ai clienti senza scrivere una riga di codice lato UI).

5. Pacchetti Core & Moduli Consigliati

Evitiamo di reinventare la ruota. Ecco le librerie standard dell'ecosistema Laravel perfette per i nostri requisiti:

- **Gestione Permessi & Ruoli:** spatie/laravel-permission (Lo standard assoluto per gestire Super Admin, Admin Cliente, Responsabile Reparto).
- **Impersonificazione:** lab404/laravel-impersonate (Pacchetto plug&play per far accedere il Super Admin come qualsiasi altro utente con 1 click).
- **QR Code Generator:** ~~simplesoftwareio/simple-qrcode~~ → **`bacon/bacon-qr-code`** (corretto il 15 Ago 2026, attuando ADR-003). Il wrapper indicato qui è fermo a `bacon ^2.0` mentre il progetto ha già `bacon v3.1.1`, **installata da Fortify** per il QR della verifica in due passaggi: usarlo avrebbe richiesto di retrocedere una dipendenza dell'autenticazione in cambio di una comodità di sintassi. Si usa direttamente la libreria sottostante, con lo stesso idioma di `TwoFactorAuthenticatable` (`SvgImageBackEnd` + `ImageRenderer` + `Writer`) — zero dipendenze nuove. Vedi `App\Support\QrStrumento`.
> **Aggiornamento del framework del 19 Ago 2026 — `laravel/framework` v13.16.1 → v13.26.1.** Fatto per un requisito preciso: le **managed queue** di Laravel Cloud richiedono almeno la 13.19, e con la 13.16 non sarebbero state attivabili (🔗 `Setup Repository e Ambienti.md` §3.3).
>
> ⚠️ **Nota di metodo, pagata sul posto.** Il primo tentativo è stato `composer update laravel/framework --with-dependencies`: ha proposto **32 pacchetti**, fra cui `guzzlehttp/guzzle` da **7 a 8** — un major, e Guzzle sta sotto l'SDK AWS che serve ai documenti su B2 (🔗 ADR-025/026). Un salto che i test **non avrebbero potuto smentire**, perché lo storage nei test è `Storage::fake()` e il client HTTP vero non viene mai esercitato. Rifatto senza `--with-dependencies`: **un solo pacchetto aggiornato**, stesso risultato, superficie di rischio ridotta a ciò che serviva. Suite **784 verdi** su SQLite e Postgres, `composer audit` pulito.

> **Manutenzione dipendenze del 17 Ago 2026.** `composer audit` segnalava **17 avvisi** su tre pacchetti — `guzzlehttp/guzzle` (9, di cui uno *high*: bypass di controlli host, CVE-2026-69246), `guzzlehttp/psr7` (2) e `league/commonmark` (6) — tutti **preesistenti**, non introdotti da dompdf. Risolti con un update mirato: 7 pacchetti, tutti minor/patch dentro lo stesso major, nessuna aggiunta né rimozione. Suite 661 verdi su entrambi i driver dopo l'aggiornamento, `composer audit` pulito. Vale come promemoria di metodo: l'audit va guardato **quando lo si vede**, perché era lì da settimane e nessuno lo aveva letto.

- **Esportazione PDF:** **`barryvdh/laravel-dompdf`** — scelto il 17 Ago 2026, 🔗 **ADR-031**: PHP puro, nessun Chromium da installare e aggiornare su Laravel Cloud (ADR-025). L'alternativa `spatie/laravel-pdf` rende come un browser, ma un foglio A4 non ha bisogno del CSS moderno che quella fedeltà serve — e un PDF non è responsive.
- **Task Scheduling:** Cron Job nativi di Laravel (Per il controllo giornaliero delle scadenze garanzie e invio allerte rosse).

6. Infrastruttura e Rilascio (Deploy)

L'ambiente dove "vivrà" l'applicazione, ottimizzato per zero pensieri lato sistemistico.

- **Piattaforma applicativa:** **Laravel Cloud** — deploy da Git, Postgres e Redis/Valkey gestiti, worker di coda e scheduler inclusi (🔗 ADR-025). *Sostituisce l'assunzione iniziale "Laravel Forge su droplet DigitalOcean", che era un default mai eseguito.*
- **Storage documenti:** **Backblaze B2** (S3-compatible), bucket privato in **regione UE** — Amsterdam `eu-central-003` (🔗 ADR-025/009).
- **Versionamento:** GitHub o GitLab (Repository privato per il codice sorgente).

> **Sub-responsabili GDPR.** Laravel Cloud e Backblaze trattano entrambi dati dei clienti e vanno nel registro dei trattamenti, con DPA firmati **prima** del go-live (🔗 `Privacy GDPR e Registro Trattamenti.md`). La scelta di B2 al posto dell'object storage incluso in Laravel Cloud aggiunge di proposito un fornitore, in cambio di uno storage circa 3× più economico: il ragionamento completo è in ADR-025.

**Note per lo Sviluppatore:**

- **Strategia Multi-tenant: Single-Database con Row-Level Scoping** (decisione architetturale — vedi `Decisioni Architetturali.md` ADR-001). Un unico database condiviso; l'isolamento è garantito a livello applicativo tramite Global Scope di Eloquent.
- Implementare un **Global Scope** di Eloquent per la logica Multi-tenant. Ogni volta che un utente "Cliente" fa una query, il Global Scope filtrerà automaticamente i dati mostrando SOLO quelli associati al suo tenant_id (o cliente_id), garantendo che non ci siano mai fughe di dati tra laboratori diversi.
- **Gerarchia a due livelli (rivenditori):** lo scoping è duplice. Ogni record "di business" porta sia un `tenant_id` (il laboratorio/ente finale) sia un `reseller_id` (l'Admin/rivenditore proprietario, NULL se cliente diretto EasyLab). Il Global Scope filtra in base al ruolo: il Tenant vede solo il proprio `tenant_id`; il Reseller vede tutti i `tenant_id` sotto il proprio `reseller_id`; il Superadmin EasyLab bypassa lo scope e vede tutto (necessario per le dashboard globali/MRR).
- **Test di isolamento obbligatorio:** prevedere fin da subito un test automatico che verifichi "il tenant A non può mai leggere dati del tenant B" — è il test più critico dell'intero SaaS.
- **Sicurezza QR Code (ADR-003):** le rotte degli strumenti raggiunte da QR devono usare **URL firmate** (`URL::signedRoute`) + middleware `signed` + Policy di autorizzazione. Il QR rimanda al login se l'utente non è autenticato e mostra la scheda solo previa verifica dei permessi tramite Global Scope. Mai una scheda accessibile senza autenticazione.
