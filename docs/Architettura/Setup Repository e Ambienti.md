🏗️ Setup Repository, Ambienti & CI — Easy Lab

*Convenzioni di repository, strategia di branch/commit, definizione degli ambienti (locale/staging/produzione) e impostazione CI. Copre i task S0.5 (repo + branch + commit), S0.6 (ambienti + provisioning) e S0.7 (CI scheletro) della roadmap. La parte di **provisioning effettivo** (creare il repo privato, i server Forge/DigitalOcean) richiede i tuoi account e va eseguita a parte: qui resta la **specifica** da seguire.*

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
| **Staging** | **Forge + DigitalOcean** (droplet) | UAT, test integrazione, demo di sprint | DB/Redis dedicati staging |
| **Produzione** | **Forge + DigitalOcean** (droplet) | clienti pilota / go-live | DB/Redis dedicati prod, backup attivi |

### 2.1 Variabili d'ambiente chiave (`.env.example` da mantenere aggiornato)
```
APP_ENV=            # local | staging | production
APP_KEY=            # php artisan key:generate
APP_URL=
DB_CONNECTION=pgsql DB_HOST= DB_PORT=5432 DB_DATABASE= DB_USERNAME= DB_PASSWORD=
REDIS_HOST= REDIS_PASSWORD= REDIS_PORT=6379
QUEUE_CONNECTION=redis  CACHE_STORE=redis
MAIL_MAILER=smtp MAIL_HOST= MAIL_PORT= MAIL_USERNAME= MAIL_PASSWORD=   # ADR-011
FILESYSTEM_DISK=spaces                                                # ADR (storage)
DO_SPACES_KEY= DO_SPACES_SECRET= DO_SPACES_REGION= DO_SPACES_BUCKET= DO_SPACES_ENDPOINT=
STRIPE_KEY= STRIPE_SECRET= STRIPE_WEBHOOK_SECRET=                     # S5, Cashier
```
**Segreti:** mai nel repo. In locale `.env`; su Forge → Environment del sito. Stripe/Spaces in **modalità test** su staging, **live** solo in produzione.

### 2.2 Provisioning base (da eseguire con i tuoi account)
- DigitalOcean: 2 droplet (staging, prod) o 1 droplet con due siti per partire (decisione di costo); **DigitalOcean Spaces** (S3) con bucket privato per i documenti (ADR storage), **region UE** per GDPR (§ vedi `Privacy GDPR e Registro Trattamenti.md`).
- Forge: collega GitHub, crea i due siti, PHP 8.3+, PostgreSQL, Redis; **deploy script** con `composer install`, `php artisan migrate --force`, build asset, cache di config/route.
- SSL automatico (Let's Encrypt) + dominio.

---

## 3. Pipeline di deploy

- **Staging:** merge su `main` → webhook Forge → deploy automatico (migrazioni incluse). Obiettivo roadmap S1: "push → staging" verde.
- **Produzione:** deploy **manuale/promosso** (pulsante Forge o tag release) dopo verifica su staging. Migrazioni in deploy con `--force`.
- **Quick deploy** Forge attivo solo su staging; produzione con conferma esplicita.

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
| S0.6 Ambienti + provisioning | ✅ definita qui (.env, ambienti) | ⬜ provisioning Forge/DigitalOcean + Spaces UE |
| S0.7 CI scheletro | ✅ `.github/workflows/ci.yml` | ⬜ si attiva con l'app in S1 |

**Definition of Done S0** (parte infra): "repo + CI + ambiente staging raggiungibili" → richiede l'esecuzione del provisioning sopra.
