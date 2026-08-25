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

## 1. Dashboard per ruolo (home di **tutti** i ruoli) — `/dashboard`

Vista d'ingresso: lo stato del parco a colpo d'occhio, e la strada per andarci dentro. 🔗 ADR-005, ADR-014, Funzionalità per Ruolo §3 e §4.

> **Riscritto il 25 Ago 2026 (S6).** La stesura precedente disegnava quattro KPI **più filtri e tabella**, ed è di Sprint 0: precede di due sprint l'esistenza di `/strumenti`, che quella tabella la implementa già — con ricerca, filtro per ubicazione, filtro per stato, «solo obsoleti», ordinamento su sette colonne, paginazione e il degrado privacy della colonna scadenza (🔗 ADR-020). Ridisegnarla qui sarebbe stata una **seconda superficie** che duplica l'elenco e poi ne diverge, cioè il difetto che questo progetto ha già pagato due volte.
>
> È la stessa rettifica già applicata al §3 il 17 Ago — «la schermata di destra *è* la scheda resa mobile; resta nuova la sola schermata di sinistra» — un livello più in su. Restano i **quattro numeri**, che non esistono da nessun'altra parte: l'elenco mostra il totale del paginatore, mai la ripartizione. Cambiano anche due cose nel disegno: le **etichette** sono ora quelle del Design System §4 («In regola / Azione richiesta / Non idoneo»), le stesse che ogni pallino dell'app porta nel proprio `title`; e il riquadro obsoleti dichiara **la soglia dell'Ente**, non «>10 anni», perché quella soglia è per-Ente da 🔗 ADR-014.
>
> E il titolo non dice più «home Tenant / Admin»: su `/dashboard` atterrano **tutti** i ruoli — Fortify dopo il login, lo switcher di sede a ogni cambio, la fuga da lockout.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ☰  Easy Lab        Ente: Ospedale San Giovanni            🔔 3   👤 M.R. ▼ │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                            │
│  Dashboard                                                                 │
│  Lo stato delle macchine di Ospedale San Giovanni.                         │
│                                                                            │
│  ┌────────────────┐┌────────────────┐┌────────────────┐┌────────────────┐ │
│  │ ● In regola    ││ ◐ Azione rich. ││ ■ Non idoneo   ││ ⏳ Obsoleti     │ │
│  │      124       ││       18       ││        5       ││        7       │ │
│  │                ││                ││                ││ oltre 10 anni  │ │
│  └────────────────┘└────────────────┘└────────────────┘└────────────────┘ │
│    ‹click → /strumenti?stato=verde›  ‹?stato=arancione&sortBy=scadenza›     │
│                                                                            │
│  Le prime tre coprono tutte le 147 macchine che vedi. Gli obsoleti sono    │
│  una segnalazione sull'età e non uno stato manutentivo: sono già contati   │
│  in una delle tre.                                                         │
│                                                                            │
│  ┌──────────────────────┐  ‹solo con tenants.view_all›                     │
│  │ 🏢 Piattaforma       │                                                  │
│  │ Vai alla cabina →    │                                                  │
│  └──────────────────────┘                                                  │
└──────────────────────────────────────────────────────────────────────────┘
```

**Ogni riquadro è un link, e il numero deve combaciare con le righe che si trovano arrivando.** È ciò che rende «conta» ed «elenca» la stessa regola *per chi guarda* e non solo nel codice: conteggi e filtro passano entrambi da `Strumento::scopeConStato()`, e un test segue l'href e confronta il numero col totale del paginatore. Cliccando «Azione richiesta 18» si atterra su **esattamente diciotto righe**, ordinate per scadenza più vicina — l'ordinamento del wireframe originale, sulla superficie che lo implementa già.

**I primi tre riquadri sono una partizione, il quarto no.** Verde, arancione e rosso coprono tutto il parco e non si sovrappongono (il forzato vince, 🔗 ADR-005); l'obsolescenza «non tocca il semaforo» (🔗 ADR-014), quindi una macchina obsoleta è **già contata** in una delle tre. Quattro riquadri in fila si leggono come quattro fette di una torta: la pagina lo dice in chiaro invece di lasciarlo dedurre a chi prova a sommare.

⛔ **Nessun riquadro scompone uno stato per CAUSA.** 🔗 ADR-020 legittima il *pallino* come aggregato dovuto a tutti — il Tenant non vede le righe garanzia-ricambio ma vede l'arancione che ne deriva — e **non** la sua scomposizione: un «di cui 4 da garanzie ricambio» direbbe a un Ente su `visibilita_garanzie_ricambio = nascosta` quanti pezzi sostituiti ha sulle proprie macchine, e lo direbbe senza passare da nessuno scope, perché un numero non è una riga.

**A parco vuoto una frase, non quattro zeri**: «Nessuno strumento visibile.» Quattro zeri si leggono come «il sistema è vuoto», ed è la casella vuota che dice il falso già pagata due volte. La frase è scelta per essere vera **per tutti**: il cliente nuovo, l'Ente di piattaforma di Superadmin e Developer, il Responsabile senza nodi assegnati e il Tecnico esterno senza portafoglio — «Nessuno strumento in questo Ente» sarebbe falsa per gli ultimi due, che un Ente pieno ce l'hanno, solo non è loro.

**Il perimetro si nomina** («Lo stato delle macchine di *X*», o «che segui» per chi un Ente non ce l'ha). Senza, il Superadmin legge un totale qui e un altro sulla cabina — entrambi corretti, uno per il proprio Ente e uno per tutti i clienti — e il primo screenshot in riunione fa il danno.

**Il permesso si chiede blocco per blocco** (🔗 Policy di Code Review, Schema Ruoli §6): la rotta non ha `can:` perché è l'unica pagina che ogni autenticato deve poter aprire. Chi non ha `strumenti.view` — revocabile a runtime dall'editor permessi — non vede i riquadri, **e non li fa nemmeno calcolare**.

**Ogni ruolo, senza diramazioni.** I numeri nascono da `Strumento::query()` con i global scope addosso, quindi il perimetro lo decide il dominio: Admin e Tenant sul proprio Ente, il Responsabile sul sotto-albero, il Tecnico su portafoglio ∪ assegnazione, e zero — non tutto — per un autenticato senza tenant. Il **Responsabile Reparto** vede quindi «stesso layout, KPI ristretti al sotto-albero» che la nota originale prometteva, senza che nessuno lo scriva.

**Mobile**: i riquadri si impilano (`sm:grid-cols-2 lg:grid-cols-4`).

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

## 4. Cabina di regia della piattaforma (Superadmin) — `/piattaforma`

Numeri globali, clienti, impersonazione, le leve amministrative. 🔗 ADR-001, ADR-013, ADR-018, ADR-032, Funzionalità §2.

> **Riscritto il 21 Ago 2026 (S6).** La stesura precedente elencava **un Ente per riga** con le colonne Piano e Stato ed è **antecedente ad ADR-032**: da quella decisione piano, lockout e dati fiscali appartengono all'**Account**, e un Account può avere N Enti. Una tabella per Ente ripeterebbe gli stessi valori su ogni riga dello stesso cliente, e soprattutto suggerirebbe che si possa bloccare un Ente — cosa che non esiste: a essere chiuso è il contratto. Cambiano anche l'etichetta «🧪 Laboratori» → **Sedi** (sono nodi di tipo Ente, non laboratori) e `[👁 Impersona]`, che bersaglia **una persona**, non un contratto.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Easy Lab · Piattaforma                                      🔔   👤 EasyLab ▼│
├──────────────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐      │
│  │ Ricavo mensile│ │ Clienti     │ │ Sedi         │ │ Strumenti    │      │
│  │   € 4.250     │ │     37      │ │     128      │ │    3.412     │      │
│  │ a listino ·   │ │ 12 Free ·   │ │ Enti dei     │ │ Macchine di  │      │
│  │ € 98 fermi    │ │ 25 SaaS·2 🔒│ │ clienti      │ │ tutti i clienti│    │
│  └──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘      │
│                                                                            │
│  🔍 Cerca…                    Piano [Tutti ▼]   Stato [Tutti ▼]           │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Cliente          │ Piano │ Stato        │ Sedi │ Strum. │ Azioni    │  │
│  ├────────────────────────────────────────────────────────────────────┤  │
│  │ ▸ Osp. San Giovanni│ SaaS │ ✅ Attivo   │ 3/∞  │  124   │ [👁][⚙]  │  │
│  │   P.IVA 01234567890│      │              │      │        │           │  │
│  │ ▾ Lab Rossi srl   │ Free  │ ✅ Attivo   │ 1/1  │   46   │ [👁][⚙]  │  │
│  │ ┌──────────────────────────────────────────────────────────────┐   │  │
│  │ │ Laboratorio San Raffaele                        46 strumenti │   │  │
│  │ └──────────────────────────────────────────────────────────────┘   │  │
│  │ ▸ Clinica Aurora  │ SaaS  │ 🔒 A mano   │ 2/∞  │   88   │ [👁][⚙]  │  │
│  │ ▸ Poliamb. Nord   │ SaaS  │ 🔒 Insoluto │ 1/∞  │   31   │ [👁][⚙]  │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                              ‹ 1 2 3 ›   [ 20 per pagina ]│
└──────────────────────────────────────────────────────────────────────────┘
```

**La riga è l'Account, e si espande nelle sue sedi.** L'espansione **non costa query**: le sedi della pagina sono già caricate, perché servono anche alla colonna Sedi (`n / max` del piano, `∞` se il piano non pone limite). Anti-N+1 col pattern di `ElencoStrumenti`: si pagina prima, poi si fa `whereIn` sugli id di pagina — **quattro query costanti** fra un cliente e dodici — conteggio di paginazione, pagina, sedi, strumenti — sette con i tre dei KPI, e c'è un test che congela la costanza.

**Due badge di lockout, mai uno solo** (ADR-013). `🔒 A mano` e `🔒 Insoluto` sono sorgenti **ortogonali**: `is_locked` significa «almeno una accesa», e fonderle in un unico badge — o in un unico filtro — ricrea esattamente il difetto che la separazione esiste per impedire, cioè un pagamento riuscito che riapre una porta chiusa per contenzioso. Per la stessa ragione il filtro Stato ha **tre** voci e non due.

**Chi non compare.** L'account di **EasyLab** (`accounts.di_piattaforma`), che è un fornitore di sé stesso e falserebbe tutti e quattro i KPI — sul database di sviluppo pesava 1.217 strumenti su 5.105. E i **cestinati**: account, sedi e strumenti soft-deleted restano fuori da ogni numero e da ogni riga, ricerca per nome di sede compresa.

**Un piano fuori catalogo non fa esplodere la pagina.** `accounts.piano` è una stringa senza CHECK: basta dismettere un codice dal catalogo perché delle righe restino orfane. Valgono **0 €** nel ricavo, si mostrano con un badge `fuori catalogo` e un avviso sopra la tabella — perché questa è l'unica schermata da cui quel dato si ripara, e morire proprio lì sarebbe il modo peggiore di segnalarlo.

**Il ricavo è a listino, non incassato.** Somma i prezzi di catalogo (`config/easylab.php`) dei contratti in essere, **lockout compresi** — un blocco è una porta chiusa, non una disdetta — con accanto il «di cui» di quanto non si sta incassando. La verità contabile resta Stripe, e i due possono divergere per una promo o un prezzo storico.

**Filtri in query string** (`#[Url]`): una vista filtrata si manda per link, che è come si chiede aiuto su un cliente. `sortBy`, `sortDir` e `perPage` sono ri-validati contro una whitelist **a ogni render** e non solo negli hook, perché per quella strada arrivano dal browser senza passare da `updatingXxx()`.

**Note sulle azioni.** `[👁]` = impersonazione, richiede `utenti.impersonate`, bersaglia **un membro** dell'account (link diretto se ce n'è uno solo, scelta se sono più d'uno) e avvia una sessione con **banner persistente** che dice entrambi i nomi. `[⚙]` = le leve amministrative: lockout manuale con motivo obbligatorio, visibilità delle garanzie ricambio per sede, dati fiscali. `[ + Nuovo cliente ]` = `tenants.provision` (onboarding + Free chiavi in mano). Il **lockout da Stripe è in sola lettura**: il suo inverso è un evento di pagamento, e un umano che dichiarasse «pagato» verrebbe smentito dal webhook successivo.

**Stato di attuazione (S6).** KPI, tabella, filtri ed espansione sono in produzione di codice; impersonazione UI, leve e `[ 🛡 Permessi ruoli ]` → §4.1 arrivano nei blocchi successivi dello stesso sprint.

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
| §1 Dashboard per ruolo | **tutti** i ruoli | S3 (semaforo) / **S6 — chiusa il 25 Ago 2026** |
| §2 Scheda Strumento (tab) | Admin, Tenant, Resp., Tecnico (ridotta) | S2 (anagrafica) → S3/S4 (tab) |
| §3 Mobile Tecnico | Tecnico | S4 |
| §4 Dashboard Superadmin | Superadmin, Developer | S6 |
| §4.1 Editor Permessi | Superadmin | S6 (🔗 ADR-016) |
| §5 Alberatura + Lista | Admin, Resp. Reparto | S2 |

> Questi wireframe sono la base per il **Design System** (S0.4): da lì arrivano palette, componenti Tailwind e gli stati semaforo definitivi.
