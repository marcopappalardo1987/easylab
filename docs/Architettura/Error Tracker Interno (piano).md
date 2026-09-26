🐞 Error Tracker Interno — Easy Lab (piano)

*Specifica del sistema di error tracking **interno** (in-house, "stile Sentry") che sostituisce un servizio in abbonamento. Copre il task S1.11 (`[STRETCH]` error tracking) costruendolo invece di acquistarlo. Questo file è il riferimento durevole del piano: l'implementazione va completata **prima del deploy** (Sprint 1 · punti 8/9).*

> # ⛔️ **SUPERATO — non è più la specifica di niente.** *(24 Ago 2026)*
>
> **Il sistema è stato costruito, ma NON come questo file lo descrive.** La
> decisione vive in **🔗 ADR-017**; questo documento si conserva come storia
> di come ci si è arrivati, e va letto solo per quello.
>
> ⚠️ Sette file di codice lo citano ancora con un 🔗 in un docblock. Chi ci
> arriva da lì deve sapere che **almeno otto punti dicono il falso** rispetto a
> ciò che esiste davvero:
>
> | Qui c'è scritto | La realtà |
> |---|---|
> | tabelle `error_issues` / `error_occurrences` | `errori` / `occorrenze_errore` |
> | `App\Support\ErrorTracker::capture()` | `App\Support\Errori\CatturaErrori::cattura()` |
> | `config/errors.php` | il blocco `errori` di `config/easylab.php` |
> | permesso **nuovo** `system.errors.view` («56→57, locked 9→10») | si riusa `system.logs.view`, che esisteva già ed era inutilizzato. **Nessun permesso nuovo**: il catalogo resta a 54 e il set bloccato a 7 |
> | rotte `/system/errors` + voce di sidebar dedicata | `/piattaforma/errori`, quarta voce della sub-nav di piattaforma |
> | fingerprint su **un** frame | **due** frame (origine + chiamante applicativo), o cento cause diverse collassano in una issue sola |
> | alert **sincrona**, «no worker» | in coda, e dentro un job che **non lancia mai** — perché il worker riporta da sé l'eccezione del job, e un alert fallito diventava un errore nuovo |
> | comando `errors:prune --days=90` «schedulazione al deploy» | **nessun comando nuovo**: si aggancia al `model:prune` già schedulato. Un comando da ricordarsi di attivare è precisamente il difetto T6 che l'ADR dichiara di evitare |
>
> 🔴 E soprattutto la verifica di §5 — **«Admin → 403; Superadmin → 200»** — è
> il **contrario** della decisione centrale: la pagina è del **solo Developer**,
> e il Superadmin ne è escluso. È la prima schermata del progetto che lui non
> può aprire, e ciò che lo tiene informato è il registro di audit.

*(Testo originale del piano S1, conservato sotto.)*

> **Stato:** ~~pianificato — da implementare prima del go-live~~. Errori **backend/PHP**; la cattura di errori JS lato browser è un'estensione futura.

---

## 1. Perché in-house (decisione)

- **No abbonamento:** Sentry SaaS / Flare costano o mandano i dati a terzi. Un servizio esterno che riceve gli errori (che possono contenere email/dati dei clienti) diventerebbe un **sub-responsabile GDPR** (DPA + registro trattamenti).
- **Dati in casa:** costruendolo dentro Easy Lab i dati restano nella nostra infrastruttura (UE), semplificando la compliance.
- **Costo:** zero licenze; gira sull'infrastruttura che avremo comunque. In futuro, se servisse una dashboard più ricca, l'alternativa open-source self-hosted è **GlitchTip** (Sentry-compatibile, leggero) — non necessaria per la V1.
- Va formalizzato come **ADR-017** (build-vs-buy) in `Decisioni Architetturali.md`.

## 2. Architettura (mutuata da Sentry)

- **Issue** = errore **raggruppato** per `fingerprint` (con conteggio occorrenze, first/last seen, stato).
- **Occurrence** = singolo avvenimento, con tutto il contesto (stack trace, url, utente, ip, input).
- **Fingerprint** = `sha1(classe_eccezione + '|' + primo frame applicativo file:line)` (fallback `getFile():getLine()`): errori uguali si fondono in un'unica issue.
- Strumento **di piattaforma** (Developer/Superadmin), **non** tenant-scoped (come l'audit log): quando in S2 arriverà il Global Scope multi-tenant, questi modelli **non** lo adottano.

## 3. Cosa costruiamo (checklist pre-deploy)

- [ ] **Migrazioni:** `error_issues` (fingerprint unique, exception_class, message, file, line, status open|resolved|ignored, occurrences, first/last_seen_at, resolved_at, resolved_by) + `error_occurrences` (issue_id, message, stack_trace, url, method, status_code, user_id, ip, user_agent, input json **sanitizzato**, environment, occurred_at).
- [ ] **Modelli** `ErrorIssue` / `ErrorOccurrence` (+ helper markResolved/markIgnored/reopen).
- [ ] **Servizio `App\Support\ErrorTracker::capture(Throwable)`** — **mai lancia** (try/catch totale, niente ricorsione): salta se disabilitato o eccezione "ignorabile" (404, validazione, auth, model-not-found, CSRF 419, HttpException 4xx); calcola fingerprint; `firstOrCreate` issue (+ alert se nuova); incrementa occorrenze; crea occurrence con contesto da `request()` e **input ripulito** dalle chiavi sensibili (password, token, code, recovery_code, two_factor*, secret).
- [ ] **Aggancio** nel report-hook di `bootstrap/app.php` (`$exceptions->report(...)`) — l'errore va sia in `laravel.log` sia nel tracker.
- [ ] **Config `config/errors.php`** (`enabled`, `alert_email`, lista `ignore`) + chiavi vuote in `.env.example`.
- [ ] **Alert email** (`App\Mail\NewErrorIssueNotification`) alla **prima** occorrenza di un nuovo errore; sincrona (no worker); attiva solo se `alert_email` valorizzato.
- [ ] **Permesso RBAC `system.errors.view`** (Developer/Superadmin; **bloccato**; Admin escluso) in `config/rbac.php` → conteggi 56→57, Superadmin 55→56, locked 9→10; aggiornare i test e la matrice in `Schema Ruoli e Permessi.md`.
- [ ] **Dashboard Livewire** (gate `can:system.errors.view`): `/system/errors` lista issue filtrabile per stato (badge open=danger/resolved=success/ignored=locked) + `/system/errors/{issue}` dettaglio con stack trace e occorrenze; azioni risolvi/ignora/riapri. Stile design system.
- [ ] **Voce di menù** nella sidebar (`@can('system.errors.view')`) "Errori di sistema".
- [ ] **Comando `errors:prune --days=90`** (pulizia issue risolte vecchie); schedulazione al deploy.
- [ ] **Test Pest:** cattura/raggruppamento/ignore/sanitizzazione/alert; dashboard (200 per Superadmin, 403 per Admin, azione risolvi); RBAC aggiornato.
- [ ] **Docs:** ADR-017 + matrice permessi + spunta roadmap.

## 4. Fuori scope (per ora)

- **Errori JS lato browser** (Sentry li cattura via SDK frontend): estensione futura con un endpoint dedicato.
- **Performance monitoring / breadcrumbs / release tracking / source maps**: non necessari in V1.
- **Aggregatore di log esterno** (Papertrail/Logtail): non necessario; i log restano su file + questo tracker.

## 5. Verifica

- Forzare un'eccezione (tinker: `app(\App\Support\ErrorTracker::class)->capture(new \RuntimeException('x'))`) → riga in `error_issues`/`error_occurrences`; `/system/errors` la mostra (login developer dopo 2FA); risolvi/ignora funzionano; in `laravel.log` (MAIL=log locale) compare l'email di alert.
- Admin → 403 su `/system/errors`; Superadmin → 200.
- `php artisan test` verde; Pint pulito.
