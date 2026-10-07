🔐 Schema Ruoli e Permessi (RBAC) — Easy Lab

*Definisce i 7 ruoli applicativi (6 fino al 6 Ott 2026: 🔗 ADR-046 ha aggiunto il `Gestore`) e la **matrice permessi per risorsa** che li governa. Traduce in permessi nominati (stile `spatie/laravel-permission`) le decisioni di accesso degli ADR-006/007 e i vincoli di privacy/tracciamento di ADR-004/005/013. Questo documento è il **contratto** per il seeder ruoli/permessi dello Sprint 1 (task "Installare spatie/laravel-permission; definire ruoli/permessi base da S0"). In caso di conflitto fra questo file e un ADR, vince l'ADR (e questo file va corretto).*

> **Stato:** implementato in Sprint 1 · punto 5 — `config/rbac.php` + `RolesAndPermissionsSeeder` (**54** permessi, 6 ruoli, set bloccato di **7**). Erano 56 permessi fino a S3-bis: ADR-019 ha rimosso `letture_contaore.*`; e il set bloccato era di 9 fino ad ADR-029 (15 Ago 2026), che ne ha liberate due — *l'intestazione ha continuato a dire 9 per una settimana mentre la §7 diceva già 7: allineata il 22 Ago 2026*. Dopo il seeding la fonte di verità è il DB (ADR-016 §7) — quindi **una modifica a `config/rbac.php` non arriva da sola sui database già seminati**: va riseminata, e nessun test se ne accorge (la suite ricrea il DB dalla config).

---

## 1. Premessa: due livelli ortogonali (permesso ≠ scope)

L'autorizzazione in Easy Lab si compone di **due livelli indipendenti**, che vanno tenuti distinti:

| Livello | Risponde a | Dove vive | Documento |
|---|---|---|---|
| **Permesso (RBAC)** | *Quale azione* un ruolo può compiere su un **tipo** di risorsa? | `spatie/laravel-permission` (ruoli + permessi) | **questo doc** |
| **Scope (visibilità per riga)** | *Su quali righe* l'azione è consentita? | Global Scope Eloquent + filtri applicativi (Policy) | `Modello Dati (ERD).md` §10 · 🔗 ADR-001/006/007 |

> ⚠️ **I due livelli si applicano in AND.** Avere il permesso `strumenti.update` **non basta**: la riga interessata deve anche rientrare nello *scope* del ruolo. Esempio: un Responsabile Reparto ha `strumenti.update`, ma può modificare solo gli strumenti del proprio sotto-albero (scope), non quelli di altri reparti dello stesso Ente.

La matrice di §6 definisce **il primo livello** (permesso sì/no per ruolo). Il secondo livello — *quante righe* il permesso effettivamente tocca — resta descritto in ERD §10 e non viene qui duplicato.

---

## 2. I 7 ruoli

Nomi ruolo **identici** a ERD §3.1 (usati così come stringa spatie).

| Ruolo (spatie) | Livello | `users.tenant_id` | Bypass Global Scope | Sintesi |
|---|---|---|---|---|
| `Developer` | Piattaforma | NULL | ❌ | Accesso tecnico totale: codice, log di sistema, impersonation. |
| `Superadmin` | Piattaforma | valorizzato (Ente di piattaforma) | ❌ | Cabina di regia EasyLab. ⊇ tutte le funzionalità di `Admin` + gestione globale (clienti, MRR, billing, provisioning, lockout). |
| `Admin` | Tenant-bound | valorizzato | ❌ | "Piccolo EasyLab" sul proprio Ente: anagrafica, interventi, garanzie (incl. ricambio), fornitori, forzatura semaforo, abbonamento proprio. |
| `Responsabile Reparto` | Tenant-bound | valorizzato | ❌ | Come Admin sull'operatività, ma ristretto al **sotto-albero** assegnato (pivot `responsabile_unita`); niente billing/audit/provisioning. |
| `Tenant` | Tenant-bound | valorizzato | ❌ | Laboratorio/Ente finale: vista semaforo, interventi, documenti, garanzia **macchina**. Garanzie ricambio secondo l'impostazione del **proprio Ente** (🔗 ADR-029), non secondo la matrice. |
| `Tecnico` | Piattaforma **o** Ente | NULL (esterno) **o** valorizzato (interno) | ❌ (accesso derivato) | Personale sul campo: accesso a strumenti = **portafoglio ∪ assegnazione** (ADR-007/030) per **entrambe** le forme, ogni accesso loggato. Per l'interno il `tenant_id` è difesa in profondità (in AND), non un criterio: appartenere all'Ente non dà accesso alle sue macchine. |
| `Gestore` | Piattaforma | NULL | ❌ (accesso derivato) | Personale EasyLab che **gestisce la manutenzione** dei clienti assegnati (🔗 ADR-046). Stesso portafoglio e stessa regola di lettura del `Tecnico`; a differenza sua **scrive**: macchine, interventi (crea, assegna, chiude), garanzie, ricambi, documenti, fornitori, reparti e sotto-laboratori (crea e rinomina, **non elimina**). Niente utenti, billing, audit, piattaforma. 2FA obbligatorio. |

**Note sulla gerarchia.**
- 🆕 **Il Superadmin sui clienti con manutenzione gestita** (🔗 ADR-046, 6 Ott 2026): oltre al proprio Ente vede e scrive le sedi degli account con `manutenzione_gestita`. Non è un bypass: l'insieme è nominato cliente per cliente (`ClientiGestiti`), e sui clienti che si gestiscono da sé resta il Parco in sola lettura e l'impersonazione.
- 🆕 **Il `Developer` non ha più il secondo fattore obbligatorio** (🔗 ADR-046): è l'account di debug. Obbligatorio per `Superadmin`, `Admin`, `Gestore`.
- 🔴 **Nessun ruolo bypassa il Global Scope**, Developer e Superadmin compresi. *Questa riga diceva il contrario fino al 26 Ago 2026 — «vedono tutti i tenant, indispensabile per le dashboard globali» — ed era la premessa che 🔗 **ADR-018** ha scartato: un bypass per ruolo è un secondo percorso di accesso, e il giorno che sbaglia non lo dice.* Ciò che rende possibili le viste globali è **una porta unica e non-scopata** (`App\Support\Tenancy\VistaPiattaforma`), che toglie gli scope **per nome** e chiede `tenants.view_all`; l'accesso ai dati di un cliente passa invece dall'**impersonazione**, tracciata. Il Superadmin è a tutti gli effetti tenant-bound su un proprio Ente di piattaforma (`SuperadminSeeder`), e il Developer **non ha `tenant_id`**: per `TenantScope` questo è fail-closed, quindi vede **zero righe** di dominio — non tutte. È il motivo per cui la dashboard per ruolo gli mostra «Nessuno strumento visibile.» e non quattro zeri.
- `Superadmin` eredita *funzionalmente* tutti i permessi di `Admin` (Funzionalità per Ruolo §2: "tutte le funzionalità dell'Admin sono integrate nel superadmin").
- `Tecnico` è un ruolo con accesso **derivato** (non bypassa lo scope, ma ne ha uno proprio come unione di due insiemi — §6, ADR-007/030). Ne esistono **due forme**: l'**esterno** (staff EasyLab, `tenant_id` NULL, con portafoglio clienti) e l'**interno** (dipendente del laboratorio, `tenant_id` valorizzato). La regola di visibilità è la stessa per entrambi: la forma cambia solo *da dove* arriva l'accesso, non *quanto* se ne ottiene.

---

## 3. Convenzione di naming permessi

Formato: **`<risorsa>.<azione>`** minuscolo. La `<risorsa>` usa i nomi tabella italiani dell'ERD (`strumenti`, `interventi`, `unita_organizzativa`, …) per coerenza col modello dati.

- **Azioni CRUD standard:** `view`, `create`, `update`, `delete`.
- **Azioni speciali:** nome dedicato esplicito (es. `semaforo.force`, `utenti.impersonate`, `documenti.export_pdf`). Per sotto-domini si usa un terzo segmento (es. `garanzie.ricambio.view`).

---

## 4. Catalogo permessi per risorsa

Risorse derivate dall'ERD §3–§9. Questo è l'elenco canonico che il seeder S1 deve creare.

### 4.1 Anagrafica & asset
- `unita_organizzativa.view` · `.create` · `.update` · `.delete` — albero Ente/Dipartimento/Sottolab (🔗 ADR-006).
- `strumenti.view` · `.create` · `.update` · `.delete`
- `strumenti.move` — registra spostamento tra nodi (🔗 ADR-015, `spostamenti_strumento`).
- `strumenti.qr_generate` — genera/ristampa QR univoco (🔗 ADR-003).
- `spostamenti.view` — consultazione log completo spostamenti.

### 4.2 Interventi & semaforo
- `interventi.view` · `.create` · `.update` · `.delete`
- `interventi.complete` — spunta "Fatto" / chiusura intervento (🔗 ADR-005).
- `interventi.assign` — assegna il tecnico (`interventi.tecnico_id`, 🔗 ADR-007).
- `semaforo.force` — forzatura manuale stato semaforo, tracciata (🔗 ADR-005).

### 4.3 Garanzie
- `garanzie.macchina.view` · `garanzie.macchina.manage` — garanzia macchina (visibile anche al Tenant).
- `garanzie.ricambio.view` · `garanzie.ricambio.manage` — garanzia pezzo: **mai al Tenant** (🔗 ADR-004). ⚠️ **Superato da ADR-029** (deciso il 9, attuato il 15 Ago 2026): è un'impostazione per-Ente decisa dal Superadmin a tre stati (`nascosta` / `lettura` / `modifica`), con **default modifica** alla creazione, e le due voci sono uscite dal set 🔒. Il Tenant ha ora entrambi i permessi: quel che lo restringe è l'Ente, non la matrice. Il divieto riguarda **le righe**, non l'arancione che ne deriva: il semaforo le conta comunque (🔗 ADR-020).
  > **Il Tecnico le gestisce** (🔗 ADR-027, 8 Ago 2026): è chi monta fisicamente il pezzo, quindi la fonte del dato. Fino ad allora il divieto si estendeva anche a lui citando ADR-004, che però nomina solo il Tenant. Ogni modifica è tracciata sul canale `audit`.
- ~~`letture_contaore.view` · `.create`~~ — **permessi rimossi** (🔗 ADR-019): eliminata la garanzia a ore, il contaore non esiste più. Tolti da `config/rbac.php` in S3-bis; **dai database già seminati solo l'8 Ago 2026** (blocco 0 di S4), perché la config è bootstrap e il seeder non cancella le righe che non conosce più.

### 4.4 Ricambi & fornitori
- `ricambi.view` · `.create` · `.update` · `.delete` — catalogo (🔗 ADR-008/022).
- `ricambi.merge` — unione doppioni catalogo *(STRETCH S4)*.
- `ricambio_utilizzo.view` · `.create` · `.update` · `.delete` — associazione macchina↔ricambio↔intervento.
  > ✅ **Nodo sciolto** (🔗 ADR-027, 8 Ago 2026). Registrando un ricambio dal form intervento si crea anche la sua garanzia `soggetto = ricambio`: il **Tecnico** ha ora sia `ricambio_utilizzo.create` sia `garanzie.ricambio.manage`, e ogni scrittura è tracciata. Nessuna delle tre soluzioni di ripiego ipotizzate in ADR-022 serve più — il vincolo che le rendeva necessarie non era mai stato deciso.
- `fornitori.view` · `.create` · `.update` · `.delete` — anagrafica fornitori **per Ente**, scopata per `tenant_id` come ogni tabella di business (🔗 ADR-023). Ogni Ente popola e vede solo i propri.

### 4.5 Documenti & QR
- `documenti.view` · `documenti.upload` · `documenti.download` · `documenti.delete` (🔗 ADR-009).
- `documenti.export_pdf` — esportazione report/storici/certificati in PDF.
- `qr.scan` — accesso alla scheda strumento da QR firmato (🔗 ADR-003).

### 4.6 Billing & tenancy (piattaforma)
- `billing.manage_own` — portale Stripe del proprio abbonamento.

> 🔒 **`billing.manage_own` non si usa mai nudo** (dal 21 Ago 2026): è la condizione *necessaria*, e la condizione «membro dell'account» vive in `App\Policies\AccountPolicy::manage` (🔗 ADR-032). Con `teams = false` i permessi sono globali, quindi `authorize('billing.manage_own')` concederebbe a qualunque Admin su **qualunque** account. L'ability ha nome diverso dal permesso perché il `Gate::before` di spatie concede appena il permesso esiste sul ruolo — un'ability omonima non verrebbe mai raggiunta. Un guardrail nei test vieta la stringa nuda fuori dalla Policy.
- `billing.manage_global` — gestione piani/abbonamenti di tutti i clienti (🔗 ADR-002).
  - 🗓️ **Dal 27 Ago 2026 è anche il permesso della creazione dei piani** (🔗 **ADR-035**), e non ne serve uno nuovo: lo tengono già **solo Developer e Superadmin** — l'Admin lo ha in `except`, nessuna lista `only` lo nomina — ed è nel **set bloccato**, quindi l'editor permessi di runtime non può concederlo a un ruolo cliente. Un permesso dedicato sarebbe l'**ottavo** bloccato e imporrebbe un riseeding per dire la stessa cosa.
- `billing.lockout` — blocco/sblocco insoluti (🔗 ADR-013).
- `tenants.view_all` — dashboard globale clienti + MRR.
- `tenants.provision` — onboarding/erogazione Free "chiavi in mano" (🔗 ADR-002/012).

### 4.7 Utenti, audit, sistema
- `utenti.view` · `.create` · `.update` · `.delete`
- `utenti.impersonate` — impersonation con banner + log (lab404/laravel-impersonate).
- `audit.view` — consultazione activity log (filtri impersonation/forzature).
- `system.logs.view` — log di sistema globali (solo Developer). ✅ **Ha un consumatore dal 24 Ago 2026** (🔗 ADR-017): gata `/piattaforma/errori` e `/piattaforma/errori/{errore}`, l'**error tracker interno** — cioè gli errori catturati dall'applicazione, non un visore di `laravel.log`, che su Cloud vive su un disco effimero. Fino a quel giorno il permesso esisteva a catalogo, era bloccato, ed era assegnato al Developer **senza gatare niente**.
  - 🔴 **È il primo permesso del progetto in cui le due partizioni di piattaforma non coincidono**: il Superadmin ha ogni altro permesso di piattaforma, questa pagina non la può nemmeno aprire. Il registro di audit sta invece dietro `tenants.view_all`, che ce l'hanno entrambi — da cui il vincolo che ne discende, e che nessuno indovinerebbe: l'etichetta del soggetto di una riga «Errore risolto» è la **classe**, mai il **messaggio**, o quest'ultimo filtrerebbe attraverso un gate che agli errori non dà accesso.
  - ⚠️ **Nessun conteggio cambia**: restano **54** permessi e **7** bloccati. Il piano dell'error tracker prevedeva un `system.errors.view` nuovo — sarebbe stato un ottavo bloccato, un riseeding e un secondo nome per la stessa cosa; si è riusato questo, che era già a catalogo, già bloccato e già del solo Developer.
- `roles.manage` — gestione della matrice ruolo→permesso dalla UI Superadmin (🔗 ADR-016).

---

🔗 **ADR-038 usa il catalogo esistente.** `/utenti` richiede `utenti.view` alla rotta e riautorizza `utenti.create`, `.update` o `.delete` in ogni azione. Conferisce solo Admin, Responsabile Reparto, Tenant e Tecnico interno; Admin impone il 2FA. `/piattaforma/tecnici` usa invece **`tenants.view_all`**, mai `utenti.view`, perché crea tecnici EasyLab e scrive un portafoglio cross-cliente. Developer e Superadmin non sono amministrabili da `/utenti`.

**Nessun permesso nuovo:** il catalogo resta a **54**, il set bloccato a **7**, quindi ADR-038 non richiede alcun riseeding.

## 5. Matrice ruolo × permesso

✅ = il ruolo possiede il permesso · ❌ = non lo possiede · 🔒 = permesso **bloccato** (non modificabile dalla UI Superadmin, vedi §7). Per i ruoli con scope ristretto il permesso resta **limitato dallo scope** (sotto-albero/portafoglio/proprietà): vedi nota e ERD §10.

| Permesso | Developer | Superadmin | Admin | Responsabile Reparto | Tenant | Tecnico |
|---|---|---|---|---|---|---|
| `unita_organizzativa.view` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ | ✅ ² |
| `unita_organizzativa.create/update/delete` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `strumenti.view` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ | ✅ ² |
| `strumenti.create/update/delete` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ |
| `strumenti.move` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ |
| `strumenti.qr_generate` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ |
| `spostamenti.view` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ |
| `interventi.view` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ | ✅ ² |
| `interventi.create/update/delete` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ |
| `interventi.complete` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ✅ ² |
| `interventi.assign` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ |
| `semaforo.force` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ |
| `garanzie.macchina.view` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ | ✅ ² |
| `garanzie.macchina.manage` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ |
| `garanzie.ricambio.view` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ ³ | ✅ ² ⁶ |
| `garanzie.ricambio.manage` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ ³ ⁷ | ✅ ² ⁶ |
| ~~`letture_contaore.view`~~ | — | — | — | — | — | — |
| ~~`letture_contaore.create`~~ | — | — | — | — | — | — |
| `ricambi.view` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ ³ | ✅ ² |
| `ricambi.create` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ✅ ² ⁴ |
| `ricambi.update/delete` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ ⁴ |
| `ricambi.merge` *(STRETCH)* | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `ricambio_utilizzo.view` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ ³ | ✅ ² |
| `ricambio_utilizzo.create/update/delete` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ✅ ² |
| `fornitori.view` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ ⁵ | ❌ |
| `fornitori.create/update/delete` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `documenti.view` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ | ✅ ² |
| `documenti.upload` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ | ✅ ² |
| `documenti.download` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ | ✅ ² |
| `documenti.delete` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ❌ |
| `documenti.export_pdf` | ✅ | ✅ | ✅ | ✅ ¹ | ✅ | ❌ |
| `qr.scan` | ✅ | ✅ | ✅ | ✅ ¹ | ❌ | ✅ ² |
| `billing.manage_own` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `billing.manage_global` 🔒 | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `billing.lockout` 🔒 | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `tenants.view_all` 🔒 | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `tenants.provision` 🔒 | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `utenti.view` | ✅ | ✅ | ✅ ¹ | ❌ | ❌ | ❌ |
| `utenti.create/update/delete` | ✅ | ✅ | ✅ ¹ | ❌ | ❌ | ❌ |
| `utenti.impersonate` 🔒 | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `audit.view` | ✅ | ✅ | ✅ ¹ | ❌ | ❌ | ❌ |
| `system.logs.view` 🔒 | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `roles.manage` 🔒 | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |

**Note di scope (livello "su quali righe", vedi ERD §10):**
- ¹ **Responsabile Reparto / Admin:** limitato al proprio Ente; il Responsabile è ulteriormente ristretto al **sotto-albero** assegnato (`responsabile_unita`, 🔗 ADR-006). `Admin.utenti.*` e `audit.view` valgono solo per il proprio Ente.
- ² **Tecnico:** solo strumenti in **portafoglio ∪ assegnazione** (🔗 ADR-007, esteso da 🔗 **ADR-030** il 17 Ago 2026); ogni accesso loggato — e dal 17 Ago **per tutti i tecnici**, non solo per quelli esterni, perché distinguerli richiederebbe di fidarsi di un `tenant_id` che ADR-030 declassa da criterio a difesa. È la seconda eccezione al perimetro di ADR-027 «si tracciano le scritture, non le letture», dopo il download documenti (ADR-026).
- ³ **Tenant — riscritta il 22 Ago 2026, e va letta come una correzione, non come un aggiornamento.** Diceva «`garanzie.ricambio.*` = ❌ (ADR-004)», cioè il **contrario** di ciò che `config/rbac.php` produce dal 15 Ago 2026: ADR-029 ha dato al Tenant entrambi i permessi e ha tolto le due voci dal set 🔒. Ciò che restringe il Tenant non è più la matrice ma l'**Ente**, con l'impostazione a tre stati `nascosta`/`lettura`/`modifica` (default **modifica**), che *restringe e non allarga mai*. Resta vero il resto: vede il **pallino arancione** che quella garanzia accende (🔗 ADR-020) anche quando l'Ente è su `nascosta` — il limite è sul dato, non sul suo effetto.
- ⁶ **Tecnico — modifica approvata l'8 Ago 2026** (🔗 ADR-027). Gestisce le garanzie dei ricambi che monta: è la fonte del dato, e negargliela costringeva a farla compilare a chi il pezzo non l'ha visto. Il controllo non è il divieto ma la **traccia**: ogni scrittura finisce nel canale `audit` con chi/cosa/quando. ⚠️ Cambia un default del seeder S1 e **richiede di riscrivere il test** `never grants spare-part warranties to Tenant or Tecnico`, che congelava la regola sbagliata.
- ⁵ **Tenant — approvata il 3 Ago 2026, APPLICATA il 15 Ago 2026** (🔗 ADR-023): fra le due date il documento affermava un default che `config/rbac.php` non produceva, e il riseeding non l'avrebbe aggiunto da solo. `fornitori.view` passa da ❌ a ✅ **in sola lettura**: il fornitore è ora un campo della scheda strumento e della Panoramica, e l'anagrafica è popolata *dall'Ente stesso* — nascondere al cliente da chi ha comprato la propria macchina non protegge nulla e lascerebbe un campo vuoto inspiegabile. Creazione/modifica restano all'Admin. ⚠ **Cambia un default del seeder S1** (`config/rbac.php`), quindi va con un test che asserisca il nuovo default: è l'unico modo perché una riga di matrice non torni indietro da sola al prossimo riseed.
- ⁷ **Il `manage` del Tenant è oggi teorico**, ed è annotato invece che risolto: il Tenant ha `garanzie.ricambio.manage` ma non `ricambio_utilizzo.update`, quindi nel tab non ha nessuna azione. Il comportamento è congelato da un test che dichiara di **congelarlo e non approvarlo**; la decisione sulla matrice va presa a parte.
- ⁴ **Tecnico:** ha **solo `ricambi.create`** — può creare voci di catalogo "al volo" durante l'intervento (autocomplete, 🔗 ADR-008/022); **aggiornare/cancellare il catalogo resta operazione di Admin** (`ricambi.update/delete` = ❌). Risolto in S1 a favore di questa nota (seeder coerente).

---

### 5-bis. La colonna che manca: `Gestore` (🔗 ADR-046)

La tabella qui sopra ha sei colonne. I permessi del settimo ruolo, dal default di `config/rbac.php` (la matrice viva si legge e si modifica da `/piattaforma/ruoli`):

- ✅ `unita_organizzativa.view/create/update` · ❌ `unita_organizzativa.delete`
- ✅ `strumenti.view/create/update/move/qr_generate` · ❌ `strumenti.delete`
- ✅ `spostamenti.view`
- ✅ `interventi.view/create/update/delete/complete/assign` · ✅ `semaforo.force`
- ✅ `garanzie.macchina.view/manage` · ✅ `garanzie.ricambio.view/manage`
- ✅ `ricambi.view/create/update` · ❌ `ricambi.delete`, `ricambi.merge`
- ✅ `ricambio_utilizzo.view/create/update/delete`
- ✅ `fornitori.view/create/update` · ❌ `fornitori.delete`
- ✅ `documenti.view/upload/download/delete/export_pdf` · ✅ `qr.scan`
- ❌ tutto il resto: `utenti.*`, `billing.*`, `tenants.*`, `audit.view`, `roles.manage`, `system.logs.view`

Tutti limitati dallo scope: le sole sedi in portafoglio (`tecnico_cliente`). Due vincoli non stanno nella matrice: il **nodo Ente** non lo modifica (lo rifiuta `Albero`), e uno **spostamento** resta dentro la sede della macchina.

---

## 6. Regole trasversali (vincoli non esprimibili dalla sola matrice)

Questi vincoli non si esauriscono in un permesso sì/no e vanno implementati in **Policy + Global Scope** (non solo in spatie):

- **Visibilità garanzie ricambio** (🔗 ADR-004/027/**029**): ⚠️ **non è più «mai al `Tenant`»** — la riga diceva così fino al 22 Ago 2026, quando la matrice era già cambiata da una settimana. Dal 15 Ago è un'impostazione **dell'Ente** a tre stati (`nascosta`/`lettura`/`modifica`, default *modifica*), applicata da `GaranziaRicambioPrivacyScope` + Policy: **restringe, non allarga**, e un Ente su `modifica` non concede nulla a chi il permesso non ce l'ha. Il **Tecnico le gestisce**, con ogni scrittura tracciata.
  - **Eccezione esplicita e unica** (🔗 ADR-020): la query che calcola il semaforo legge le garanzie ricambio **senza** il privacy scope, perché il pallino è un aggregato dovuto a tutti. È l'unico punto del codice autorizzato a fare `withoutGlobalScope(GaranziaRicambioPrivacyScope::class)`, e va accompagnato da un test negativo che verifichi che dal risultato **non trapeli** il nome del pezzo.
- **Scope Responsabile Reparto** (🔗 ADR-006): filtro applicativo sul sotto-albero dei nodi in `responsabile_unita`, in aggiunta al Global Scope per `tenant_id`.
- **Accesso Tecnico** (🔗 ADR-007): insieme visibile = `strumento.tenant_id ∈ portafoglio` **OR** `strumento.id ∈ strumenti con intervento assegnato`. Ogni accesso a scheda strumento → log in `activity_log`.
- **Forzatura semaforo** (🔗 ADR-005): chi ha `semaforo.force` traccia `forced_by` / `forced_at` / `forced_reason`; lo stato forzato vince sul calcolato.
- **Viste di sintesi** (🔗 ADR-024): una schermata che compone dati di aree diverse — la Panoramica della scheda e, dal 25 Ago 2026, la **dashboard per ruolo** (`/dashboard`) — applica il permesso **di ciascuna area, blocco per blocco**. Un solo `@can` in testa alla vista è l'errore da non fare: mostrerebbe a chi entra tutto ciò che la vista sa, aggirando i controlli delle aree d'origine.
  - ⚠️ **Sulla dashboard non è una raccomandazione di stile: è l'unica guardia che esista.** È l'unica pagina del progetto **senza `can:` di rotta**, perché ci atterra ogni utente autenticato — Fortify dopo il login, lo switcher di sede a ogni cambio, la fuga da lockout. Il permesso del blocco parco (`strumenti.view`) si chiede **due volte**: nel `render()` per decidere se *calcolare*, nel template per decidere se *mostrare*; senza la prima, chi non ce l'ha pagherebbe comunque quattro aggregati. E il caso è reale, non teorico: `strumenti.view` **non è nel set bloccato**, quindi `/piattaforma/ruoli` può revocarlo a runtime.
  - ⚠️ **E regge finché quel componente non ha AZIONI**: una con `skipRender()` non arriverebbe mai a `render()`, e non c'è un `can:` di rotta a raccoglierla. Chi aggiunge un pulsante lì deve portarsi dietro il proprio `Gate::authorize()` in testa all'azione.
  - ⛔ **Un aggregato non è esente dalla privacy per il fatto di essere un numero.** La dashboard **non scompone** i propri conteggi per causa: 🔗 ADR-020 legittima il *pallino* come aggregato dovuto a tutti, non la sua scomposizione, e un «di cui N da garanzie ricambio» direbbe a un Ente su `visibilita_garanzie_ricambio = nascosta` quanti pezzi sostituiti ha — **senza passare da nessuno scope**, perché un numero non è una riga.
- **Impersonation** (🔗 Tech Stack, lab404): chi ha `utenti.impersonate` agisce con banner persistente di ripristino; ogni impersonation loggata.
- **Lockout insoluto** (🔗 ADR-013): chi ha `billing.lockout` blocca totalmente il tenant; i dati restano consultabili solo lato Superadmin.

---

🔗 **Persone e portafoglio (ADR-038).** La matrice dice *quale gesto* è ammesso; il perimetro resta nel codice: persone del solo Ente corrente su `/utenti`, tecnici `tenant_id IS NULL` con ruolo Tecnico su `/piattaforma/tecnici`, sole sedi consegnate dalla porta cross-cliente nel portafoglio. La whitelist dell'assegnatario contiene soltanto utenti col ruolo Tecnico: tecnici interni della sede ∪ tecnici EasyLab nel portafoglio di quella sede. Ogni azione mutante riautorizza e rilegge l'id nel proprio perimetro.

## 7. Gestione runtime dei permessi (UI Superadmin) — 🔗 ADR-016

I permessi della matrice §5 sono dei **default**, non una configurazione fissa: `spatie/laravel-permission` salva la relazione ruolo→permesso a DB (`role_has_permissions`), quindi EasyLab può aggiustarla nel tempo **senza rilascio di codice**.

**Tre stati di un permesso:**

| Stato | Significato | Dove |
|---|---|---|
| **Default** | Valore iniziale impostato dal seeder S1 (= matrice §5). | `RolesAndPermissionsSeeder` |
| **Configurabile** | Modificabile a runtime dalla UI Superadmin (toggle ruolo×permesso). | `role_has_permissions` (DB) |
| **Bloccato** 🔒 | Non modificabile dalla UI: vincolo privacy/legge/sicurezza o strutturale. Mostrato in sola lettura. | costante applicativa (config/codice) |

**Regole della UI** (Dashboard Superadmin, S6):
- **Ambito globale:** un'unica matrice per tutta la piattaforma (no modalità "teams" in V1; personalizzazione per-Ente → V1.1).
- **Accesso:** solo chi ha `roles.manage` (Developer/Superadmin) — esso stesso bloccato per evitare auto-delega.
- ✅ **In costruzione da S6 (22 Ago 2026)**, `App\Support\Rbac\MatriceRuoli`. Tre cose che la lettura di questo paragrafo non rendeva ovvie e che l'attuazione ha dovuto decidere:
  - **«bloccato» è una proprietà del permesso, non della coppia**: una voce 🔒 non è né revocabile né **concedibile**, a nessun ruolo. L'argomento decisivo non sta in questo documento ma nel middleware: il 2FA obbligatorio si gata **per nome di ruolo** (`two_factor_required_roles`), quindi sotto la lettura per coppia `roles.manage` sarebbe concedibile al `Tenant` — un editor della matrice raggiungibile senza secondo fattore;
  - **la riga del `Developer` è inerte in entrambe le direzioni**: è la chiave di riserva della piattaforma, e non esiste un `Gate::before` da super-admin che la rimpiazzi se la si svuota;
  - **un permesso fuori catalogo non si riassegna**: `permissions` conserva le righe tolte dalla config (il seeder usa `firstOrCreate`), e senza guardia la UI legittimerebbe un orfano — la forma dell'incidente `letture_contaore.*`.
- **Set bloccato (V1):** ~~`garanzie.ricambio.*`~~ (*uscite dal set il 15 Ago 2026 con ADR-029, che le rende configurabili per Ente: il set passa da 9 a 7 voci*. Erano lì per ADR-004 — mai al **Tenant**; *corretto l'8 Ago 2026 da ADR-027: qui c'era scritto «mai a Tenant/Tecnico», ed è la stessa citazione allargata oltre la fonte che §4.3 portava. Il Tecnico le gestisce*), `utenti.impersonate`, `system.logs.view`, `billing.manage_global`, `billing.lockout`, `tenants.view_all`, `tenants.provision`, `roles.manage`. Le righe 🔒 della matrice §5.
- **Confine invariabile:** la UI modifica solo il *cosa* (permesso), **mai** il *su quali righe* (scope). Isolamento `tenant_id`, sotto-albero Responsabile e unione Tecnico restano nel codice (Global Scope/Policy) e non sono configurabili (🔗 ADR-001/006/007).
- **Audit:** ogni modifica alla matrice è loggata in `activity_log` (chi/cosa/quando).
- **Fonte di verità:** dopo il seeding è il DB; il seeder resta solo bootstrap/reset.

> Aggiungere un *nuovo* permesso resta operazione di codice (catalogo §4 + seeder). La UI gestisce l'**assegnazione** dei permessi esistenti, non la loro creazione.

---

## 8. Mapping verso l'implementazione (Sprint 1)

Indicazioni operative per il task S1 "definire ruoli/permessi base da S0" — il codice si scrive in S1, qui solo la mappa:

1. **`RolesAndPermissionsSeeder`** (spatie): creare tutti i permessi del catalogo §4, poi i 6 ruoli di §2, infine assegnare i permessi seguendo la matrice §5. Questi sono **default** (bootstrap/reset): dopo il seeding la fonte di verità è il DB, modificabile da UI (§7, 🔗 ADR-016).
2. **Cosa va in spatie:** la coppia ruolo→permesso (§5), cioè il *cosa*.
3. **Cosa NON va in spatie ma in Policy/Global Scope:** il *su quali righe* (§6) — scope `tenant_id` (ADR-001), sotto-albero Responsabile (ADR-006), unione Tecnico (ADR-007), privacy garanzie ricambio (ADR-004). La matrice spatie da sola **non** garantisce l'isolamento: serve la suite test di isolamento prevista in S2.
4. **Set bloccato come costante** (config/codice, non DB): la guard che impedisce di modificare i permessi 🔒 dalla UI va con test negativi. ⚠️ **L'esempio che stava qui — «il Superadmin non può concedere `garanzie.ricambio.view` al Tenant» — è falso dal 15 Ago 2026** (ADR-029 ha liberato quelle voci) e va sostituito, non conservato: gli esempi giusti sono «non può concedere `roles.manage` all'Admin» e «non può revocare `tenants.view_all` al Superadmin». ⚠️ E il set è bloccato **per permesso, non per coppia**: una voce 🔒 non è né revocabile né concedibile, a **nessun** ruolo — vedi §7. Attuato il 22 Ago 2026 in `App\Support\Rbac\MatriceRuoli` (S6, blocco 1).
5. **Verifica incrociata:** ogni voce di `Funzionalità per Ruolo.md` deve trovare un permesso corrispondente qui; ogni risorsa con permessi deve avere una tabella nell'ERD.

---

## 9. Tracciabilità ADR → permessi

| ADR | Permessi/regole impattati |
|---|---|
| **ADR-001** Multi-tenancy | Scope `tenant_id` su tutti i permessi tenant-bound (livello 2). |
| **ADR-002** Rivenditori/billing | `billing.manage_global`, `tenants.provision`. |
| **ADR-003** QR firmato | `strumenti.qr_generate`, `qr.scan`. |
| **ADR-004** Garanzie sdoppiate | `garanzie.macchina.*`, `garanzie.ricambio.*` (mai al Tenant). |
| **ADR-005** Semaforo | `interventi.complete`, `semaforo.force` (+ tracciamento). |
| **ADR-006** Tenant=Ente / scope reparto | Ruoli tenant-bound; nota scope ¹; `responsabile_unita`. |
| **ADR-007** Accesso Tecnici | Ruolo `Tecnico`, `interventi.assign`, nota scope ²; log accessi. |
| **ADR-008** Catalogo ricambi | `ricambi.*`, `ricambio_utilizzo.*`, `ricambi.merge`. |
| **ADR-009** Documenti/tarature | `documenti.*`, `documenti.export_pdf`. |
| **ADR-012** Onboarding doppio | `tenants.provision`, `utenti.create`. |
| **ADR-013** Lockout insoluto | `billing.lockout`. |
| **ADR-016** Permessi configurabili a runtime | `roles.manage`; set bloccato 🔒; default seeder vs DB (§7). |
| **ADR-019** Garanzie solo a data | **Rimozione** di `letture_contaore.view` / `.create` da catalogo, matrice e seeder. |
| **ADR-020** Garanzia ricambio nel semaforo | Nessun permesso nuovo: eccezione documentata al privacy scope (§6), con test negativo. |
| **ADR-022** Ricambi dall'intervento | Nodo sciolto da ADR-027: il Tecnico ha entrambi i permessi. |
| **ADR-029** Visibilità garanzie ricambio per-Ente | `garanzie.ricambio.*` esce dal set 🔒 (9 → 7 voci) e passa al Tenant; l'eccezione è un'impostazione dell'Ente a tre stati (`nascosta`/`lettura`/`modifica`, default **modifica**), governata da chi ha `roles.manage`. Il vincolo di scrittura vive in `GaranziaRicambioPolicy`, non nel permesso. |
| **ADR-027** Tracciabilità | `garanzie.ricambio.*` al Tecnico; il set bloccato torna a significare "mai al Tenant". Ogni scrittura di dominio tracciata su `audit`. |
| **ADR-023** Fornitore 1-N | `fornitori.*` scopati per tenant; `fornitori.view` **concesso al Tenant** in lettura (nota ⁵, approvato 3 Ago 2026) — cambia un default del seeder S1. |
| **ADR-024** Tab Panoramica | Nessun permesso nuovo: **ogni blocco resta gated dal permesso della propria area**. La vista di sintesi non deve diventare la scorciatoia che aggira i `@can` degli altri tab — vale come regola di §6. |
| **ADR-038** Persone e portafoglio | Riusa i quattro `utenti.*` per `/utenti` e `tenants.view_all` per `/piattaforma/tecnici`. Ruoli cliente conferibili: Admin, Responsabile Reparto, Tenant, Tecnico; 2FA obbligatorio per Admin. Catalogo 54 / bloccati 7 invariati, nessun seeding. |
