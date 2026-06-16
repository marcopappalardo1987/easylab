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
│  │  🔴⚑  │ Autoclave AC-200   │ Lab Microbiologia │ Taratura — scaduta │  │
│  │  🟠   │ Centrifuga CF-12   │ Lab Analisi       │ Manut. tra 4 gg    │  │
│  │  🟠   │ Spettrofotom. S-9  │ Lab Chimica       │ Garanzia tra 12 gg │  │
│  │  🟢   │ Frigo -80 FR-3     │ Lab Biobanca      │ —                  │  │
│  │  …                                                                   │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                              ‹click riga → Scheda strumento›│
└──────────────────────────────────────────────────────────────────────────┘
```

**Note.** Ordinamento default per gravità (🔴→🟠→🟢) e scadenza più vicina. `⚑` indica stato forzato (badge cliccabile → mostra chi/quando/perché, ADR-005). Il Tenant **non** vede colonne/filtri relativi a garanzie ricambio (ADR-004). Responsabile Reparto: stesso layout ma KPI/lista ristretti al sotto-albero. Mobile: KPI in colonna, tabella → lista di card.

---

## 2. Scheda Strumento (con tab)

Cuore dell'app: tutto ciò che riguarda un singolo strumento, organizzato in 5 tab. 🔗 Funzionalità §2.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ‹ Torna alla lista                                                         │
│                                                                            │
│  🔴⚑  Autoclave AC-200            [ Forza semaforo ▼ ] ‹perm. semaforo.force›│
│  Cod. STR-0042 · Lab Microbiologia · Installato 03/2015 · ⏳ Obsoleto      │
│                                            [ 🔳 QR ] [ ✎ Modifica ] [ ⠿ ]  │
│ ┌────────────────────────────────────────────────────────────────────────┐│
│ │ Anagrafica │ ▸Interventi◂ │ Ricambi │ Documenti │ Garanzie ‹Admin/EasyLab›││
│ ├────────────────────────────────────────────────────────────────────────┤│
│ │                                                  [ + Nuovo intervento ]  ││
│ │  Stato │ Data       │ Tipo         │ Descrizione      │ Tecnico         ││
│ │  ──────┼────────────┼──────────────┼──────────────────┼──────────────── ││
│ │  ✓ Fatto│ 12/01/2026 │ Manutenzione │ Sostituz. guarniz│ L. Bianchi     ││
│ │  ✗ No   │ 28/05/2026 │ Taratura     │ Taratura annuale │ — (scaduta) 🔴 ││
│ │  ◷ Prog.│ 15/09/2026 │ Manutenzione │ Controllo press. │ G. Verdi       ││
│ │         │            │              │            [ ✓ Segna come fatto ]  ││
│ └────────────────────────────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────────────────────────┘
```

**Contenuto dei tab.**
- **Anagrafica:** modello, parametri tecnici, data installazione, ubicazione corrente, fornitori associati, storico spostamenti (`spostamenti.view`).
- **Interventi:** lista attività passate (fatto/non fatto) + future pianificate; spunta "Fatto" (`interventi.complete`); fonte di verità del semaforo (ADR-005).
- **Ricambi:** ricambi montati (catalogo + autocomplete, ADR-008); collega a un intervento; ricerca incrociata "dov'è montato".
- **Documenti:** allegati su Spaces con URL firmate (ADR-009); upload/download; export PDF.
- **Garanzie ‹solo Admin/EasyLab›:** garanzia macchina + garanzie ricambio (motore sdoppiato, ADR-004). **Tab nascosto a Tenant e Tecnico.**

**Note.** Il pulsante "Forza semaforo" compare solo con `semaforo.force`. Il tab "Garanzie" è renderizzato solo se il ruolo ha `garanzie.*` (Tenant/Tecnico non lo vedono — ADR-004). Mobile: i tab diventano un menù `▼` o scroll orizzontale.

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
│ ─ oppure ─            │     │  [ ⏱ Lettura contaore]│
│  I miei interventi ▾  │     │  [ 📎 Allega foto/doc]│
│  • AC-200 (oggi)      │     │                       │
│  • CF-12 (dom.)       │     │  Report fine lavoro:  │
│                       │     │  ┌───────────────────┐│
│                       │     │  │ note…             ││
│                       │     │  └───────────────────┘│
│                       │     │  [   Chiudi intervento]│
└───────────────────────┘     └───────────────────────┘
   schermata iniziale            dopo scansione/selezione
```

**Note.** Accesso post-QR via URL firmata → login se necessario → scheda **solo se autorizzato** (mai dati senza auth, ADR-003). Il tecnico vede solo strumenti in portafoglio ∪ assegnazione (ADR-007); ogni accesso loggato. Niente tab Garanzie, niente garanzie ricambio. Azioni disponibili filtrate per permesso (`interventi.complete`, `ricambio_utilizzo.create`, `letture_contaore.create`, `documenti.upload`).

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
