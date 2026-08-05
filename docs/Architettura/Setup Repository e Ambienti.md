🏗️ Setup Repository, Ambienti & CI — Easy Lab

*Convenzioni di repository, strategia di branch/commit, definizione degli ambienti (locale/staging/produzione) e impostazione CI. Copre i task S0.5 (repo + branch + commit), S0.6 (ambienti + provisioning) e S0.7 (CI scheletro) della roadmap. La parte di **provisioning effettivo** (repo privato, ambienti Laravel Cloud, bucket Backblaze B2) richiede i tuoi account e va eseguita a parte: qui resta la **specifica** da seguire.*

> **Stato:** bozza di Sprint 0 (task S0.5–S0.7). Convenzioni e scheletro CI pronti; provisioning da eseguire.

---

## 1. Repository (S0.5)

- **Hosting:** GitHub, repository **privato** `easylab` (o org dedicata). Accesso minimo necessario.
- **Default branch:** `main` — **protetto** (no push diretto; merge solo via Pull Request con CI verde).
- **Struttura monorepo-light:** app Laravel alla radice; la cartella `docs/` (questa documentazione) versionata insieme al codice.
- **`.gitignore`:** quello standard generato dall'installer Laravel (in S1) + aggiunte: `/.env*` (tranne `.env.example`), `/storage/*.key`, `/public/build`, file IDE.

### 1.1 Strategia di branch (GitHub Flow leggero, adatto a solo+AI)
- `main` = sempre deployabile; ogni merge su `main` → **deploy automatico su staging** (§3).
- `feature/<breve-descrizione>` = branch a vita breve per ogni task; PR verso `main`.
- `fix/<...>`, `chore/<...>`, `docs/<...>` per le altre nature di lavoro.
- **Produzione:** non da branch separato ma da **release taggata** (`v*`) o deploy manuale promosso da staging (§3). Niente long-lived `develop`.

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
MAIL_MAILER=smtp MAIL_HOST= MAIL_PORT= MAIL_USERNAME= MAIL_PASSWORD=   # ADR-011
FILESYSTEM_DISK=s3                                                    # 🔗 ADR-025 (Backblaze B2)
AWS_ACCESS_KEY_ID= AWS_SECRET_ACCESS_KEY=       # Application Key B2 limitata AL SINGOLO bucket
AWS_DEFAULT_REGION=eu-central-003               # Amsterdam — regione UE (GDPR)
AWS_BUCKET= AWS_ENDPOINT=https://s3.eu-central-003.backblazeb2.com
STRIPE_KEY= STRIPE_SECRET= STRIPE_WEBHOOK_SECRET=                     # S5, Cashier
```
**Segreti:** mai nel repo. In locale `.env`; su Laravel Cloud → variabili d'ambiente dell'ambiente. Stripe in **modalità test** su staging, **live** solo in produzione; **bucket B2 separati** per staging e produzione, così un test non tocca mai i documenti dei clienti.

> Le chiavi B2 usano i nomi `AWS_*` perché è il driver S3 standard di Laravel puntato a un endpoint diverso: non c'è nulla di Amazon coinvolto.

### 2.2 Provisioning base (da eseguire con i tuoi account)
- **Laravel Cloud:** collega GitHub, crea gli ambienti staging e produzione in **regione UE**, provisiona Postgres e Redis gestiti, configura worker di coda e scheduler. Deploy da Git, niente server da amministrare.
- **Backblaze B2:** bucket **privato** in `eu-central-003` (Amsterdam), uno per ambiente; **Application Key limitata al singolo bucket**, mai la master key (🔗 ADR-025).
- **DPA firmati con entrambi i fornitori prima che arrivino dati reali** — sono sub-responsabili ex art. 28 (🔗 `Privacy GDPR e Registro Trattamenti.md`).
- SSL e dominio gestiti dalla piattaforma.

---

## 3. Pipeline di deploy

- **Staging:** merge su `main` → deploy automatico di Laravel Cloud (migrazioni incluse). Obiettivo roadmap S1: "push → staging" verde.
- **Produzione:** deploy **promosso** dopo verifica su staging, mai automatico. Migrazioni in deploy con `--force`.
- ⚠️ **Migration distruttive:** il progetto ne ha già in storia (drop di colonne e tabelle popolate, 🔗 ADR-019). Su un deploy automatico girano senza che nessuno guardi: **backup del database verificato prima di promuovere in produzione**, e revisione umana della migration secondo la Policy di Code Review (area rossa).

---

## 4. CI — GitHub Actions (S0.7)

Scheletro reale in [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml). Si attiva quando l'app Laravel atterra in S1 (prima non c'è codice da lint/testare).

**Cosa fa lo scheletro:**
1. Trigger su `push` e `pull_request` verso `main`.
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
