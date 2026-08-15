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
  6. `User::ente()` — «di chi è quest'utente» va risposto anche dentro un global scope.

  Il criterio che li accomuna, e che vale per il prossimo: **il permesso governa il dettaglio mostrato, non l'integrità del dato**. Ognuno va con un test negativo che verifichi che dal risultato **non trapeli** il dato protetto — a maggior ragione nella Panoramica (🔗 ADR-024), dove il motivo del semaforo è testo in chiaro e non un pallino.
- **Viste che compongono più aree** (Panoramica, dashboard S6): il permesso va applicato **blocco per blocco**, mai una volta sola in testa alla vista — 🔗 ADR-024.
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
- [ ] Ci sono **test negativi** per i casi 🔴?
- [ ] La **suite di isolamento** è ancora verde?
- [ ] Per aree 🔴: ho fatto il **secondo passaggio di security review**?
- [ ] Nessun **segreto** committato, nessun pacchetto sospetto?
- [ ] Niente codice **non testato** lasciato indietro?
