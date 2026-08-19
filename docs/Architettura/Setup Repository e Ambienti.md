🏗️ Setup Repository, Ambienti & CI — Easy Lab

*Convenzioni di repository, strategia di branch/commit, definizione degli ambienti (locale/staging/produzione) e impostazione CI. Copre i task S0.5 (repo + branch + commit), S0.6 (ambienti + provisioning) e S0.7 (CI scheletro) della roadmap. La parte di **provisioning effettivo** (repo privato, ambienti Laravel Cloud, bucket Backblaze B2) richiede i tuoi account e va eseguita a parte: qui resta la **specifica** da seguire.*

> **Stato:** bozza di Sprint 0 (task S0.5–S0.7). Convenzioni e scheletro CI pronti; provisioning da eseguire.

---

## 1. Repository (S0.5)

- **Hosting:** GitHub, repository **privato** `easylab` (o org dedicata). Accesso minimo necessario.
- **Default branch:** `main` = **produzione**, **protetto** (no push diretto; merge solo via Pull Request con CI verde). Il ramo su cui si lavora è **`staging`** (§1.1), che conviene proteggere allo stesso modo: è lui a ricevere le PR di tutti i giorni.
- **Struttura monorepo-light:** app Laravel alla radice; la cartella `docs/` (questa documentazione) versionata insieme al codice.
- **`.gitignore`:** quello standard generato dall'installer Laravel (in S1) + aggiunte: `/.env*` (tranne `.env.example`), `/storage/*.key`, `/public/build`, file IDE.

### 1.1 Strategia di branch — **rivista il 19 Ago 2026**
- **`staging`** = il ramo di lavoro; ogni push → **deploy automatico sull'ambiente di staging** (§3).
- **`main`** = **produzione**. Ci si arriva solo con una PR di **promozione** da `staging`, dopo la verifica sull'ambiente. Mai un commit diretto.
- `feature/<breve-descrizione>` = branch a vita breve per ogni task; **PR verso `staging`**.
- `fix/<...>`, `chore/<...>`, `docs/<...>` per le altre nature di lavoro.

> *La versione precedente di questa sezione diceva «`main` = sempre deployabile → deploy automatico su staging» e «niente long-lived `develop`»: un ramo solo, con la produzione promossa da release taggata. Con `staging` la promozione diventa **un merge visibile e revisionabile** invece di un gesto sul pannello — e il codice che va in produzione è, per costruzione, quello che qualcuno ha già visto girare.*

### 1.2 Convenzioni di commit (Conventional Commits)
Formato: `<tipo>(<scope opz.>): <descrizione imperativa>`.

| Tipo | Quando |
|---|---|
| `feat` | nuova funzionalità |
| `fix` | correzione bug |
| `refactor` | modifica senza cambi di comportamento |
| `test` | aggiunta/modifica test |
| `chore` | build, dipendenze, config |
| `docs` | solo documentazione |
| `perf` | performance |

Esempi: `feat(tenancy): global scope tenant_id su modelli business` · `test(isolation): tenant A non legge dati tenant B`. Scope utili: `tenancy`, `semaforo`, `billing`, `rbac`, `qr`, `docs`.

---

## 2. Ambienti (S0.6)

Tre ambienti, isolati e con credenziali separate. 🔗 Tech Stack §6.

| Ambiente | Dove | Scopo | DB / Redis |
|---|---|---|---|
| **Locale** | Laravel **Herd** (macOS, già in uso) | sviluppo quotidiano | PostgreSQL + Redis locali |
| **Staging** | **Laravel Cloud** (ambiente dedicato) | UAT, test integrazione, demo di sprint | Postgres + Redis gestiti, staging |
| **Produzione** | **Laravel Cloud** (ambiente dedicato) | clienti pilota / go-live | Postgres + Redis gestiti, backup attivi |

> 🔗 **ADR-025** (5 Ago 2026): l'assunzione iniziale era **Forge + droplet DigitalOcean**, mai provisionata. Gli ambienti sono ora **ambienti di Laravel Cloud**, con database e Redis gestiti dalla piattaforma. **La regione va scelta nella UE** per il vincolo GDPR.

### 2.1 Variabili d'ambiente chiave (`.env.example` da mantenere aggiornato)
```
APP_ENV=            # local | staging | production
APP_KEY=            # php artisan key:generate
APP_URL=
DB_CONNECTION=pgsql DB_HOST= DB_PORT=5432 DB_DATABASE= DB_USERNAME= DB_PASSWORD=
REDIS_HOST= REDIS_PASSWORD= REDIS_PORT=6379
QUEUE_CONNECTION=redis  CACHE_STORE=redis
MAIL_MAILER=smtp MAIL_HOST= MAIL_PORT= MAIL_USERNAME= MAIL_PASSWORD=   # ADR-011 — server di posta INTERNO
MAIL_FROM_ADDRESS= MAIL_FROM_NAME="Easy Lab"                          # dominio EasyLab, allineato a SPF/DKIM
FILESYSTEM_DISK=s3                                                    # 🔗 ADR-025 (Backblaze B2)
AWS_ACCESS_KEY_ID= AWS_SECRET_ACCESS_KEY=       # Application Key B2 limitata AL SINGOLO bucket
AWS_DEFAULT_REGION=eu-central-003               # Amsterdam — regione UE (GDPR)
AWS_BUCKET= AWS_ENDPOINT=https://s3.eu-central-003.backblazeb2.com
STRIPE_KEY= STRIPE_SECRET= STRIPE_WEBHOOK_SECRET=                     # S5, Cashier
```
**Segreti:** mai nel repo. In locale `.env`; su Laravel Cloud → variabili d'ambiente dell'ambiente. Stripe in **modalità test** su staging, **live** solo in produzione; **bucket B2 separati** per staging e produzione, così un test non tocca mai i documenti dei clienti.

> Le chiavi B2 usano i nomi `AWS_*` perché è il driver S3 standard di Laravel puntato a un endpoint diverso: non c'è nulla di Amazon coinvolto.

> **Posta in uscita — server interno** (🔗 ADR-011, deciso il 18 Ago 2026). Niente servizio transazionale: le email partono dal mailserver di EasyLab via SMTP autenticato. In locale resta `MAIL_MAILER=log` e le email finiscono in `storage/logs/laravel.log`.
>
> ⚠️ **Da fare sul dominio prima del primo invio a un cliente reale**, perché senza il promemoria arriva nello spam ed è come non averlo mandato: **SPF** (autorizza l'IP di uscita), **DKIM** (firma il messaggio), **DMARC** (dice ai destinatari cosa fare se i primi due falliscono) e **PTR/reverse DNS** dell'IP. `MAIL_FROM_ADDRESS` deve stare sullo stesso dominio che quei record coprono, o l'allineamento DMARC fallisce anche con SPF e DKIM validi.
>
> Il primo avvio dello scheduler va fatto con `easylab:notifica-scadenze --senza-invio`: la procedura è in §3.1.

### 2.2 Provisioning base (da eseguire con i tuoi account)
- **Laravel Cloud:** collega GitHub, crea gli ambienti staging e produzione in **regione UE**, provisiona Postgres e Redis gestiti, configura worker di coda e scheduler. Deploy da Git, niente server da amministrare.
- **Backblaze B2:** bucket **privato** in `eu-central-003` (Amsterdam), uno per ambiente; **Application Key limitata al singolo bucket**, mai la master key (🔗 ADR-025).
- **DPA firmati con entrambi i fornitori prima che arrivino dati reali** — sono sub-responsabili ex art. 28 (🔗 `Privacy GDPR e Registro Trattamenti.md`).
- SSL e dominio gestiti dalla piattaforma.

---

## 3. Pipeline di deploy

**Dal 19 Ago 2026 i rami sono due**, e la differenza è quella fra «provare» e «pubblicare»:

| Ramo | Ambiente | Come ci si arriva |
|---|---|---|
| `staging` | **staging** — deploy automatico a ogni push | `feature/<descrizione>` → PR verso `staging` (CI obbligatoria verde) |
| `main` | **produzione** — deploy **promosso**, mai automatico | PR di promozione `staging` → `main`, dopo la verifica sull'ambiente di staging |

- Mai un commit diretto su `main`: ciò che è in produzione è passato da staging, e questo è l'unico modo per poterlo affermare.
- La CI (`.github/workflows/ci.yml`) gira su **entrambi** i rami — aggiungerlo è stata la prima conseguenza pratica del ramo nuovo: senza, le PR verso staging sarebbero passate senza rete.
- Migrazioni in deploy con `--force` su tutti e due gli ambienti.
- ⚠️ **Staging deve avere risorse SUE**: database, Redis, **bucket B2 separato** e Stripe in modalità test. Un ambiente di prova che scrive sui dati veri non è un ambiente di prova — ed è la stessa lezione dell'incidente del 18 Ago, quando Redis condiviso fra `easylab` ed `easylab_test` ha avvelenato la cache dei permessi del database di sviluppo.
- ⚠️ **Migration distruttive:** il progetto ne ha già in storia (drop di colonne e tabelle popolate, 🔗 ADR-019). Su un deploy automatico girano senza che nessuno guardi: **backup del database verificato prima di promuovere in produzione**, e revisione umana della migration secondo la Policy di Code Review (area rossa).

### 3.1 Prima attivazione dello scheduler scadenze (S5 — 🔗 ADR-011)

Da fare **una volta sola**, nell'ordine, quando le notifiche vanno in un ambiente che ha già dati:

1. `php artisan easylab:notifica-scadenze --senza-invio` — registra gli avvisi **senza notificare nessuno**. Salta questo passo e la prima email conterrà *tutte* le scadenze già aperte: sulla base demo erano **1306 righe**, sul database di sviluppo ci sono 3297 interventi entro soglia. Il comando avvisa dei cambi di stato, e al primo giro non ha memoria: senza questo passo tutto è un cambio.
2. Verificare SPF/DKIM/DMARC e `MAIL_FROM_ADDRESS` (§2.1) — prima che parta la prima email vera, non dopo.
3. Attivare **scheduler** e **worker di coda** nell'ambiente Laravel Cloud (🔗 ADR-025): il cron è già nel codice (`routes/console.php`), non serve alcun crontab.

Dal giorno dopo il digest manda solo le novità.

### 3.2 Comandi di deploy (Laravel Cloud) — cosa gira a ogni release

Da configurare come **deploy/release commands** dell'ambiente, nell'ordine:

```bash
php artisan migrate --force            # solo in avanti; mai fresh/refresh/rollback
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart              # ⚠️ vedi sotto
```

⚠️ **`queue:restart` non è opzionale.** I worker tengono in memoria il codice con cui sono partiti: senza questo comando, dopo un deploy continuano a eseguire le notifiche con la versione **precedente** dell'applicazione, e il sintomo — una mail vecchia mandata da codice nuovo — è fra i più difficili da diagnosticare. Il worker esce alla fine del job in corso e il supervisore lo riavvia aggiornato.

*(`storage:link` non serve: i documenti stanno su Backblaze B2, 🔗 ADR-025.)*

### 3.3 Comandi una-tantum, alla nascita di un ambiente

Nell'ordine, dopo il primo deploy riuscito:

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # bootstrap RBAC
php artisan easylab:provision-tenant "EasyLab" --admin-email=…  # primo Ente + Superadmin/Admin
php artisan easylab:notifica-scadenze --senza-invio             # §3.1: obbligatorio
```

Poi, dal pannello Laravel Cloud: attivare **scheduler** e **queue worker**.

⚠️ Il seeder RBAC è un **bootstrap, non una sincronizzazione**: dal primo seeding in poi la fonte di verità è il DB (🔗 ADR-016 §7). Rilanciarlo dopo aver toccato `config/rbac.php` va fatto sapendo che `firstOrCreate` non rimuove i permessi tolti dalla config — vanno cancellati a mano — e che le personalizzazioni fatte dalla UI di S6 non vanno perse: si confronta ruolo per ruolo **prima**.

### 3.4 Comandi di manutenzione, quando servono

```bash
php artisan permission:cache-reset                    # dopo OGNI modifica a ruoli/permessi
php artisan easylab:lockout {account} --motivo="…"    # blocco per insoluto (ADR-013)
php artisan easylab:lockout {account} --sblocca
php artisan easylab:provision-tenant "Sede" --account={id}   # sede nuova su account esistente
php artisan schedule:list                             # verifica che i cron siano quelli attesi
```

Il resto gira da sé: il digest delle scadenze alle 06:00 di Roma, la rotazione di `avvisi_scadenza` (24 mesi) e delle `notifications` (12 mesi) alle 03:30/03:35.

---

## 4. CI — GitHub Actions (S0.7)

Scheletro reale in [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml). Si attiva quando l'app Laravel atterra in S1 (prima non c'è codice da lint/testare).

**Cosa fa lo scheletro:**
1. Trigger su `push` e `pull_request` verso `staging` e `main` (dal 19 Ago 2026: il ramo di lavoro è staging, e senza il suo trigger le PR passerebbero senza rete).
2. Servizi: **PostgreSQL** + **Redis** (per i feature/isolation test).
3. Step: checkout → setup PHP 8.3 → `composer install` → copia `.env` → `key:generate` → **lint (Pint)** → **test (Pest)**.
4. La PR non è mergeabile se il job fallisce (branch protection §1).

> La suite include da subito i **test di isolamento multi-tenant** (ADR-001) appena introdotti in S2: la CV li esegue ad ogni PR.

---

## 5. Stato dei task S0.5–S0.7

| Task | Specifica | Esecuzione |
|---|---|---|
| S0.5 Repo + branch + commit | ✅ definita qui | ⬜ creare repo privato su GitHub |
| S0.6 Ambienti + provisioning | ✅ definita qui (.env, ambienti) | ⬜ provisioning Laravel Cloud (regione UE) + bucket B2 privati + DPA |
| S0.7 CI scheletro | ✅ `.github/workflows/ci.yml` | ⬜ si attiva con l'app in S1 |

**Definition of Done S0** (parte infra): "repo + CI + ambiente staging raggiungibili" → richiede l'esecuzione del provisioning sopra.
