🔍 Policy di Code Review — Easy Lab

*Come revisionare il codice in un modello "l'AI scrive, l'umano testa e approva". Pensata per un singolo sviluppatore con throughput di test limitato: massimizza la sicurezza dove conta senza leggere tutto riga per riga. Da applicare a ogni feature, al confine della feature (non a fine progetto).*

---

## Principio guida

> I test dimostrano i casi che **hai pensato**. I buchi di sicurezza e di isolamento sono quasi sempre i casi che **non** hai pensato.
> → Dove un errore è **catastrofico**, *leggere e capire* batte testare. Dove il comportamento è **visibile e a basso danno**, *testare* è più efficiente.

**Regola d'oro:** non devi leggere tutto, ma devi saper **spiegare come funziona ogni pezzo del percorso critico**. Se non sai spiegare come è imposto l'isolamento tra tenant, fermati e leggi — lì sì, riga per riga.

---

## I tre livelli di revisione

### 🔴 Livello 1 — Leggi riga per riga + sappi spiegarlo (non delegabile)
Un bug qui = fuga di dati, soldi sbagliati o accessi indebiti. Revisione umana piena + test (anche negativi).

- **Global Scope / isolamento multi-tenant** — 🔗 ADR-001, ADR-006
- **Autorizzazioni, Policy, Gate** su ogni risorsa e ogni rotta nuova
- **URL firmate del QR Code** e accesso alle schede strumento — 🔗 ADR-003
- **Accesso tecnici** (logica OR: portafoglio ∪ assegnazione) — 🔗 ADR-007
- **Billing/Stripe**: webhook, stato abbonamento, **lockout insoluti** — 🔗 ADR-013
- **Impersonation** e relativo audit — 🔗 ADR-005/007
- **Migrazioni** che toccano `tenant_id`/`reseller_id` o la forma dei dati (difficili da annullare in produzione)
- **Ogni `withoutGlobalScope` scritto a mano** — è la disattivazione di una guardia. L'elenco dei legittimi è **nominativo e non un conteggio** (corretto il 15 Ago 2026: diceva «ne esistono due e nessun terzo», mentre nel codice erano già sette, e un elenco che conta male smette di funzionare come vincolo di review):
  1. le query di piattaforma non-scopate (🔗 ADR-018);
  2. `Garanzia::scopeDeiPezziMontati()` — il calcolo del semaforo (🔗 ADR-020);
  3. `RicambioUtilizzo::fissaMontaggio()` e `cestinaConGaranzia()` — la garanzia va allineata e cestinata **anche** da chi non ha titolo a vederla, o resta viva e orfana a pesare sul semaforo di un pezzo che non c'è più;
  4. `ManagesRicambiStrumento` — stessa ragione, sul lato lettura del tab;
  5. `AccessibleStrumenti` e `GaranziaDepartmentScope` — subquery di sicurezza che rompono in anticipo la ricorsione col futuro scope Tecnico (🔗 ADR-007);
  6. `User::ente()` — «di chi è quest'utente» va risposto anche dentro un global scope;
  7. `User::sediRaggiungibili()` — le sedi di un account sono per definizione fuori dal tenant corrente (🔗 ADR-032); il confine lo dà il pivot, non un filtro;
  8. `Account::enti()` — idem, e **con i due scope elencati per nome**: il nudo portava via anche il soft delete, e le sedi cestinate occupavano uno slot di piano per sempre;
  9. `Strumento::percorsoUbicazione()` e `mappaUbicazioni()` — risalita gerarchica nodo→radice, che dev'essere completa anche per chi vede un ramo solo;
  10. `Ricambio::unisciIn()` (🔗 ADR-008), `AccessibleNodes`, `AccessoTecnico`, `FugaDaLockout`, `SwitcherEnte`, `ElencoStrumenti` — rotture di ricorsione fra scope e risoluzioni di un id **prima** della guardia che lo autorizza; ciascuno con la ragione nel proprio docblock;
  11. **`App\Support\Tenancy\VistaPiattaforma`** (21 Ago 2026) — la **porta unica** delle viste di piattaforma di S6: toglie `TenantScope` e `DepartmentScope` **per nome**, chiede `tenants.view_all`, e nessun builder non-scopato di piattaforma nasce fuori di lì. È la voce 1 resa concreta, non una dodicesima eccezione.
  12. **`App\Support\Piattaforma\ParcoClienti`** (29 Ago 2026) — la **seconda porta**, quella del *Parco clienti* (🔗 ADR-037, che deroga alla sola metà «lettura» di 🔗 ADR-018): stesso gate `tenants.view_all`, chiesto con `Gate::authorize()` **dentro** la classe, e scope tolti **per nome** modello per modello — `Intervento` senza `TenantScope` + `DepartmentThroughStrumentoScope`, `Garanzia` senza `TenantScope` + `GaranziaDepartmentScope`, `Ricambio` senza il solo `TenantScope`; `UnitaOrganizzativa` e `Strumento` passano dalla voce 11 e non aggiungono bypass. ⚠️ `GaranziaRicambioPrivacyScope` **resta applicato**: non è di tenancy (🔗 ADR-029), e toglierlo «per uniformità con gli altri» concederebbe in silenzio una categoria di righe a un ruolo che avesse `tenants.view_all` senza `garanzie.ricambio.view` — che dall'editor permessi è a un click di distanza. `RicambioUtilizzo` è **deliberatamente fuori**, con la sua domanda ancora aperta. La difendono `tests/Feature/ParcoBypassGuardrailTest.php` (le tre invarianti dello strato di piattaforma) e `tests/Feature/Piattaforma/ParcoClientiTest.php`.

  > ⚠️ **L'elenco si è derivato una seconda volta.** Al 21 Ago 2026 contava sei voci mentre in `app/` i bypass erano diciannove in quattordici file: la correzione del 15 Ago aveva reso l'elenco nominativo, ma non aveva impedito che tornasse a mentire. Da qui la scelta di affiancargli una **rete meccanica** invece di sola disciplina — `tests/Feature/Piattaforma/BypassNudiGuardrailTest.php` non conta tutti i bypass (sarebbe un condono in blocco), ma vieta di aggiungerne di **nudi**: `withoutGlobalScopes()` senza argomenti toglie anche `SoftDeletingScope`, ed è la forma che è davvero costata. Chi ne scrive uno nuovo deve nominarlo lì, e la scelta fra «elencare gli scope» e «dichiarare perché il nudo è giusto» diventa esplicita.
  >
  > *Aggiornato il 29 Ago 2026: in `app/` i `withoutGlobalScope*` sono **48 in 22 file**. Il numero non è la voce da tenere allineata — l'elenco qui sopra è nominativo sulle **ragioni**, non un censimento delle righe — ma vale citarlo perché la rete meccanica è da oggi **doppia**: `BypassNudiGuardrailTest` vieta la forma **nuda** ovunque, `ParcoBypassGuardrailTest` vieta allo strato di piattaforma di togliere scope **anche per nome** fuori dalle due porte. Le due reti non si sovrappongono, ed è il buco che ha lasciato scoperto `app/Support/Piattaforma` fino alla stesura del parco.*

  Il criterio che li accomuna, e che vale per il prossimo: **il permesso governa il dettaglio mostrato, non l'integrità del dato**. Ognuno va con un test negativo che verifichi che dal risultato **non trapeli** il dato protetto — a maggior ragione nella Panoramica (🔗 ADR-024), dove il motivo del semaforo è testo in chiaro e non un pallino.
- 🔴 **Le due porte cross-tenant, e nessuna terza** *(29 Ago 2026)* — `App\Support\Tenancy\VistaPiattaforma` **conta** i clienti (🔗 ADR-018), `App\Support\Piattaforma\ParcoClienti` **elenca le righe di quelli scelti** (🔗 ADR-037). Sono le sole due superfici in cui si legge oltre il proprio Ente senza impersonare, e chi rivede una schermata di piattaforma verifica le tre cose che `tests/Feature/ParcoBypassGuardrailTest.php` tiene meccanicamente su `app/Livewire/Piattaforma`, `app/Support/Piattaforma` (con la **sola** porta del parco esente, per nome), `resources/views/livewire/piattaforma` e `resources/views/components/parco`: che nessun file di quello strato tolga global scope **a mano**; che le schede del parco non leggano diritte da `VistaPiattaforma`, i cui builder non sono filtrati per cliente e mostrerebbero «tutti» mentre il filtro in cima alla pagina dice altro; che sulla porta non si concateni una **scrittura** — `update()`/`delete()` passano dal query builder, non emettono eventi di modello e gli hook di `BelongsToTenant` non girano.
  - 🔴 **Con il parco cambia la *specie* della difesa, ed è la cosa da sapere prima di rivedere.** Fuori di qui l'isolamento è impossibile da violare per costruzione: il global scope filtra e chi scrive una query nuova è protetto senza saperlo. Su queste due superfici l'isolamento è **corretto se il filtro è scritto bene** — è una proprietà del codice, non del framework. Due punti concreti: il perimetro va letto come **intersezione** sopra `VistaPiattaforma::accounts()` (un id forgiato non trova corrispondenza e sparisce; scritto come **unione** sarebbe un buco e sembrerebbe la stessa riga), e la selezione vuota deve compilare «nessuno», mai «nessun filtro» — `App\Support\Piattaforma\Perimetro` non ha per questo un modo `nessuno` separato.
  - ⚠️ **Il gate dentro `ParcoClienti` non si prova coi negativi comportamentali.** Ogni lettore attraversa comunque `VistaPiattaforma`, che il permesso lo chiede: togliendo `self::porta()` da un metodo, i test di accesso **restano verdi**. L'unica prova è **strutturale** e vive in `tests/Feature/Piattaforma/ParcoClientiTest.php` (§5), che pretende che ogni lettore pubblico *apra* con la porta. In review non si accetta «il permesso è già chiesto sotto»: è difesa in profondità, e serve il giorno in cui un lettore smettesse di passare da lì.
  - ⚠️ **Sola lettura, e una PR che aggiunga una scrittura al parco si respinge prima di leggerne il diff.** È l'alternativa esplicitamente scartata da ADR-037: la riga di audit perderebbe il «per conto di chi» (🔗 ADR-027) proprio dove serve, e un filtro che silenziosamente vale «tutti» trasformerebbe una correzione in un'operazione di massa su ogni cliente della piattaforma.
  - *Due debiti **dichiarati**, da non riscoprire in review come se fossero difetti nuovi:* la regola dell'etichetta «prossima scadenza» esiste in **due copie** — `RigheParcoStrumenti::scadenza()`/`etichetta()` e le closure `$scadenzaLabel`/`$etichettaScadenza` in cima a `resources/views/livewire/strumenti/elenco-strumenti.blade.php` — tenute insieme da un **test di accoppiamento** (*«spells the deadline exactly like the per-Ente list does»* in `ParcoStrumentiTest`) e non da un'estrazione; e il perimetro «per piano» non sa esprimere i clienti su un piano **fuori catalogo**, che la cabina invece filtra. Nessuno dei due è un buco di isolamento: sono due schermi che potrebbero divergere e un insieme che non si può selezionare.
- **Viste che compongono più aree** (Panoramica, dashboard S6): il permesso va applicato **blocco per blocco**, mai una volta sola in testa alla vista — 🔗 ADR-024.
  - *Al 25 Ago 2026 la voce ha **due referenti reali**: `_panoramica.blade.php` e `App\Livewire\Dashboard\Home`. La seconda è anche l'**unica pagina del progetto senza `can:` di rotta** — ci atterra ogni utente autenticato — quindi lì «blocco per blocco» non è una raccomandazione di stile: è l'unica guardia che esista. ⚠️ E regge **finché il componente non ha azioni**: una con `skipRender()` non arriverebbe mai a `render()`, e non c'è un `can:` a raccoglierla.*
- **Migrazioni distruttive** (drop di colonne o tabelle popolate) e i **backfill** che le precedono — 🔗 ADR-019. Un ordine sbagliato fra backfill e drop non è recuperabile.

### 🟡 Livello 2 — Leggi la forma, fidati dei test sui casi limite
Logica di business: leggi quanto basta per confermare che corrisponde all'ADR; pretendi test sugli edge case.

- **Calcolo semaforo** (verde/arancione/forzato), incluse le **garanzie dei ricambi montati** e la **diagnosi** che ne espone i motivi — 🔗 ADR-005/020/024
- **Normalizzazione garanzie** in `data_scadenza_effettiva` — 🔗 ADR-004/019
- **Obsolescenza** (soglia configurabile da `data_installazione`) — 🔗 ADR-014
- **Catalogo ricambi** (collega-o-crea sul nome normalizzato) + ricerca incrociata — 🔗 ADR-008/022
- **Rimappatura di valori enum** su dati esistenti — 🔗 ADR-021
- **Scheduler scadenze** / "email del futuro" (no duplicati) — 🔗 ADR-011
- **Spostamenti** e trasferimento storico tra Enti — 🔗 ADR-015

### 🟢 Livello 3 — Black-box: testa e vai
Se sembra giusto e funziona, avanti. Nessuna lettura approfondita necessaria.

- CRUD scaffolding, liste/tabelle Livewire, viste Blade, stile Tailwind, formattazione PDF, componenti UI.

---

## I test che valgono doppio: i test negativi

Per le aree 🔴, la prova che conta è **negativa**, non positiva:
- ❌ non basta "il tenant A vede i suoi dati"
- ✅ serve **"il tenant A NON può vedere/modificare i dati di B"** (per ogni risorsa)
- ✅ "un utente senza permesso sulla rotta riceve 403"
- ✅ "un QR non firmato/scaduto non apre la scheda"
- ✅ "un tenant in lockout non può scrivere"

Scrivi (o fai scrivere) **prima** i test negativi sulle aree 🔴, e **leggili tu**.

---

## Come moltiplicare la banda di un solo revisore

1. **Fatti spiegare il codice dall'AI.** Chiedi: *"descrivi come questo punto garantisce l'isolamento"*, *"dove hai tagliato gli angoli?"*, *"quali rotte ho aggiunto senza autorizzazione?"*. Se la spiegazione non regge, il codice non regge.
2. **Secondo passaggio avversariale** sulle aree 🔴: una review AI dedicata alla sicurezza (es. `/security-review` in Claude Code) prima del merge.
3. **Rivedi al confine della feature, non a fine progetto.** Il debito invisibile si accumula se aspetti — è il rischio principale del modello "AI scrive / tu testi".
4. **Mantieni la suite di isolamento sempre verde** (🔗 ADR-001): è il tuo paracadute di regressione a ogni cambiamento.

---

## Trappole tipiche del codice generato dall'AI (cerca a vista)

- Default troppo **permissivi** (visibilità/permessi aperti "per comodità")
- **Autorizzazione mancante** su rotte/endpoint nuovi
- **Query N+1** e mancanza di eager loading sulle relazioni nidificate
- **Pacchetti inventati o abbandonati** (verifica che esistano e siano mantenuti)
- **Pattern incoerenti** tra file (stesso problema risolto in modi diversi)
- Implementazioni **"solo happy path"** (nessuna gestione errori/edge case)
- **Scope multi-tenant dimenticato** su un nuovo modello (manca il trait/Global Scope)
- **Segreti/chiavi** committati o loggati

---

## Checklist rapida per ogni feature prima del merge

- [ ] So **spiegare** come funziona il percorso critico di questa feature?
- [ ] Tutte le rotte/azioni nuove hanno **autorizzazione** verificata?
- [ ] Se tocca dati di business: ha lo **scope multi-tenant**? (🔗 ADR-001)
- [ ] Se è una schermata di **piattaforma**: legge da una delle **due porte** (`VistaPiattaforma` / `ParcoClienti`) e non toglie scope per conto suo? (🔗 ADR-018/037)
- [ ] Ci sono **test negativi** per i casi 🔴?
- [ ] La **suite di isolamento** è ancora verde?
- [ ] Per aree 🔴: ho fatto il **secondo passaggio di security review**?
- [ ] Nessun **segreto** committato, nessun pacchetto sospetto?
- [ ] Niente codice **non testato** lasciato indietro?
