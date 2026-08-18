🎨 Wireframe Viste Chiave — Easy Lab

*Wireframe a bassa fedeltà (ASCII) delle viste fondamentali della V1, pensati per guidare l'implementazione Livewire degli Sprint 2–6. Non sono mockup grafici definitivi (palette/componenti → "Design System base", task S0.4): descrivono **struttura, gerarchia delle informazioni e flussi**. Ogni vista richiama i permessi di `../Architettura/Schema Ruoli e Permessi.md` e gli stati semaforo di ADR-005.*

> **Stato:** bozza di Sprint 0 (task S0.3). Da approvare prima di implementare le viste.

---

## 0. Convenzioni dei wireframe

- **Box ASCII** = contenitore/area. `[ Testo ]` = bottone. `( • )` = radio/toggle. `[x]` = checkbox. `▼` = dropdown. `🔍` = campo ricerca.
- **Stati semaforo** (ADR-005): 🟢 in regola · 🟠 azione richiesta · 🔴 non idoneo · `⚑` badge "forzato".
- **Visibilità per ruolo:** annotata sotto ogni vista. Le aree visibili solo a certi ruoli sono marcate `‹solo Admin/EasyLab›` ecc.
- **Mobile-first:** la vista tecnico (§3) è disegnata a larghezza singola; le altre sono desktop con note di collasso responsive.

---

## 1. Dashboard Semaforo (home Tenant / Admin)

Vista d'ingresso per Tenant e Admin: stato di salute del parco strumenti a colpo d'occhio. 🔗 ADR-005, Funzionalità §1.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ☰  Easy Lab        Ente: Ospedale San Giovanni            🔔 3   👤 M.R. ▼ │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                            │
│  Dashboard          ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌──────────────┐  │
│                     │ 🟢  124 │ │ 🟠   18 │ │ 🔴   5  │ │ ⏳ Obsoleti 7│  │
│                     │ in regola│ │ da fare │ │ fermi   │ │ (>10 anni)   │  │
│                     └─────────┘ └─────────┘ └─────────┘ └──────────────┘  │
│                                                                            │
│  Filtri: [ Stato ▼ ] [ Tipo ▼ ] [ Laboratorio ▼ ]   🔍 Cerca strumento…   │
│                                                                            │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Stato │ Strumento          │ Ubicazione        │ Prossima scadenza  │  │
│  ├────────────────────────────────────────────────────────────────────┤  │
│  │  🔴⚑  │ Autoclave AC-200   │ Lab Microbiologia │ Tar./cert. scaduta │  │
│  │  🟠   │ Centrifuga CF-12   │ Lab Analisi       │ Manut. tra 4 gg    │  │
│  │  🟠   │ Spettrofotom. S-9  │ Lab Chimica       │ Garanzia tra 12 gg │  │
│  │  🟢   │ Frigo -80 FR-3     │ Lab Biobanca      │ —                  │  │
│  │  …                                                                   │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                              ‹click riga → Scheda strumento›│
└──────────────────────────────────────────────────────────────────────────┘
```

**Note.** Ordinamento default per gravità (🔴→🟠→🟢) e scadenza più vicina. `⚑` indica stato forzato (badge cliccabile → mostra chi/quando/perché, ADR-005). Il Tenant **non** vede colonne/filtri relativi a garanzie ricambio (ADR-004). Responsabile Reparto: stesso layout ma KPI/lista ristretti al sotto-albero. Mobile: KPI in colonna, tabella → lista di card.

> **Colonna "Prossima scadenza" e garanzie ricambio** (🔗 ADR-020). La colonna nomina la fonte ("Taratura e certificazione — scaduta", "Garanzia tra 12 gg"); nei wireframe l'etichetta è abbreviata per stare nella colonna, ma il testo reale è quello di `TipoIntervento::label()`. Quando la scadenza più vicina è la **garanzia di un ricambio** e l'utente non ha `garanzie.ricambio.view`, la cella degrada a una dicitura neutra — **"Garanzia tra N gg"**, senza il nome del pezzo — mentre il **pallino resta arancione per tutti**. Il pallino è un aggregato, la colonna un dettaglio: due regole diverse sulla stessa riga, di proposito.

---

## 2. Scheda Strumento (con tab)

Cuore dell'app: tutto ciò che riguarda un singolo strumento, organizzato in **6 tab** — Panoramica (default, §2.0), Anagrafica, Interventi, Ricambi, Documenti, Garanzie. 🔗 Funzionalità §2, ADR-024.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ‹ Torna alla lista                                                         │
│                                                                            │
│  🔴⚑  Autoclave AC-200            [ Forza semaforo ▼ ] ‹perm. semaforo.force›│
│  Cod. STR-0042 · Lab Microbiologia · Installato 03/2015 · ⏳ Obsoleto      │
│                                            [ 🔳 QR ] [ ✎ Modifica ] [ ⠿ ]  │
│ ┌────────────────────────────────────────────────────────────────────────┐│
│ │ Panoramica │ Anagrafica │ ▸Interventi◂ │ Ricambi │ Documenti │ Garanzie ││
│ ├────────────────────────────────────────────────────────────────────────┤│
│ │                                                  [ + Nuovo intervento ]  ││
│ │  Stato │ Data       │ Tipo         │ Descrizione      │ Tecnico         ││
│ │  ──────┼────────────┼──────────────┼──────────────────┼──────────────── ││
│ │  ✓ Fatto│ 12/01/2026 │ Manut. ordin.│ Sostituz. guarniz│ L. Bianchi     ││
│ │  ✗ No   │ 28/05/2026 │ Taratura e c.│ Taratura annuale │ — (scaduta) 🔴 ││
│ │  ◷ Prog.│ 15/09/2026 │ Manut. full r│ Controllo press. │ G. Verdi       ││
│ │         │            │              │            [ ✓ Segna come fatto ]  ││
│ └────────────────────────────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────────────────────────┘
```

### 2.0 Tab "Panoramica" — perché il semaforo è acceso 🔗 ADR-024

Primo tab e **landing di default** della scheda. Risponde a una domanda sola: *perché questa macchina è arancione?*

```
┌────────────────────────────────────────────────────────────────────────┐
│ ▸Panoramica◂ │ Anagrafica │ Interventi │ Ricambi │ Documenti │ Garanzie │
├────────────────────────────────────────────────────────────────────────┤
│ ┌──────────────────────────┐  ┌───────────────────────────────────────┐│
│ │  ● ARANCIONE             │  │ Prossimo intervento                   ││
│ │  Azione richiesta        │  │ ◷ 15/09/2026 · Manut. full risk       ││
│ │                          │  │   Controllo pressione · G. Verdi      ││
│ │ Motivi (3)               │  │───────────────────────────────────────││
│ │ ✗ Taratura/cert. scaduta │  │ Ultimo eseguito                       ││
│ │   28/05/2026        [ › ]│  │ ✓ 12/01/2026 · Manut. ordinaria       ││
│ │ ⏳ Garanzia macchina in   │  │───────────────────────────────────────││
│ │   scadenza il 20/08/2026 │  │ Garanzia macchina                     ││
│ │                     [ › ]│  │ ⏳ scade il 20/08/2026 (fra 17 gg)     ││
│ │ ⏳ Garanzia di un compo-  │  │ Ricambi coperti: 4  ‹aggregato›       ││
│ │   nente scade 03/02/2027 │  └───────────────────────────────────────┘│
│ │        ‹testo neutro se  │  ┌───────────────────────────────────────┐│
│ │   manca garanzie.ricam-  │  │ In sintesi                            ││
│ │   bio.view›              │  │ Fornitore    MedTech S.r.l.           ││
│ └──────────────────────────┘  │ Ubicazione   Lab Microbiologia        ││
│ ┌──────────────────────────┐  │ Installato   03/2015 · ⏳ Obsoleto     ││
│ │ ⚑ Stato forzato: ROSSO   │  └───────────────────────────────────────┘│
│ │ M. Rossi · 01/08/2026    │  ┌───────────────────────────────────────┐│
│ │ "Guasto in verifica"     │  │ Statistiche                           ││
│ │ Il calcolato resta       │  │ Interventi 12 mesi        7           ││
│ │ ARANCIONE ↑ per i motivi │  │ Scaduti non fatti         1           ││
│ │ qui accanto.             │  │ Ricambi montati           4           ││
│ └──────────────────────────┘  │ Documenti allegati        9           ││
│                                └───────────────────────────────────────┘│
└────────────────────────────────────────────────────────────────────────┘
```

**Note del tab.**
- **Ogni motivo è cliccabile** `[ › ]` e porta alla riga che lo genera (tab Interventi o Garanzie). La Panoramica è il ponte fra il segnale di sintesi e la fonte di verità (ADR-005), **non** una terza verità: non calcola nulla di suo, riusa le regole già uniche del progetto.
- **Il blocco "Stato forzato" appare solo se lo stato è forzato**, e mostra *entrambi* gli stati — quello forzato che vince e quello calcolato coi suoi motivi. Una forzatura non nasconde i problemi reali: qui lo si vede, invece di leggerlo in un ADR.
- **Testo neutro sulle garanzie ricambio**: senza `garanzie.ricambio.view` il motivo dice "Garanzia di un componente…" e **non è cliccabile** — niente nome del pezzo, niente link (🔗 ADR-004/020).
- **Ogni blocco è gated dal permesso della propria area** (🔗 Schema Ruoli §6): senza `documenti.view` sparisce il contatore documenti, non l'intero pannello.
- **Empty state esplicito ovunque**: "Nessun intervento pianificato" è un'informazione, non uno spazio bianco.
- **Mobile**: i blocchi si impilano in colonna, con "Stato e motivi" sempre per primo.

### 2.1 Form "Nuovo intervento" — ricambio effettuato 🔗 ADR-021/022

```
┌────────────────────────────────────────────────────────────┐
│ Nuovo intervento — Autoclave AC-200                    [×] │
├────────────────────────────────────────────────────────────┤
│ Tipo            ▼ Manutenzione ordinaria                   │
│                   Manutenzione straordinaria               │
│                   Manutenzione full risk                   │
│                   Taratura e certificazione   ‹una voce›   │
│                   Altro                                    │
│ Descrizione     [                                        ] │
│ Data scadenza   [ 15/09/2026 ]   Tecnico ▼ G. Verdi        │
│ [x] Già eseguito   Data esecuzione [ 03/08/2026 ]          │
│ ──────────────────────────────────────────────────────────  │
│ [x] Ricambio effettuato          ‹perm. ricambio_utilizzo.create›│
│  ┌────────────────────────────────────────────────────────┐│
│  │ Nome ricambio 🔍 [ Guarnizione portello        ]       ││
│  │ Scad. garanzia   [ 03/08/2028 ]                  [ ✕ ] ││
│  ├────────────────────────────────────────────────────────┤│
│  │ Nome ricambio 🔍 [ Filtro HEPA                  ]      ││
│  │ Scad. garanzia   [ 03/02/2027 ]                  [ ✕ ] ││
│  └────────────────────────────────────────────────────────┘│
│  [ + Aggiungi ricambio ]                                   │
│ ──────────────────────────────────────────────────────────  │
│                                    [ Annulla ]  [ Salva ]  │
└────────────────────────────────────────────────────────────┘
```

**Note del form.** La checkbox "Ricambio effettuato" **non è un campo persistito**: apre e chiude il repeater; la verità è l'esistenza delle righe. Il campo nome è un **autocomplete sul catalogo** (collega-o-crea, ADR-008/022): il codice costruttore non è richiesto. **La scadenza garanzia è obbligatoria per ogni riga** — è il dato che accende il semaforo (ADR-020). Deselezionare la checkbox in modifica **non cancella** righe già salvate: la rimozione è esplicita, riga per riga (`✕`), perché una spunta tolta per sbaglio non deve distruggere storico. Righe e garanzie si salvano **nella stessa transazione** dell'intervento.

*Precisazioni dall'attuazione (9 Ago 2026), tutte emerse dalla prima prova a mano:*

- **Il campo Tecnico è obbligatorio** (🔗 ADR-028): un intervento è sempre di qualcuno. Nel select il segnaposto «— Scegli un assegnatario —» è `disabled` — serve solo perché, aprendo uno dei 4178 interventi storici senza assegnatario, il campo possa mostrare «non ancora scelto» invece del primo tecnico dell'elenco, che sarebbe un'assegnazione fatta da un default.
- **La Descrizione diventa facoltativa quando ci sono ricambi** e vale «Sostituzione ricambio»: registrare un pezzo non deve costare anche una frase. Senza ricambi resta obbligatoria — un intervento qualunque descritto come una sostituzione sarebbe falso in tabella e in Panoramica.
- **Riaprendo un intervento che ha già ricambi la checkbox è accesa.** Resta stato di UI e non un campo persistito, ma il suo valore iniziale deve *riflettere* l'esistenza delle righe: spenta, le nascondeva, e chi guardava concludeva che i suoi dati fossero spariti.
- **«Scad. garanzia» accetta anche date passate** su un inserimento storico: il confine è la data di *montaggio*, non oggi.
- Il combobox mostra i suggerimenti man mano che si scrive e segnala solo il caso che la lista non può dire, «Nuovo ricambio: verrà creato». Vincoli e trappole del componente: Design System §5.6.

**Contenuto dei tab.**
- **Panoramica ‹default›:** stato del semaforo **coi motivi**, prossimo/ultimo intervento, garanzie, sintesi anagrafica, statistiche della macchina (§2.0, ADR-024).
- **Anagrafica:** modello, parametri tecnici, data installazione, ubicazione corrente, **fornitore** (uno, obbligatorio nel form — ADR-023), storico spostamenti (`spostamenti.view`).
- **Interventi:** lista attività passate (fatto/non fatto) + future pianificate; spunta "Fatto" (`interventi.complete`); fonte di verità del semaforo (ADR-005). Tipologie chiuse da ADR-021.
- **Ricambi:** ricambi montati (catalogo + autocomplete sul **nome**, ADR-008/022); riga collegata all'intervento che l'ha generata; scadenza garanzia del pezzo ‹solo con `garanzie.ricambio.view`›; ricerca incrociata "dov'è montato".
- **Documenti:** allegati su bucket privato B2 (ADR-009/025); upload/download solo autenticato; export PDF.
- **Garanzie ‹solo Admin/EasyLab›:** garanzia macchina + garanzie ricambio (motore sdoppiato, ADR-004). **Tab nascosto a Tenant e Tecnico.** *Rettifica S3: il tab è in realtà visibile in sola lettura anche a Tenant/Tecnico, che hanno `garanzie.macchina.view`; a essere nascoste sono le **righe** `soggetto = ricambio`, per scope.* *Seconda rettifica, 8 Ago 2026 (ADR-027): quelle righe sono nascoste al **solo Tenant**. Il Tecnico le vede e le gestisce — è chi monta il pezzo — con ogni scrittura tracciata sul canale `audit`.* ~~Sotto-sezione "Letture contaore"~~ — **rimossa** (ADR-019).

**Note.** Il pulsante "Forza semaforo" compare solo con `semaforo.force`. Le righe garanzia-ricambio sono filtrate da `garanzie.ricambio.view` (ADR-004) — ma **contribuiscono comunque al pallino** mostrato in testa alla scheda (ADR-020). Mobile: i tab diventano un menù `▼` o scroll orizzontale.

---

## 3. Vista Mobile Tecnico

Interfaccia sul campo, mobile-first: scansiona QR → vedi storico → chiudi intervento. 🔗 ADR-003/007.

```
┌───────────────────────┐     ┌───────────────────────┐
│ Easy Lab    👤 Tecnico│     │ ‹ AC-200    🔴 Autocl.│
├───────────────────────┤     ├───────────────────────┤
│                       │     │ Lab Microbiologia     │
│      ┌─────────┐      │     │ Cod. STR-0042         │
│      │  [▦▦▦]  │      │     │───────────────────────│
│      │  [▦ ▦]  │      │     │ ▸ Intervento assegnato│
│      │  [▦▦▦]  │      │     │  Controllo pressione  │
│      └─────────┘      │     │  Scad. 15/09/2026     │
│   Inquadra il QR      │     │                       │
│                       │     │  [ ✓ Segna come fatto]│
│  [ 📷 Scansiona QR ]  │     │  [ + Aggiungi ricambio]│
│                       │     │  [ 🛈 Storico attività]│
│ ─ oppure ─            │     │  [ 📎 Allega foto/doc]│
│  I miei interventi ▾  │     │                       │
│  • AC-200 (oggi)      │     │                       │
│  • CF-12 (dom.)       │     │  Report fine lavoro:  │
│                       │     │  ┌───────────────────┐│
│                       │     │  │ note…             ││
│                       │     │  └───────────────────┘│
│                       │     │  [   Chiudi intervento]│
└───────────────────────┘     └───────────────────────┘
   schermata iniziale            dopo scansione/selezione
```

> **Rettifica del 17 Ago 2026 (S4 blocco 10).** Questa vista **non è stata costruita come schermata separata**, e il motivo è nei documenti stessi: §1, §2 e §5 prevedono già il responsive («tabella → lista di card», «i tab diventano un menù ▼ o scroll orizzontale», «albero in drawer ☰»), e 🔗 ADR-003 esiste per affermare che la scheda vive **in un posto solo, con una sola catena di autorizzazione**. La schermata di destra qui sotto *è* la scheda §2 resa mobile: righe che diventano card, azioni a tutta larghezza, nessun markup duplicato. Resta nuova la sola schermata di **sinistra** — QR e «I miei interventi» — che non esiste altrove. Conseguenza voluta: `/q/{token}` continua a portare **tutti** su `strumenti.show`, senza diramare per ruolo, e ADR-003 resta intatto invece di essere derogato.

> **Terza rettifica, stessa data.** La nota «Niente garanzie ricambio» e il rimando al «nodo di permessi di §4.4 ancora da sciogliere» sono **superati da 🔗 ADR-027** (8 Ago 2026): il Tecnico gestisce le garanzie dei pezzi che monta — è la fonte del dato — e il controllo è la traccia sul canale `audit`, non la cecità. Il tab Ricambi della scheda gliele mostra.

**Note.** Accesso post-QR via URL firmata → login se necessario → scheda **solo se autorizzato** (mai dati senza auth, ADR-003). Il tecnico vede solo strumenti in portafoglio ∪ assegnazione (ADR-007); ogni accesso loggato. Niente garanzie ricambio. Azioni disponibili filtrate per permesso (`interventi.complete`, `ricambio_utilizzo.create`, `documenti.upload`). L'azione "⏱ Lettura contaore" è **rimossa** (ADR-019). "+ Aggiungi ricambio" chiede **nome e scadenza garanzia**, come nel form desktop (ADR-022) — con il nodo di permessi di §4.4 dello Schema Ruoli ancora da sciogliere.

---

## 4. Dashboard Superadmin (EasyLab)

Cabina di regia: numeri globali, clienti, impersonation, gestione permessi. 🔗 ADR-001, Funzionalità §2.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Easy Lab · SUPERADMIN                                       🔔   👤 EasyLab ▼│
├──────────────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐      │
│  │ 💶 MRR       │ │ 🏢 Clienti   │ │ 🧪 Laboratori│ │ 🔧 Strumenti │      │
│  │  € 4.250 /m  │ │   37 (2 🔒)  │ │     128      │ │    3.412     │      │
│  └──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘      │
│                                                                            │
│  Clienti                       🔍 Cerca…   [ + Nuovo cliente (provision) ] │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Ente              │ Piano    │ Stato    │ Strum. │ Azioni            │  │
│  ├────────────────────────────────────────────────────────────────────┤  │
│  │ Osp. San Giovanni │ SaaS     │ ✅ Attivo│  124   │ [👁 Impersona][⚙] │  │
│  │ Lab Rossi srl     │ Free     │ ✅ Attivo│   46   │ [👁 Impersona][⚙] │  │
│  │ Clinica Aurora    │ SaaS     │ 🔒 Lockout│  88   │ [👁 Impersona][⚙] │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                                                            │
│  Strumenti laterali:                                                       │
│   [ 🛡 Permessi ruoli ]  [ 📜 Audit log ]  [ 💳 Billing/Stripe ]           │
└──────────────────────────────────────────────────────────────────────────┘
```

**Note.** `[👁 Impersona]` richiede `utenti.impersonate`; avvia sessione con **banner persistente** di ripristino, loggata (ADR/activitylog). `[ + Nuovo cliente ]` = `tenants.provision` (onboarding + Free chiavi in mano). Riga in `🔒 Lockout` = insoluto (ADR-013): dati conservati e consultabili solo qui. `[ 🛡 Permessi ruoli ]` → vista §4.1.

### 4.1 Editor Permessi Ruolo ‹sub-vista, `roles.manage`› — 🔗 ADR-016

Matrice ruolo×permesso editabile a runtime; le righe 🔒 sono in sola lettura.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ‹ Dashboard   Permessi ruoli                       [ Ripristina default ]  │
├──────────────────────────────────────────────────────────────────────────┤
│ Permesso                    │ Admin │ Resp.Rep. │ Tenant │ Tecnico        │
│ ────────────────────────────┼───────┼───────────┼────────┼─────────────── │
│ strumenti.create            │  [x]  │   [x]     │  [ ]   │  [ ]           │
│ strumenti.move              │  [x]  │   [x]     │  [ ]   │  [ ]           │
│ semaforo.force              │  [x]  │   [x]     │  [ ]   │  [ ]           │
│ interventi.complete         │  [x]  │   [x]     │  [ ]   │  [x]           │
│ documenti.export_pdf        │  [x]  │   [x]     │  [x]   │  [ ]           │
│ 🔒 garanzie.ricambio.view   │  ✓    │   ✓       │  ✗     │  ✗  (bloccato) │
│ 🔒 utenti.impersonate       │  ✗    │   ✗       │  ✗     │  ✗  (bloccato) │
│ …                                                                          │
│                                              [ Annulla ]  [ Salva modifiche]│
└──────────────────────────────────────────────────────────────────────────┘
```

**Note.** Modifica solo il *cosa* (permesso), mai lo *scope* (ADR-016). Righe 🔒 = set bloccato (mostrato `✓/✗` non editabile). Ogni salvataggio loggato in `activity_log`. Developer/Superadmin non compaiono come colonne editabili (permessi pieni per definizione).

---

## 5. Navigazione Alberatura + Lista Strumenti

Hub di navigazione: albero Ente→Dipartimento→Sottolaboratorio a sinistra, strumenti filtrabili a destra. 🔗 ADR-006, Funzionalità §1.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ☰  Easy Lab        Ente: Ospedale San Giovanni            🔔   👤 Admin ▼  │
├───────────────────────────┬────────────────────────────────────────────────┤
│ Alberatura                │ Strumenti                  [ + Nuovo strumento ]│
│ 🔍 filtra nodi…           │ Filtri: [Stato▼][Tipo▼]  🔍 codice/nome…       │
│ ▾ 🏢 Ospedale S.Giovanni  │ ┌────────────────────────────────────────────┐ │
│   ▾ 📁 Dip. Diagnostica   │ │St│ Codice  │ Nome           │ Ubicaz.│ Scad.│ │
│     • 🧪 Lab Analisi      │ ├──┼─────────┼────────────────┼────────┼──────┤ │
│     • 🧪 Lab Microbiol. ◂ │ │🔴│STR-0042 │ Autoclave AC-200│ Microb.│ scad.│ │
│   ▾ 📁 Dip. Ricerca       │ │🟠│STR-0107 │ Centrifuga CF-12│ Analisi│ 4 gg │ │
│     • 🧪 Lab Chimica      │ │🟢│STR-0211 │ Frigo -80 FR-3  │ Biobanca│ —   │ │
│     • 🧪 Lab Biobanca     │ │ …                                          │ │
│   ▾ 📁 Dip. Chirurgia     │ └────────────────────────────────────────────┘ │
│                           │              ‹selezione nodo filtra la lista›   │
│ [ + Aggiungi nodo ]       │                                  [ ⬇ Import CSV]│
└───────────────────────────┴────────────────────────────────────────────────┘
```

**Note.** L'albero è dinamico (profondità libera, `unita_organizzativa.parent_id`, ADR-006). Selezionando un nodo la lista si filtra sul suo sotto-albero. Il **Responsabile Reparto** vede solo il proprio sotto-albero (scope, ADR-006). `[ + Aggiungi nodo ]`/`[ + Nuovo strumento ]` compaiono con i relativi permessi `*.create`. `[ ⬇ Import CSV ]` = onboarding massivo (STRETCH S2). Mobile: albero in drawer `☰`, lista a tutta larghezza.

---

## 6. Mappa viste → ruoli & sprint

| Vista | Ruoli principali | Sprint di build |
|---|---|---|
| §1 Dashboard Semaforo | Tenant, Admin, Resp. Reparto | S3 (semaforo) / S6 (rifinitura per ruolo) |
| §2 Scheda Strumento (tab) | Admin, Tenant, Resp., Tecnico (ridotta) | S2 (anagrafica) → S3/S4 (tab) |
| §3 Mobile Tecnico | Tecnico | S4 |
| §4 Dashboard Superadmin | Superadmin, Developer | S6 |
| §4.1 Editor Permessi | Superadmin | S6 (🔗 ADR-016) |
| §5 Alberatura + Lista | Admin, Resp. Reparto | S2 |

> Questi wireframe sono la base per il **Design System** (S0.4): da lì arrivano palette, componenti Tailwind e gli stati semaforo definitivi.
