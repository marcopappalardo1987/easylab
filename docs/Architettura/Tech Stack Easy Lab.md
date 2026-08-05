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
- **Integrazione Laravel:** Laravel Cashier (Stripe)
- **Gestione Portale Clienti:** Stripe Hosted Billing Portal (Per far scaricare le fatture e gestire le carte ai clienti senza scrivere una riga di codice lato UI).

5. Pacchetti Core & Moduli Consigliati

Evitiamo di reinventare la ruota. Ecco le librerie standard dell'ecosistema Laravel perfette per i nostri requisiti:

- **Gestione Permessi & Ruoli:** spatie/laravel-permission (Lo standard assoluto per gestire Super Admin, Admin Cliente, Responsabile Reparto).
- **Impersonificazione:** lab404/laravel-impersonate (Pacchetto plug&play per far accedere il Super Admin come qualsiasi altro utente con 1 click).
- **QR Code Generator:** simplesoftwareio/simple-qrcode (Per generare i codici da applicare fisicamente alle macchine).
- **Esportazione PDF:** barryvdh/laravel-dompdf o spatie/laravel-pdf (Per generare i report di fine intervento in PDF).
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
