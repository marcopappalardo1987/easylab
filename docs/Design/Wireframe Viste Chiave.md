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

🔗 **Whitelist dell'assegnatario aggiornata da ADR-038.** Il select offre le persone vive della sede della macchina, qualunque sia il ruolo, più i tecnici EasyLab che hanno **quella sede** nel portafoglio. Non offre più tutti i tecnici di piattaforma a tutti i clienti; form e validazione consumano la stessa query.

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
│  │ ★ │ Cliente      │ Piano │ Stato       │ Sedi │ Strum. │ Azioni  │  │
│  ├────────────────────────────────────────────────────────────────────┤  │
│  │ ★ ▸ Osp. San Giov. │ SaaS │ ✅ Attivo  │ 3/∞  │  124   │ [👁][⚙]  │  │
│  │   P.IVA 01234567890│      │              │      │        │           │  │
│  │ ☆ ▾ Lab Rossi srl │ Free  │ ✅ Attivo   │ 1/1  │   46   │ [👁][⚙]  │  │
│  │ ┌──────────────────────────────────────────────────────────────┐   │  │
│  │ │ Laboratorio San Raffaele                        46 strumenti │   │  │
│  │ └──────────────────────────────────────────────────────────────┘   │  │
│  │ ★ ▸ Clinica Aurora│ SaaS  │ 🔒 A mano   │ 2/∞  │   88   │ [👁][⚙]  │  │
│  │ ☆ ▸ Poliamb. Nord │ SaaS  │ 🔒 Insoluto │ 1/∞  │   31   │ [👁][⚙]  │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                              ‹ 1 2 3 ›   [ 20 per pagina ]│
└──────────────────────────────────────────────────────────────────────────┘
```

**La riga è l'Account, e si espande nelle sue sedi.** L'espansione **non costa query**: le sedi della pagina sono già caricate, perché servono anche alla colonna Sedi (`n / max` del piano, `∞` se il piano non pone limite). Anti-N+1 col pattern di `ElencoStrumenti`: si pagina prima, poi si fa `whereIn` sugli id di pagina — **quattro query costanti** fra un cliente e dodici — conteggio di paginazione, pagina, sedi, strumenti — sette con i tre dei KPI, e c'è un test che congela la costanza.

**La ★ della prima colonna è PERSONALE, e non è un attributo del cliente** *(29 Ago 2026 — 🔗 ADR-037)*. Segna un cliente fra i **preferiti di chi sta guardando**, e alimenta il terzo modo del perimetro del Parco clienti (§8), che prima era una `<select multiple>` da ricomporre a ogni visita. Due Superadmin sulla stessa pagina vedono quindi stelle **diverse**: sta in un pivot per-utente (`clienti_preferiti`, ERD §4.3) e non in una colonna di `accounts`, che renderebbe la preferenza una proprietà del rapporto commerciale. Per la stessa ragione la colonna **non è ordinabile** — ordinare per un dato personale metterebbe la stessa riga in due posti per due colleghi — e non mostra né conteggi né nomi di chi ha segnato. Lo stato è leggibile **senza colore** (★ pieno / ☆ vuoto, più `aria-pressed`): è un requisito del Design System, non un vezzo. Non costa una query per riga: gli id preferiti si leggono una volta sola per pagina.

**Due badge di lockout, mai uno solo** (ADR-013). `🔒 A mano` e `🔒 Insoluto` sono sorgenti **ortogonali**: `is_locked` significa «almeno una accesa», e fonderle in un unico badge — o in un unico filtro — ricrea esattamente il difetto che la separazione esiste per impedire, cioè un pagamento riuscito che riapre una porta chiusa per contenzioso. Per la stessa ragione il filtro Stato ha **tre** voci e non due.

**Chi non compare.** L'account di **EasyLab** (`accounts.di_piattaforma`), che è un fornitore di sé stesso e falserebbe tutti e quattro i KPI — sul database di sviluppo pesava 1.217 strumenti su 5.105. E i **cestinati**: account, sedi e strumenti soft-deleted restano fuori da ogni numero e da ogni riga, ricerca per nome di sede compresa.

**Un piano fuori catalogo non fa esplodere la pagina.** `accounts.piano` è una stringa senza CHECK: basta dismettere un codice dal catalogo perché delle righe restino orfane. Valgono **0 €** nel ricavo, si mostrano con un badge `fuori catalogo` e un avviso sopra la tabella — perché questa è l'unica schermata da cui quel dato si ripara, e morire proprio lì sarebbe il modo peggiore di segnalarlo.

**Il ricavo è a listino, non incassato.** Somma i prezzi di catalogo (`config/easylab.php`) dei contratti in essere, **lockout compresi** — un blocco è una porta chiusa, non una disdetta — con accanto il «di cui» di quanto non si sta incassando. La verità contabile resta Stripe, e i due possono divergere per una promo o un prezzo storico. *⚠️ Aggiornato il 28 Ago 2026: i prezzi non vengono più da `config/easylab.php` ma dalla tabella `piani` (🔗 ADR-035, §7.1). La frase resta vera parola per parola — cambia solo dove il catalogo abita — ma la divergenza da Stripe smette di essere solo teorica: da quel giorno esiste una schermata che scrive su entrambi, quindi un salvataggio interrotto a metà la produce davvero, ed è per questo che il listino porta un confronto esplicito.*

**Filtri in query string** (`#[Url]`): una vista filtrata si manda per link, che è come si chiede aiuto su un cliente. `sortBy`, `sortDir` e `perPage` sono ri-validati contro una whitelist **a ogni render** e non solo negli hook, perché per quella strada arrivano dal browser senza passare da `updatingXxx()`.

**Note sulle azioni.** `[👁]` = impersonazione, richiede `utenti.impersonate`, bersaglia **un membro** dell'account (link diretto se ce n'è uno solo, scelta se sono più d'uno) e avvia una sessione con **banner persistente** che dice entrambi i nomi. `[⚙]` = le leve amministrative: lockout manuale con motivo obbligatorio, visibilità delle garanzie ricambio per sede, dati fiscali. `[ + Nuovo cliente ]` = `tenants.provision` (onboarding + Free chiavi in mano). *(29 Ago 2026: la modale chiede due nomi invece di uno — **ragione sociale** e, facoltativo, **nome della prima sede**. Fino a quel giorno il valore era uno solo e finiva in tutti e due i posti, così la colonna SEDE del Parco (§8) ripeteva la ragione sociale. ⚠️ Il nodo Ente **non** è una sede «di default» che si possa non creare: è la radice del tenant — `strumenti.tenant_id`, `interventi.tenant_id`, `garanzie.tenant_id` e `ricambi.tenant_id` puntano lì — quindi un Account senza Enti non è uno stato rappresentabile, non ci sarebbe un posto in cui mettere la prima macchina. Ciò che si è tolto è l'**imposizione del nome**, non il nodo. Aggiungendo invece una sede a un cliente che esiste già il campo resta uno solo, perché lì quel nome È la sede.)* Il **lockout da Stripe è in sola lettura**: il suo inverso è un evento di pagamento, e un umano che dichiarasse «pagato» verrebbe smentito dal webhook successivo.

**Stato di attuazione (S6).** KPI, tabella, filtri ed espansione sono in produzione di codice; impersonazione UI, leve e `[ 🛡 Permessi ruoli ]` → §4.1 arrivano nei blocchi successivi dello stesso sprint.

> *Aggiornamento del paragrafo qui sopra: le tre cose che rimandava — impersonazione UI (`OffreImpersonazione`), leve amministrative (`AmministraAccount`, `FissaVisibilitaSede`, `ProvisionaCliente`) ed editor permessi — sono arrivate dentro S6, come previsto.*
>
> **Cosa la cabina ha guadagnato il 28 Ago 2026 (S7), e cosa NON ha guadagnato.** Il disegno qui sopra resta valido: nessuno dei quattro numeri, nessuna colonna e nessun badge cambia. Si aggiungono tre cose, tutte sopra o dentro la barra dei filtri già disegnata.
>
> **Una fascia di grafici fra i KPI e i filtri** — «Nuovi clienti per mese» a barre su dodici mesi, «Clienti per piano» come barra di composizione, e tre sparkline cumulate (clienti, sedi, strumenti) con accanto il «+N in 12 mesi». Sono **gli stessi dati dei quattro numeri letti nel tempo**: stessa porta `VistaPiattaforma`, stesso `PerimetroClienti`, tre query costanti — non una seconda sorgente. Stanno **dopo** l'avviso sui piani fuori catalogo perché quell'avviso è un'azione da fare e un grafico è una lettura. 🔴 **Il ricavo non riceve un andamento, e la pagina lo dichiara**: `accounts.piano` è lo stato di *oggi*, e ricostruire l'MRR di sei mesi fa applicando il piano attuale alle date d'ingresso darebbe una curva plausibile e falsa. Al suo posto c'è la composizione al presente, che è vera. Ogni voce porta **etichetta e glifo** oltre al colore: in tema scuro verde↔arancione scendono a ΔE 6,9 (🔗 ADR-034, DS §2.5). E una serie tutta a zero **non riceve una sparkline** — la linea piatta di dodici mesi vuoti è identica a quella di 5.000 strumenti fermi da un anno, cioè un'assenza di dato che si legge come un dato.
>
> **Due bottoni di esportazione, `[ CSV ]` e `[ PDF ]`, in fondo alla riga dei filtri.** Non hanno un `@can` proprio e non hanno una rotta propria, e sono due decisioni: il permesso di questa pagina (`tenants.view_all`) *è* già quello dell'export, e aggiungerne un secondo — `documenti.export_pdf`, che sembrerebbe naturale — lo renderebbe **revocabile a runtime** da §4.1, perché quel permesso non è nel set bloccato mentre `tenants.view_all` sì; una rotta `/piattaforma/clienti.csv` dovrebbe invece rileggere e ri-validare i filtri una seconda volta, ed è esattamente lì che nasce «il file esporta tutto mentre la pagina ne mostra dodici». Sotto ai bottoni la pagina dichiara l'invariante: **l'esportazione segue i filtri ma non si ferma alla pagina**, perché la paginazione non è un filtro di privacy. Il CSV passa da `CsvSicuro` (le celle che cominciano per `=`, `+`, `-` o `@` sono formule per Excel, intestazioni comprese). ⚠️ Tetto dichiarato: il file si costruisce tutto in memoria, e intorno ai 5.000 account questo meccanismo va sostituito da un job in coda, non allargato.
>
> **L'avviso sui piani fuori catalogo acquista la via d'uscita**: accanto a «Mostrali» c'è ora «Vai al listino», gatato su `billing.manage_global`. Senza, l'avviso era una diagnosi senza cura — chi lo leggeva non aveva da lì nessuna strada verso l'unica schermata che ripara il problema (§7.1).
>
> La **sub-nav di piattaforma è a cinque voci**: Clienti · Registro di audit · Ruoli e permessi · **Piani** · Errori. *(⚠️ Aggiornato il 29 Ago 2026: sono **sei**, con «Parco clienti» inserito fra Piani ed Errori — §8. La regola di presentazione regge invariata e per la stessa ragione: l'ultima voce resta quella del solo Developer, e la fila che il Superadmin vede continua a finire dove finisce il suo insieme di permessi. La voce nuova non porta un permesso nuovo: è `tenants.view_all`, lo stesso di «Clienti».)* Ogni voce si filtra sul proprio permesso, e sono quattro permessi diversi per cinque voci. «Piani» sta **in mezzo e non in fondo**, ed è una decisione di presentazione con una ragione: l'ultima resta così quella del solo Developer (`system.logs.view`), e la fila che il Superadmin vede finisce dove finisce il suo insieme di permessi invece di avere un buco in mezzo.

> **Aggiornamento ADR-038 (30 Ago): la sub-nav è ora a sette voci** — Clienti · Registro di audit · Ruoli e permessi · Piani · Parco clienti · **Tecnici** · Errori. «Tecnici» usa `tenants.view_all`, non `utenti.view`; Errori resta l'ultima voce e la sola riservata al Developer.

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

⚠️ **Questo wireframe disegna l'albero già dentro un Ente, e l'omissione è costata una segnalazione.** L'alberatura organizza l'**interno** di un Ente e non ne crea mai uno: sa fare Dipartimenti e Sotto-laboratori, e ogni creazione vuole un padre. Un Ente nasce **solo dal provisioning** (§4, «＋ Nuovo cliente» e «＋ Ente» per riga). Al **livello radice** — quando l'utente non ha un'unica radice visibile e la lista si intitola «Enti» — la pagina non ha quindi alcun pulsante: fino al 27 Ago 2026 era un vicolo cieco muto, e ci finiva dentro soprattutto il **Developer**, che non ha un Ente proprio (🔗 ADR-018 → `TenantScope` fail-closed) e vedeva la pagina interamente vuota. Da quella data il livello radice porta un rimando alla cabina, **chiuso dietro `tenants.provision`**: indicare a un Admin una pagina che gli risponderebbe 403 sarebbe la seconda strada senza uscita invece della via d'uscita dalla prima. *Limite dichiarato, e c'è un test che lo fissa*: il rimando vive alla sola radice, quindi il **Superadmin** — che un Ente ce l'ha e viene portato dentro all'ingresso — non lo vede mai; per lui la Piattaforma è già in barra laterale.

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
| §7.1 Listino dei piani — `/piattaforma/piani` | Superadmin, Developer (`billing.manage_global`) | **S7 — 28 Ago 2026** (🔗 ADR-035) |
| §7.2 Registrazione pubblica — `/registrati` | **nessuno**: chi la apre non esiste ancora | **S7 — 28 Ago 2026** (🔗 ADR-012, ADR-032) |
| §8 Parco clienti — `/piattaforma/parco` (+ `/scadenzario`, `/ricambi`) | Superadmin, Developer (`tenants.view_all`), **in sola lettura** | **29 Ago 2026** (🔗 ADR-037) |
| §9.1 Persone dell'Ente — `/utenti` | Admin e chi riceve i quattro `utenti.*`; solo Ente corrente | **30 Ago 2026** (🔗 ADR-038) |
| §9.2 Tecnici EasyLab — `/piattaforma/tecnici` | Superadmin, Developer (`tenants.view_all`) | **30 Ago 2026** (🔗 ADR-038) |
| Archivio documentale d'Ente — `/documenti` | tutti con `documenti.view`; l'indice PDF vuole **anche** `documenti.export_pdf` | **S7** (🔗 ADR-026/031) |
| Scadenzario — `/scadenzario` | tutti con `interventi.view` | **S7** (🔗 ADR-005/030) |
| Abbonamento — `/abbonamento` | chi può `manage` l'Account, **anche in lockout** | **S7** (🔗 ADR-013/032) |
| Marchio email dell'Ente — `/anagrafica/marchio` | Admin (`unita_organizzativa.update`) | **S7** (🔗 ADR-033) |

> **Aggiornata il 28 Ago 2026 (S7), con sei righe e nessun disegno nuovo per quattro di esse.** Le due schermate che ricevono un wireframe vero stanno in §7, e il criterio è scritto lì. Le altre quattro non lo ricevono per la ragione che questo documento applica dal 25 Ago: **`/documenti` e `/scadenzario` sono la stessa forma di §5** — filtri in query string, tabella paginata, empty state esplicito, `tabella-a-card` sotto i 640px — e ridisegnarle sarebbe una seconda copia dello stesso disegno destinata a divergerne. Non è una deduzione di questo documento: `Scadenzario` cita **§5** nel proprio docblock ed è il codice a dichiarare da dove prende la forma. `/anagrafica/marchio` è un form con due campi e un'anteprima. E `/abbonamento` ha un layout banale — un titolo, tre voci di riepilogo, una card — mentre tutto ciò che ha di non ovvio è **autorizzazione e non struttura**: sta fuori dal gruppo protetto perché un moroso deve poter pagare, quindi non ha `can:` di rotta e le sue quattro ricadute (impersonazione, portale disponibile, piano gratuito, nessun portale) sono rami di testo. Quella materia è già scritta dove serve — commento della rotta in `routes/web.php` e `Tech Stack §4` — e un box ASCII la nasconderebbe invece di dirla.
>
> **Aggiunta il 29 Ago 2026: il Parco clienti, che il wireframe se lo guadagna** (§8). Non è la forma di §5 allargata, ed è il criterio di questo documento a chiederlo: ha un **filtro di perimetro a tre modi** che decide *di chi* sono le righe, due colonne — cliente e sede — che su una vista cross-cliente sono una difesa e non una comodità, e un'ultima colonna con **quattro esiti diversi**. Sono tre rotte, non una: la fila di schede è un partial condiviso (`x-parco.nav`), e i tre componenti restano disgiunti.
>
> **Le due lavorazioni di S7 che non compaiono in tabella non sono viste**: i grafici e le esportazioni della cabina stanno **dentro §4**, e l'alert di obsolescenza non ha schermate proprie — entra in un'email e nella campanella già esistenti.

---

## 7. Le due schermate di S7 che meritano un disegno (28 Ago 2026)

> **Perché in coda e non in mezzo.** I numeri di sezione di questo documento sono **citati dal codice**: `Cabina`, `Home`, `Scadenzario`, `CampoHome`, `app.css` e una decina di test scrivono «Wireframe §1/§3/§4/§5» nei propri docblock. Inserire una sezione fra §4 e §5 rinumererebbe §5 e §6 e renderebbe **false una dozzina di citazioni in un colpo solo**, in silenzio, perché nessun test le controlla. La sezione va quindi in fondo, e la numerazione resta un indirizzo stabile invece di una posizione.
>
> **Perché solo due, su sei viste nuove.** Un wireframe si guadagna quando la struttura non si deduce dal resto del documento. Queste due lo fanno: la prima ha una **colonna che mostra due verità affiancate** e non un dato, la seconda non è una schermata ma **quattro schermate con quattro gate diversi**, ed è la superficie dove è vissuto il difetto più grave di tutto lo sprint. Per le altre basta la riga in §6, con la motivazione scritta lì.

### 7.1 Listino dei piani ‹`billing.manage_global`› — `/piattaforma/piani` 🔗 ADR-035

Da qui un piano nasce, cambia prezzo e viene archiviato — **e lo stesso gesto arriva su Stripe** (l'«opzione A» decisa da Marco il 27 Ago). Fino a quel giorno il listino era `config('easylab.piani.catalogo')`: cambiare una cifra voleva dire un commit, un deploy e uno sviluppatore.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Easy Lab · Piattaforma                                    🔔  👤 EasyLab ▼│
│ Clienti │ Registro di audit │ Ruoli e permessi │ ▸Piani◂ │ Errori ‹Dev.› │
├──────────────────────────────────────────────────────────────────────────┤
│  Piani                                                                     │
│  Da qui si crea un piano, se ne cambia il prezzo, e lo stesso gesto arriva │
│  su Stripe.                                                                │
│  ┌──────────────────────────────────────────────────────────────────────┐ │
│  │ Cambiare prezzo crea un price NUOVO e archivia il vecchio: chi è già  │ │
│  │ abbonato RESTA AL SUO. Un piano non si cancella — si archivia.        │ │
│  └──────────────────────────────────────────────────────────────────────┘ │
│  ┌──────────────────────────────────────────────────────────────────────┐ │
│  │ Il listino NON È STATO CONFRONTATO con  [ Confronta con Stripe ]      │ │
│  │ Stripe in questa pagina.                                              │ │
│  └──────────────────────────────────────────────────────────────────────┘ │
│                                                                            │
│  Il listino                                              [ Nuovo piano ]   │
│  ┌──────────────────────────────────────────────────────────────────────┐ │
│  │ Piano      │ Prezzo listino │ Enti    │Clienti│ Stripe    │ Azioni    │ │
│  ├──────────────────────────────────────────────────────────────────────┤ │
│  │ Free       │    0,00 EUR    │    1    │  12   │ niente,   │ [Modifica]│ │
│  │ `free`     │    al mese     │         │       │ per defi- │           │ │
│  │ ⟨gratuito⟩ ⟨predefinito⟩             │       │ nizione   │           │ │
│  ├──────────────────────────────────────────────────────────────────────┤ │
│  │ SaaS       │   49,00 EUR    │    5    │  25   │ ✅ sincro- │ [Modifica]│ │
│  │ `saas`     │    al mese     │         │       │ nizzato   │ [Sincro-  │ │
│  │            │                │         │       │ 28/08 9:12│  nizza]   │ │
│  │            │                │         │       │ price_1Ab…│ [Aggancia │ │
│  │            │                │         │       │ +2 price  │  un price]│ │
│  │            │                │         │       │  storici  │ [Archivia]│ │
│  │            │                │         │       │ ⚠ divergente su prezzo│ │
│  │            │                │         │       │  listino 49,00 →      │ │
│  │            │                │         │       │  Stripe   39,00       │ │
│  ├──────────────────────────────────────────────────────────────────────┤ │
│  │ Starter    │   19,00 EUR    │    2    │   0   │ da sincro-│ [Modifica]│ │
│  │ `starter`  │    al mese     │         │       │ nizzare   │ …         │ │
│  │ ⟨archiviato⟩                          │       │           │ [Riattiva]│ │
│  └──────────────────────────────────────────────────────────────────────┘ │
│                                                                            │
│  ⚠️ Piani presenti sui clienti ma non a listino  ‹il blocco non c'è, se   │
│                                                    non ce n'è nessuno›     │
│  ┌──────────────────────────────────────────────────────────────────────┐ │
│  │ Codice fuori catalogo       │  Clienti    │              In cabina    │ │
│  ├──────────────────────────────────────────────────────────────────────┤ │
│  │ `pro`                       │     3       │        Mostra i clienti → │ │
│  └──────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

🔴 **La colonna «Stripe» è la ragione per cui questa è una schermata e non un comando.** Tre `INSERT` scriverebbero il listino; ciò che non si scrive a mano è il **confronto** fra quel listino e ciò che il cliente paga davvero. La divergenza si mostra coi **due valori affiancati** — la stessa forma del marcatore «personalizzato» di §4.1 — perché dire soltanto «diverge» manda ad aprire la dashboard di Stripe per sapere *di quanto*, cioè a rifare a mano metà del lavoro che questa pagina toglie. E nessuna delle due parti vince da sola: **il database è la verità per il dominio** (etichetta, tetto di Enti, offribilità), **Stripe lo è per il denaro**.

**Il confronto è un bottone, non una riga di `render()`.** Una conciliazione all'apertura sarebbe una chiamata di rete per piano a ogni visita, e una pagina di piattaforma che **muore perché un fornitore esterno è giù** o perché le chiavi Stripe non sono configurate su quell'ambiente. Un test conta le chiamate alla porta finta e pretende **zero** finché nessuno preme. Il risultato si butta via dopo ogni scrittura: un cambio di prezzo rende falsi i marcatori calcolati un istante prima, e «non confrontato» è meglio di una cifra sbagliata.

**Tre badge che dicono cose diverse, e vanno letti prima del click.** `⟨gratuito⟩` = niente customer e niente subscription su Stripe, per definizione (ADR-002) — la colonna Stripe è vuota apposta, non è un buco da riempire. `⟨predefinito⟩` = il piano con cui nasce ogni account e a cui torna chi disdice: **archiviarlo è rifiutato**, e il bottone non compare, perché offrire un gesto che verrà rifiutato è un invito a bussare. `⟨archiviato⟩` = non più offribile, ma **ancora a catalogo**: gli account rimasti sopra continuano a valere il proprio prezzo nell'MRR: se `Piani::codici()` filtrasse sugli attivi diventerebbero tutti «fuori catalogo», cioè **0 €**.

**La striscia in fondo è quella di §4.1, spostata sul listino.** Elenca i codici che vivono in `accounts.piano` e che nessun piano descrive più — la stringa non ha FK — con la strada verso la cabina per vedere *chi* sono. È il verso di ritorno del link che §4 ha guadagnato lo stesso giorno: la cabina dice che il problema esiste, il listino è dove si ripara.

**Quattro modi di rompere Stripe, chiusi nella caccia del 28 Ago e visibili nel disegno.** «Aggancia un price» esiste perché un Price creato a mano nella dashboard non diventi un secondo Product al gesto successivo; l'aggancio verifica che il price sia **attivo** e **appartenga al prodotto del piano**, non solo che l'importo torni; la chiave di idempotenza non è funzione del solo importo, o tornare a un prezzo già usato entro 24 h farebbe ripescare a Stripe il price **vecchio** — quello che avevamo archiviato noi — rimettendolo corrente. E gli errori di validazione si vedono **dentro** la modale: l'attributo `name` dei campi è la chiave dell'error bag e non il nome della property, o il blocco d'errore per campo è codice morto e chi clicca vede «non è successo niente».

**Nella colonna «Enti» un `null` vale «illimitati», non «non lo so»** — è la forma che avrebbe un piano Enterprise. E abbassare quel tetto mette clienti **sopra** il proprio limite, perché `Account::puoAggiungereEnte()` ci si appoggia: la modifica non è rifiutata, ma passa da una conferma che dice quanti clienti ci finirebbero.

**Mobile**: la tabella scorre in orizzontale, come in §4 e per la stessa ragione — questa pagina si guarda da scrivania.

### 7.2 Registrazione pubblica — `/registrati` 🔗 ADR-012, ADR-032

Non è una schermata: sono **quattro**, con quattro gate diversi, e il disegno serve a far vedere dove passa il denaro e dove passa la fiducia. È l'**unica superficie pubblica e non autenticata che scrive** dell'intera applicazione.

```
  ① /registrati              ② …/controlla-email     ③ …/pagamento         ④ …/completata
    ‹guest · throttle IP+email›  ‹guest›                ‹signed · guest›       ‹signed · NON guest›
  ┌─────────────────────┐   ┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
  │ Crea il tuo account │   │ Controlla la     │   │ Indirizzo        │   │ «Lab Rossi srl»  │
  │ Nome del laboratorio│   │ posta            │   │ confermato       │   │ è attivo         │
  │ [                  ]│   │                  │   │                  │   │                  │
  │ Il tuo nome e cognome│  │ Se l'indirizzo è │   │ Piano    SaaS    │   │ [Accedi a EasyLab]│
  │ [                  ]│   │ valido ti abbiamo│   │ Importo  49,00 € │   │                  │
  │ Email               │   │ scritto: apri il │   │          al mese │   │ ── oppure ──     │
  │ [                  ]│   │ link per         │   │                  │   │ «Stiamo comple-  │
  │ Password  [        ]│   │ confermarlo.     │   │ [ Vai al         │   │  tando l'attiva- │
  │ Conferma  [        ]│   │                  │   │   pagamento ]    │   │  zione» ‹incasso │
  │ ( • ) SaaS  49 €/mese│  │ ‹stessa risposta │   │                  │   │  non ancora con- │
  │ ( ) …               │   │  se l'email è    │   │ ‹se il piano non │   │  fermato›        │
  │ [sito_web]‹invisibile│   │  già di qualcuno›│   │  è più vendibile:│   │ [Torna all'acc.] │
  │ [   Crea l'account  ]│   │ [Ricompila il    │   │  «Ricomincia»›   │   │                  │
  │                     │   │  modulo]         │   │                  │   │                  │
  └─────────────────────┘   └──────────────────┘   └──────────────────┘   └──────────────────┘
            │                        ▲                      │                      ▲
            └── riga `registrazioni` ┘                      └─ Stripe Checkout ────┘
               + email di verifica     link di verifica       (fuori da Easy Lab)
                                        firmato: 1 ora        ritorno firmato: 24 h
```

🔴 **L'account nasce SOLO al passo ④, e solo se Stripe dice che il pagamento è riuscito.** Fino a lì esiste una riga `registrazioni` che si pota da sola a 30 giorni, non un utente. È anche il motivo per cui il modulo **non offre piani gratuiti**: un piano omaggiato non ha un checkout da superare, e sarebbe l'unica porta del progetto da cui chiunque si fabbrica un Ente e un ruolo `Admin` senza che nessuno lo autorizzi. Il Free esiste (ADR-002) ma si **concede**, dalla cabina o da `easylab:provision-tenant`.

**Il passo ④ ha due volti, e il secondo è quello che conta.** Se l'incasso non è ancora confermato la pagina dice «stiamo completando l'attivazione» invece di mentire: il webhook è la rete che chiude il caso «scheda chiusa a metà». E il caso peggiore — **pagamento riuscito, account rifiutato** — lascia ora una riga nel registro di audit: prima l'unica traccia era in `laravel.log`, cioè su un disco che questo progetto descrive come effimero e azzerato a ogni deploy, e a trenta giorni la potatura cancellava pure la riga pendente. Incasso avvenuto, nessun account, e niente da cui accorgersene. ⚠️ Quella voce di audit si etichetta sul **nome dell'Ente e non sull'email**, ed è privacy e non stile: il registro si legge con `tenants.view_all`, e l'indirizzo di chi ha solo *tentato* di registrarsi è il dato personale di una persona che cliente non è ancora.

🔴 **Il passo ② dice sempre la stessa cosa, ed è la difesa principale del disegno.** Un'email che appartiene già a un utente produce **lo stesso identico schermo** di una nuova, e nessuna riga a database: cambia solo cosa arriva nella casella, che è raggiungibile dal solo proprietario. Dire «esiste già» significherebbe pubblicare l'anagrafica commerciale di Easy Lab un indirizzo alla volta.

🔴 **Il difetto più grave dello sprint è vissuto fra ① e ②, e va scritto perché il disegno da solo non lo mostrerebbe.** Il riuso di una registrazione pendente sovrascriveva la password **senza azzerare la verifica della casella**: Mario si registra e conferma il link; un terzo che conosca solo il suo indirizzo ripete il modulo con la **propria** password, la riga viene riusata, la verifica di Mario resta valida; Mario paga, e l'account nasce con l'hash dell'attaccante. Ora una riga pendente si riusa **solo se nessuno l'ha verificata**, e la seconda registrazione ne ottiene una propria che nessuno ha ancora confermato.

**Il campo `sito_web` è nel disegno perché esiste nel markup, e non si vede.** È l'esca: se arriva pieno la risposta è di **successo apparente** e non accade nulla — un rifiuto esplicito insegnerebbe al bot come passare. Il nome non dice «trappola»: `honeypot` non lo compila nessuno, un campo plausibile sì.

**Quattro gate, e nessuno è un permesso.** Chi si registra **non esiste** nel database, quindi non ha ruoli: il controllo è `guest` + `signed` + `throttle` (su **due** chiavi, IP ed email — la sola chiave IP si aggira con un proxy, la sola email cambiando indirizzo), esattamente come `/invito/{user}` e `/q/{token}`. ⚠️ Il passo ④ è l'unico **fuori** da `guest`: Stripe rimanda qui il browser, e un visitatore già autenticato con un altro account verrebbe sbattuto sulla dashboard **perdendo il completamento di un pagamento già incassato**. Nessuna di queste rotte passa da `account.lockout` né da `two-factor.enforce`, e non è una svista: qui non c'è nessuna sessione da proteggere, e mandare al setup del 2FA chi sta chiudendo un pagamento sarebbe la stessa perdita.

**Le finestre delle firme non sono tutte uguali, e ognuna ha la sua ragione.** Il link di verifica dura **un'ora**, perché è il gesto che si compie subito e la casella è già aperta; il ritorno da Stripe dura **24 ore**, perché chi resta quaranta minuti sulla pagina di pagamento — un cambio di carta, una telefonata — deve poter tornare, e una scadenza corta lì sarebbe un 403 su un pagamento **riuscito**.

**Controller e Blade, non Livewire**, ed è l'unica forma possibile: `throttle` è un middleware **di rotta** e ogni update Livewire passa da `/livewire/update`, quindi un form Livewire avrebbe il rate limiting sul solo GET iniziale — cioè non l'avrebbe.

**L'interruttore chiude solo l'ingresso.** `config('easylab.registrazione.aperta')` a `false` fa rispondere **404** — non 403, che dichiarerebbe l'esistenza della pagina — al solo passo ①. I passi ②–④ restano aperti apposta: chi ha già pagato deve poter completare, e chiudergli la porta significherebbe aver incassato senza consegnare.

⚠️ **Limite dichiarato.** `Registrazione` è **esentato** dal guardrail di isolamento per tenant (`NON_TENANT_MODELS`), sulla stessa forma già usata per `Account`, `Errore` e `Piano`: una registrazione pendente un tenant non ce l'ha ancora — è ciò che deve nascere — e il confine qui è il **permesso**, non il tenant. E nulla di questo percorso è mai stato provato su Stripe vero: la porta del checkout ha una finta in suite, e il percorso felice verso la rete si verifica su staging con le chiavi di test.

---

## 8. Parco clienti ‹`tenants.view_all`› — `/piattaforma/parco` 🔗 ADR-037 (29 Ago 2026)

Tre rotte e tre componenti — **Strumenti**, **Scadenzario**, **Ricambi** — che mostrano al Superadmin e al Developer le righe di **tutti** i clienti, in **sola lettura**. È la risposta alla domanda di lavoro quotidiana di chi fa manutenzione su molti Enti («quali macchine di quali clienti scadono questa settimana»), che fino a ieri costava dodici impersonazioni e dodici elenchi tenuti a mente.

> 🔴 **Il confine è la ragione per cui il disegno è accettabile, e va letto prima del box.** Da qui si **guarda** oltre il proprio Ente; **non si scrive**. Nessuna delle tre schede ha un bottone che modifichi alcunché: ogni modifica continua a passare dall'impersonazione, che è per cliente e lascia nel registro di audit chi agiva e per conto di chi (🔗 ADR-018, ADR-027). Il tasto `[👁 Impersona]` accanto a ogni riga non è un ornamento: è **la funzione della pagina**, ciò che rende quel confine rapido invece che fastidioso. Il nome della macchina, per la stessa ragione, **non è un link**: `strumenti.show` risolve col route model binding e quindi passa dai global scope, cioè risponderebbe 404 su ogni cliente che non è il proprio — e un link che porta a un 404 è peggio di nessun link.

```
┌───────────────────────────────────────────────────────────────────────────────┐
│ Easy Lab · Piattaforma                                        🔔  👤 EasyLab ▼│
│ Clienti · Audit · Ruoli e permessi · Piani · «Parco clienti» · Errori         │
│ ───────────────────────────────────────────────────────────────────────────── │
│  ‹Strumenti›   Scadenzario   Ricambi        ← tre schede, tre rotte vere      │
│                                                                               │
│  Strumenti di tutti i clienti   ‹il titolo dice il perimetro›                 │
│  Sola lettura: per intervenire si entra come una persona del cliente.         │
│  ┌──────────────────────────────────────────────────────────────────────────┐ │
│  │ CLIENTI                   ‹PIANO›           CERCA                        │ │
│  │ [Tutti i clienti     ▼]   [— scegli… ▼]    🔍 Nome, modello, matricola…  │ │
│  │  · Tutti i clienti         ‹solo se il modo è «Per piano»›               │ │
│  │  · Per piano                                                             │ │
│  │  · ★ I miei preferiti →   I MIEI PREFERITI (2)                           │ │
│  │                          ┌────────────────────────────────────────┐      │ │
│  │                          │ Osp. S.Giovanni, Clin. Aurora          │      │ │
│  │                          │ Gestisci i preferiti nell'elenco Clienti│     │ │
│  │                          └────────────────────────────────────────┘      │ │
│  │ Lo stato del semaforo si legge su ogni riga, ma NON è un filtro.         │ │
│  └──────────────────────────────────────────────────────────────────────────┘ │
│  ┌──────────────────────────────────────────────────────────────────────────┐ │
│  │St│Cliente ↑    │Sede      │Macchina    │Modello│Matric│Pross.sc. │Azioni │ │
│  ├──┼─────────────┼──────────┼────────────┼───────┼──────┼──────────┼───────┤ │
│  │🔴│Osp. S.Giov. │Lab Micro.│Autoclave   │AC-200 │8841  │Tarat.sca…│👁     │ │
│  │🟠│Osp. S.Giov. │Lab Anal. │Centrifuga  │CF-12  │1207  │Manut.4 gg│👁 ×3  │ │
│  │🟢│Clin. Aurora │Biobanca  │Frigo -80   │FR-3   │—     │—         │nessuno│ │
│  │🟢│Clin. Aurora │Biobanca  │Cappa chim. │CH-9   │0442  │Verif. 2 m│—      │ │
│  └──────────────────────────────────────────────────────────────────────────┘ │
│  Righe per pagina [20 ▼]                                ‹ 1 2 3 ›             │
└───────────────────────────────────────────────────────────────────────────────┘
```

**Il perimetro sta per primo, da solo, e ha tre modi.** È il filtro che decide **di chi** sono le righe, e metterlo dopo la ricerca farebbe credere che l'elenco sia già di tutti. 🔴 Un perimetro **vuoto** vale **nessuna riga**, mai «tutti» — è la trappola che `Perimetro` esiste per chiudere (l'insieme vuoto *è* `scelti([])`, che compila `0 = 1`), e la vista lo dice a schermo invece di lasciarlo dedurre. L'etichetta identica sulle tre schede — **«★ I miei preferiti»** — non è pignoleria: due schermi che chiamano lo stesso perimetro con due nomi diversi sono due perimetri, per chi legge, e fino al 29 Ago 2026 questa pagina lo faceva davvero («Quelli che scelgo» sugli strumenti, «Scelti a mano» su scadenzario e ricambi). ⚠️ Anche il **titolo** dice il perimetro — «Strumenti di tutti i clienti», «…dei clienti sul piano SaaS», «…di 3 clienti preferiti» — e non è cosmesi: il rischio di questa pagina non è sbagliare macchina, è impersonare dalla riga del cliente sbagliato, e un titolo fisso che allarga il perimetro a parole ci lavorerebbe contro. Modo e piano sono `#[Url]`, quindi una vista filtrata si manda per link — ed è anche il motivo per cui arrivano dal browser e vengono rivalidati **intersecando**, non validando.

🔴 **Il terzo modo si sceglie ALTROVE, ed è la modifica del 29 Ago 2026.** «Quelli che scelgo» era una `<select multiple>` legata a un `#[Url]`: si ricomponeva a mano a ogni visita che non arrivasse da un link salvato, mentre i clienti che si guardano spesso sono pochi e sempre gli stessi. Al suo posto ci sono i **preferiti**, che si segnano con una **★ nell'elenco Clienti** (§4) e vivono nel pivot `clienti_preferiti` (ERD §4.3). Qui il blocco è in **sola lettura** — quanti sono, quali sono, e il rimando a dove si cambiano — e rimetterci un controllo che li modifichi ricostruirebbe la multi-select sotto un nome nuovo. Ne discende una proprietà che vale più della comodità: **nessuna lista di id di clienti arriva più dal browser**. Le tre schede non hanno una property pubblica che la porti, quindi il `whereIn` del perimetro non ha una strada di ingresso dalla querystring — la difesa resta comunque l'intersezione con `VistaPiattaforma::accounts()`, ma ora è la seconda e non la sola.

⚠️ E un modo che la tendina **non sa disegnare** — `?modo=scelti` di un link salvato, o una stringa forgiata — non lascia il controllo a mentire: le tre schede lo normalizzano a `preferiti`. Una `<select>` legata a un valore senza `<option>` non resta vuota, evidenzia la **prima** voce: direbbe «Tutti i clienti» sopra una tabella che tutti i clienti non li mostra, e da lì non si uscirebbe, perché riselezionare la voce già mostrata non emette alcun evento.

**Cliente e sede sono colonne, su ogni riga, e non si possono togliere.** Su una vista cross-cliente il rischio non è sbagliare macchina: è sbagliare **cliente**, e chi impersona dalla riga sbagliata entra in casa di qualcun altro. I due nomi arrivano da due `leftJoin` e non da un `with()` — le relazioni passano da `UnitaOrganizzativa`, che porta `TenantScope`, e un eager load le risolverebbe a `NULL` per quasi tutta la pagina, cioè una colonna di trattini plausibile e muta.

⚠️ **Le colonne derivate non sono ordinabili, ed è dichiarato nel disegno.** Si ordina per cliente, sede, macchina, modello, matricola — colonne vere, che la join rende tali. **Stato** e **prossima scadenza** no: ordinarli richiederebbe le sottoquery correlate per riga dell'elenco per-Ente (scopate, quindi qui *false*) o una loro copia non-scopata, cioè una seconda regola del semaforo. Per la stessa ragione **manca il filtro «solo arancioni»** di §5, e la pagina lo scrive sotto i filtri invece di lasciar cercare un controllo che non c'è: filtrarlo in PHP dopo la paginazione darebbe pagine incomplete e un totale falso. Si preferisce un filtro assente a un filtro che mente.

**L'ultima colonna ha quattro esiti, e nessuno è una cella vuota.** Un solo membro impersonabile → link `<a href>` GET (mai un'azione Livewire: `take()` sostituisce l'utente in sessione, e una risposta Livewire lascerebbe in pagina un componente montato con lo scope del precedente); più d'uno → `[👁 Impersona (n)]` che apre la scelta; nessuno → il testo «Nessun membro impersonabile» col perché nel `title`, perché un'assenza muta su questa piattaforma è già stata scambiata per un difetto; senza `utenti.impersonate` → un trattino. Nel box qui sopra i quattro esiti sono abbreviati per larghezza (`👁`, `👁 ×3`, `nessuno`, `—`); a schermo sono per esteso. La logica è quella di `OffreImpersonazione`, lo stesso trait della cabina (§4): Developer mai impersonabile, chi già impersona non impersona nessuno.

**Le due schede sorelle cambiano la tabella, non l'impianto.** Lo **Scadenzario** antepone le **tre partizioni di §1 come riquadri cliccabili** — Scaduti · In scadenza (entro la soglia) · Oltre, più «Tutti gli interventi aperti» — e le sue colonne sono Cliente · Sede · Macchina · Tipo · Descrizione · Scadenza · Quanto manca · Azioni, con i filtri Stato e Tipo. I **Ricambi** elencano Cliente · Sede · Pezzo · In catalogo dal · Codice · **Presso** · Azioni, dove «Presso» conta quanti clienti *del perimetro* hanno lo stesso pezzo a catalogo — l'unico numero della schermata che esiste solo perché la vista è trasversale; lì si ordina per pezzo e per data d'inserimento, e la pagina **dice a parole** che per cliente non si ordina (servirebbe una join che perde gli scope o una sottoquery scopata che tornerebbe `NULL` per ogni Ente altrui), rimandando al filtro «Clienti».

⚠️ **Gli empty state sono tre messaggi diversi, non uno.** «Nessun piano selezionato» e «Non hai ancora clienti preferiti: segnali con la ★ nell'elenco Clienti» sono fatti distinti da «nessun risultato», e mandano al controllo **giusto**: col modo «per piano» e nessun piano scelto il perimetro è vuoto ma il blocco dei preferiti non è nemmeno a schermo, e mandare lì è mandare a cercare un difetto. Il messaggio dei preferiti a zero manda per di più a una **pagina diversa**, che è la conseguenza di averli spostati: il rimedio non è in cima a questa schermata, è la ★ di §4. Ognuno chiude con la frase che regge tutto il disegno — *«Un piano non scelto non vale “tutti”»*, *«Una selezione vuota non vale “tutti”»* — e la dice a schermo perché è esattamente l'invariante che un `if` scritto male romperebbe in silenzio.

**Ciò che questo disegno NON contiene**, per scelta e non per dimenticanza: nessuna cella modificabile e nessuna azione di massa (ADR-037, alternativa scartata (a) — un filtro che silenziosamente vale «tutti» trasformerebbe una correzione in un'operazione su ogni cliente della piattaforma); nessun `[CSV]`/`[PDF]` come quelli di §4; nessuna scheda per `RicambioUtilizzo`, tenuto **fuori dalla porta** insieme alla domanda di privacy che porta con sé.

🔴 **Due limiti dichiarati, che il disegno da solo non mostra.** **(a)** La regola con cui si compone l'etichetta della colonna «Prossima scadenza» è **duplicata** fra `RigheParcoStrumenti` e l'elenco per-Ente di §5: non è stata estratta, è tenuta allineata da un **test di accoppiamento** che calcola l'etichetta di qui e la cerca nella pagina per-Ente. Un'estrazione resta il rimedio vero, e finché non c'è la rete è quel test. **(b)** Il modo «per piano» offre i soli codici a **catalogo** (`Piani::codici()`): i clienti fermi su un piano dismesso — quelli che la cabina segnala col badge `fuori catalogo` e ripara da §7.1 — **non sono esprimibili** da questa tendina. Si raggiungono da «Tutti i clienti» o segnandoli fra i preferiti. È l'asimmetria fra le due schermate, e va tolta riparando il dato in §7.1, non allargando la tendina.

## 9. Persone e tecnici — ADR-038 (30 Ago 2026)

### 9.1 Persone dell'Ente — `/utenti`

```text
┌ Persone dell'Ente ─────────────────────────────── [+ Invita una persona] ┐
│ Persona        Ruolo          Stato                         Azioni        │
│ G. Verdi       Admin          Attivo                        Ruolo · Cestina│
│ L. Bianchi     Tecnico        Invitato, mai entrato         Reinvita      │
│ A. Neri        —              Senza ruolo                   Ruolo         │
│ M. Rossi       Tenant         Cestinato                     Ripristina    │
└───────────────────────────────────────────────────────────────────────────┘
 Invito: nome · email · [Admin | Responsabile Reparto | Tenant | Tecnico]
 ⚠ Admin richiede il secondo fattore al primo accesso.
```

Invito, reinvito, cambio ruolo, cestino e ripristino sono gesti distinti e riautorizzati. Developer e Superadmin non sono amministrabili qui; l'ultimo Admin non si declassa e non si cestina.

### 9.2 Tecnici EasyLab — `/piattaforma/tecnici`

```text
┌ I miei tecnici ─────────────────────────────────── [+ Invita un tecnico] ┐
│ Persona        Stato                   Sedi in portafoglio      Azioni   │
│ G. Verdi       Attivo                  3 sedi                    Accessi  │
│ L. Bianchi     Invitato                Nessuna                   Cestina  │
└───────────────────────────────────────────────────────────────────────────┘
 Invito: nome · email · ruolo fisso Tecnico · nessun Ente
 Portafoglio: [✓] Cliente A / Sede 1   [ ] Cliente A / Sede 2
```

Il portafoglio è **per sede**, non per contratto. L'avviso accanto alle checkbox dice che la spunta apre tutte le macchine della sede e rende il tecnico assegnabile. Migration sul DB di sviluppo e verifica manuale restano pendenti.

---

> Questi wireframe sono la base per il **Design System** (S0.4): da lì arrivano palette, componenti Tailwind e gli stati semaforo definitivi.
