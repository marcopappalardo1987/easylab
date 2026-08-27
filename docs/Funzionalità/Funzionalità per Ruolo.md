**1. Dashboard Developer (Tu)**

Questa è la vista di massimo livello tecnico, dove tu come sviluppatore controlli l'infrastruttura e il codice, invisibile a chiunque altro.

- **Monitoraggio di Sistema:** Accesso ai log di sistema globali per identificare bug o malfunzionamenti.
- **User Impersonation (Tecnica):** Funzionalità con 1-click per impersonificare qualsiasi utente nel database allo scopo di fare assistenza tecnica profonda o risolvere bug, con banner persistente per tornare ai privilegi massimi.

> **Stato di attuazione al 24 Ago 2026 (S6).** Entrambe le voci esistono: l'**impersonazione** dalla cabina di regia con banner persistente che dice **entrambi** i nomi, e il **monitoraggio** su `/piattaforma/errori`, quarta voce della sub-nav di piattaforma. La pagina è dietro `system.logs.view` — permesso **bloccato** e del **solo Developer**, quindi nemmeno il Superadmin la apre (🔗 ADR-017, ADR-016).
>
> 🔴 **Una voce va letta con una correzione, non alla lettera.**
>
> *«Accesso ai log di sistema globali»* — oggi significa **gli errori catturati dall'applicazione**, non un visore di `laravel.log`. Non è una riduzione dell'ambizione, è la sola forma che dica la verità: su Laravel Cloud (🔗 ADR-025) `laravel.log` vive su un disco **effimero e per-replica**, azzerato a ogni deploy e a ogni risveglio da scale-to-zero. Una schermata che lo mostrasse sarebbe *muta proprio sugli errori che contano* — quelli visti da un cliente prima di un rilascio — e lo sarebbe **senza dirlo**, che è la ragione per cui è stata scartata invece che rimandata: un cruscotto che mente per omissione è peggio della sua assenza, perché chi lo guarda smette di cercare altrove. Le eccezioni si salvano quindi **a database**, raggruppate per punto d'origine, con il messaggio, lo stack trace e l'input della richiesta **ripuliti in scrittura** dalle chiavi sensibili (🔗 Privacy §3).
>
> ⚠️ **E il «monitoraggio» dell'infrastruttura non è qui, di proposito**: uso di CPU, memoria, code e repliche li mostra già la dashboard di Laravel Cloud, e rifarli in applicazione significherebbe un secondo cruscotto da tenere allineato al primo — con il difetto che il nostro si spegne insieme all'applicazione che dovrebbe sorvegliare.
>
> ⚠️ **In uso quotidiano il canale non è la pagina, è l'email.** Nessuno apre `/piattaforma/errori` a caso: ci si arriva perché è arrivato un alert. Quell'email va a una **casella** (`ERRORI_ALERT_EMAIL`), che non ha né permesso né registro di audit, e per questo contiene solo classe, punto d'origine, conteggio e il link — mai il dettaglio (🔗 Privacy §T8).

**2. Dashboard Superadmin (EasyLab)**

Questa è la cabina di regia commerciale e operativa del proprietario originario della piattaforma (EasyLab).

- **Controllo Infrastruttura SaaS:** Gestione dei piani in abbonamento tramite Stripe/Cashier.

- **Gestione Rivenditori/Admin:** Possibilità di vendere il software a terzi (che diventeranno Admin/Proprietari).
- **Dashboard Globale (Master):** Vista generale su tutti i clienti (sia propri che degli Admin), numero totale di laboratori attivati, strumenti totali gestiti e MRR (Ricavi Mensili Ricorrenti) dell'intera piattaforma.
- **Gestione Contratti Propri:** Erogazione dell'abbonamento "Free (Omaggiato)" per i clienti diretti di EasyLab che firmano un contratto di manutenzione "chiavi in mano" fisico.
- **Blocco Automatico Insoluti:** Gestione e visualizzazione del blocco automatico dell'accesso per i tenant/admin a cui scade l'abbonamento o fallisce il pagamento.
- **Tutte le funzionalità del Admin** sono integrate nel superadmin

> **Stato di attuazione al 21 Ago 2026 (S6).** La cabina di regia è su `/piattaforma`. Realizzati: **Dashboard Globale** (clienti, sedi, strumenti, MRR — quest'ultimo **a listino, non incassato**: la verità contabile resta Stripe), tabella clienti con espansione nelle sedi, **impersonazione** con banner che dice entrambi i nomi, **lockout** manuale, **dati fiscali**, **visibilità garanzie ricambio** per sede, e l'erogazione **Free «chiavi in mano»** dal form invece che dal terminale.
>
> ⚠️ **Tre voci di questo elenco vanno lette con una correzione, non alla lettera.**
> 1. *«Vista generale su tutti i clienti»* — la riga è di un'unità sbagliata: dopo 🔗 **ADR-032** l'unità del contratto è l'**Account**, non l'Ente, e un Account può avere N sedi. La tabella elenca clienti, non laboratori.
> 2. *«Blocco Automatico Insoluti»* — sono **due sorgenti ortogonali** (🔗 ADR-013): quella automatica la scrive il webhook Stripe e **si riapre da sé** al pagamento (non ha un pulsante, ed è voluto), quella manuale la scrive una persona con un motivo obbligatorio. Un interruttore unico rifarebbe il difetto che la separazione esiste per impedire.
> 3. *«Gestione Rivenditori/Admin»* — **fuori da V1** (🔗 ADR-002: solo il rapporto A, EasyLab incassa; niente Stripe Connect). È nel backlog V1.1.
> 4. *«Controllo Infrastruttura SaaS: gestione dei piani tramite Stripe/Cashier»* — oggi è **letterale, e non passa da qui**: un piano si tocca in due posti scollegati, il catalogo in codice (`App\Support\Piani`) e il prodotto su Stripe, e la cabina non offre alcun modo di farlo — il form di provisioning non ha nemmeno un select dei piani. 🗓️ **Deciso il 27 Ago 2026** (🔗 **ADR-035**): il listino si governerà **da una schermata della piattaforma**, e sarà quel gesto a scrivere anche su Stripe. Riservato a **Superadmin e Developer** — creare un piano è fissare un prezzo — sul permesso `billing.manage_global`, che è già loro e già bloccato: nessun permesso nuovo, nessun riseeding. **Non attuato.**
>
> **Ancora da fare in S6**: vista **Audit log**, **editor permessi ruolo**, e le dashboard dedicate agli altri ruoli.



**3. Dashboard Admin (Proprietari che si abbonano)**

Questi sono i clienti che acquistano il SaaS per operare in autonomia sui propri laboratori o per gestire i propri clienti terzi. Agiscono come un "piccolo EasyLab" limitato al proprio recinto.

- **Gestione Autonoma del SaaS:** Completa autonomia nel tracciamento di interventi e manutenzioni per i propri clienti/laboratori.
- **Anagrafica e Tracciamento:** Creazione delle schede macchinari, registrazione dei trasferimenti fisici tra laboratori e ricerca per singolo strumento nei vari laboratori.
- **Gestione Garanzie e Ricambi:** Accesso riservato (nascosto ai Tenant) al motore delle garanzie sdoppiato (macchina intera e singolo pezzo di ricambio). *Aggiornamento 3 Ago 2026: garanzie **solo a data**, la garanzia a ore non esiste (ADR-019); la garanzia del ricambio **accende il semaforo** dello strumento anche per il Tenant, che continua a non vederne il dettaglio (ADR-020).*
- **Valutazione Obsolescenza:** Alert automatico e gestione per le macchine che superano i 10 anni (ricambi non più garantiti per legge).
- **Gestione Fornitori e Anomalie:** Associazione diretta tra macchinari e fornitori, oltre alla gestione manuale della "Pallina Rossa" (macchinario non idoneo) a seguito di verifiche.

> **Stato di attuazione al 25 Ago 2026 (S6).** La pagina di atterraggio è `/dashboard`, **una sola per tutti i ruoli** e non una per ruolo: ci arrivano Fortify dopo il login, lo switcher di sede a ogni cambio e la fuga da lockout, quindi due componenti avrebbero richiesto un instradamento **per nome di ruolo** — che il progetto rifiuta ovunque (`/campo` è gatata `interventi.view` e non «Tecnico»), e che diventerebbe falso al primo click sull'editor permessi. Ciò che distingue questo §3 dal §4 è già espresso da `TenantScope`, `DepartmentScope`, `AccessoTecnico` e `GaranziaRicambioPolicy`: una seconda vista dovrebbe **ri-deciderlo**.
>
> La dashboard **aggiunge una cosa sola**: i quattro conteggi del parco (in regola / azione richiesta / non idoneo / obsoleti), che non esistevano da nessuna parte — l'elenco strumenti mostra il totale del paginatore, mai la ripartizione. Tutto il resto lo **indirizza**: ogni riquadro è un link a `/strumenti` col proprio filtro, e cliccando «Azione richiesta 18» si atterra su esattamente diciotto righe. 🔗 Wireframe §1, riscritto lo stesso giorno.
>
> 🔴 **Una voce di questo elenco è testo morto, e va corretta invece che letta.**
>
> *«Gestione Garanzie e Ricambi: accesso riservato (nascosto ai Tenant)»* — **non è più vero dal 15 Ago 2026** (🔗 ADR-029, e per il Tecnico 🔗 ADR-027). `config/rbac.php` dà `garanzie.ricambio.view` **e** `.manage` sia al Tenant sia al Tecnico, e il default del singolo Ente è `modifica`. Il divieto assoluto non era mai stato deciso davvero: poggiava sulla premessa mai scritta che i ricambi li fornisca EasyLab, che non regge quando il Tenant è l'intestatario dell'abbonamento e il pezzo sta sulla sua macchina. Oggi la restrizione è **un'impostazione per Ente a tre stati** (`nascosta` / `lettura` / `modifica`), che **restringe e non allarga mai**, e si governa dalla cabina di regia.
>
> ⚠️ **Due voci restano aperte, dichiarate invece che nascoste.**
>
> 1. *«Valutazione Obsolescenza: **alert automatico**»* — l'alert **non esiste**. Ci sono il filtro «solo obsoleti» nell'elenco, il badge ⏳ nella scheda e, da oggi, il **conteggio aggregato** in dashboard con il link all'elenco filtrato: è più di prima, ma nessuno *avvisa*. E non è una dimenticanza di tempo: `easylab:notifica-scadenze` è costruito su **transizioni datate** (`avvisi_scadenza` porta riferimento, transizione e data di scadenza), mentre l'obsolescenza dipende da una **soglia mutabile per Ente** — abbassare `soglia_obsolescenza_anni` fa attraversare la linea a decine di macchine nello stesso istante, e quella non è una transizione del *dato* ma della *configurazione*. Serve una decisione — si avvisa al cambio soglia? mai? con quale riferimento? — non un'aggiunta. Riga aperta in roadmap.
> 2. *«Registrazione dei trasferimenti fisici tra laboratori»* — esiste, ma **solo dentro un Ente**: `TipoSpostamento` prevede anche `Uscita` e `CrossTenant` (🔗 ADR-015) e nessuna delle due è mai stata scritta. E `spostamenti.view` ha **un solo consumatore**, il tab della scheda: non c'è nessuna vista «dov'è stata questa macchina» che attraversi il parco.

**4. Dashboard Tenant (I Laboratori / Enti Finali)**

Questa è l'interfaccia usata dai dipartimenti o laboratori fisici. Vedono solo i propri strumenti, con isolamento assoluto dei dati da altri clienti.

- **Sistema Visivo "A Semaforo":** Controllo immediato delle proprie macchine (Pallina Verde = in regola; Pallina Arancione = intervento/scadenza garanzia da eseguire; Pallina Rossa = non idoneo). *Aggiornamento 3 Ago 2026 (ADR-024): la scheda strumento apre su un **tab Panoramica** che elenca i **motivi** dello stato acceso, con link alla riga d'origine; i motivi che nascono da garanzie ricambio sono mostrati al Tenant in forma neutra e non cliccabile.*
- **Tracciamento Interventi:** Visualizzazione degli interventi con data passata o futura.
- **Automazioni e "Email del Futuro":** Ricezione delle notifiche programmate tramite cron jobs per l'approssimarsi di scadenze o rinnovi contrattuali.
- **Gestione Documentale:** Area dedicata per caricare certificati di taratura, scaricare report di fine lavoro ed esportare storici o certificati in PDF.
- **Limitazione di Visibilità (Privacy):** Il tenant è escluso dalla visione delle dinamiche interne di garanzia sui singoli ricambi.

> **Stato di attuazione al 25 Ago 2026 (S6).** Il Tenant atterra sulla **stessa** `/dashboard` dell'Admin, che gli mostra ciò che i suoi permessi e i global scope gli concedono — vedi la nota del §3 per il perché di una vista sola.
>
> ✅ **«Automazioni ed email del futuro» è stata chiusa oggi, ed era una promessa che il codice negava.** Fino al 25 Ago 2026 il Tenant **non riceveva nulla**: né email né notifica in-app. `NotificaScadenze::destinatari()` ammetteva Admin, Responsabile Reparto e tecnico assegnato, e `DigestScadenze::via()` scrive il canale `database` **sempre, ma solo per chi quel metodo sceglie** — quindi la sua campanella non era «vuota oggi», era *strutturalmente* vuota, mentre `/settings/notifiche` gli offriva una preferenza per un'email che nessuno gli mandava. Ora è fra i destinatari, con le righe del proprio Ente.
>
> ⚠️ Con lui **entra in gioco ADR-029**, e il filtro è nato con lui: le righe delle garanzie ricambio si tolgono a chi il proprio Ente le nasconde, chiedendo l'**ability** della Policy e mai il permesso nudo — `spatie` concede appena il permesso esiste sul ruolo, cioè *prima* che l'impostazione dell'Ente sia letta. Il docblock del comando diceva «nessun destinatario è il ruolo che quella regola protegge»: era vero, e da oggi sarebbe falso.
>
> 🔴 **Una voce di questo elenco è testo morto**, la stessa del §3: *«Limitazione di Visibilità (Privacy): il tenant è escluso dalla visione delle dinamiche interne di garanzia sui singoli ricambi»* — **non è più vero dal 15 Ago 2026** (🔗 ADR-029). Il Tenant ha `garanzie.ricambio.view` e `.manage`, e il default dell'Ente è `modifica`. Ciò che resta vero, e che non va confuso con questo, è 🔗 ADR-020: il **pallino** è un aggregato dovuto a tutti, quindi il Tenant vede l'arancione che nasce dalla garanzia di un pezzo anche quando il suo Ente è su `nascosta` — e per questo la dashboard **non scompone i propri numeri per causa**, che sarebbe il modo di aggirare quella regola con un conteggio invece che con una riga.
>
> ⚠️ **Una voce resta aperta, dichiarata invece che nascosta.** *«Gestione Documentale: **area dedicata**»* — non esiste a livello di Ente. Esistono l'upload nel tab Documenti della scheda, il download mediato dall'applicazione (🔗 ADR-026) e l'export PDF dello storico macchina (🔗 ADR-031), tutti **per singolo strumento**; non c'è nessun elenco documenti d'Ente né un export aggregato. È una funzionalità intera — rotta, elenco, filtri, permessi, retention, privacy — non un blocco di pagina d'atterraggio, e costruirla dentro queste due caselle sarebbe stata proprio la seconda superficie che questo lavoro ha rifiutato di costruire per abitudine. Riga aperta in roadmap.

**5. Interfaccia Manutentori / Tecnici**

L'ambiente operativo dedicato esclusivamente al personale sul campo.

- **Interfaccia UI Mobile-First:** Ottimizzazione per schermi piccoli per inserire report di fine intervento e leggere lo storico direttamente dal laboratorio.
- **Scansione QR Code Istantanea:** Accesso immediato alla scheda dello strumento inquadrando il QR code, bypassando la ricerca (riservato ai tecnici/Admin).
- **Associazione Dinamica Ricambi:** Possibilità di inserire il pezzo di ricambio e associarlo alla macchina in fase di attività (dato che a monte non si sanno tutti i ricambi). *Aggiornamento 3 Ago 2026 (ADR-022): si inserisce dal form intervento — checkbox "Ricambio effettuato" + righe con **nome** e **scadenza garanzia**; il codice costruttore è facoltativo.*
- **Completamento Interventi:** Utilizzo della spunta "Fatto" per contrassegnare l'esecuzione di interventi programmati o passati, con conseguente integrazione dell'azione anche nel tab "Ricambi".
