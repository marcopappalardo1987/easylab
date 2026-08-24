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
>
> **Ancora da fare in S6**: vista **Audit log**, **editor permessi ruolo**, e le dashboard dedicate agli altri ruoli.



**3. Dashboard Admin (Proprietari che si abbonano)**

Questi sono i clienti che acquistano il SaaS per operare in autonomia sui propri laboratori o per gestire i propri clienti terzi. Agiscono come un "piccolo EasyLab" limitato al proprio recinto.

- **Gestione Autonoma del SaaS:** Completa autonomia nel tracciamento di interventi e manutenzioni per i propri clienti/laboratori.
- **Anagrafica e Tracciamento:** Creazione delle schede macchinari, registrazione dei trasferimenti fisici tra laboratori e ricerca per singolo strumento nei vari laboratori.
- **Gestione Garanzie e Ricambi:** Accesso riservato (nascosto ai Tenant) al motore delle garanzie sdoppiato (macchina intera e singolo pezzo di ricambio). *Aggiornamento 3 Ago 2026: garanzie **solo a data**, la garanzia a ore non esiste (ADR-019); la garanzia del ricambio **accende il semaforo** dello strumento anche per il Tenant, che continua a non vederne il dettaglio (ADR-020).*
- **Valutazione Obsolescenza:** Alert automatico e gestione per le macchine che superano i 10 anni (ricambi non più garantiti per legge).
- **Gestione Fornitori e Anomalie:** Associazione diretta tra macchinari e fornitori, oltre alla gestione manuale della "Pallina Rossa" (macchinario non idoneo) a seguito di verifiche.

**4. Dashboard Tenant (I Laboratori / Enti Finali)**

Questa è l'interfaccia usata dai dipartimenti o laboratori fisici. Vedono solo i propri strumenti, con isolamento assoluto dei dati da altri clienti.

- **Sistema Visivo "A Semaforo":** Controllo immediato delle proprie macchine (Pallina Verde = in regola; Pallina Arancione = intervento/scadenza garanzia da eseguire; Pallina Rossa = non idoneo). *Aggiornamento 3 Ago 2026 (ADR-024): la scheda strumento apre su un **tab Panoramica** che elenca i **motivi** dello stato acceso, con link alla riga d'origine; i motivi che nascono da garanzie ricambio sono mostrati al Tenant in forma neutra e non cliccabile.*
- **Tracciamento Interventi:** Visualizzazione degli interventi con data passata o futura.
- **Automazioni e "Email del Futuro":** Ricezione delle notifiche programmate tramite cron jobs per l'approssimarsi di scadenze o rinnovi contrattuali.
- **Gestione Documentale:** Area dedicata per caricare certificati di taratura, scaricare report di fine lavoro ed esportare storici o certificati in PDF.
- **Limitazione di Visibilità (Privacy):** Il tenant è escluso dalla visione delle dinamiche interne di garanzia sui singoli ricambi.

**5. Interfaccia Manutentori / Tecnici**

L'ambiente operativo dedicato esclusivamente al personale sul campo.

- **Interfaccia UI Mobile-First:** Ottimizzazione per schermi piccoli per inserire report di fine intervento e leggere lo storico direttamente dal laboratorio.
- **Scansione QR Code Istantanea:** Accesso immediato alla scheda dello strumento inquadrando il QR code, bypassando la ricerca (riservato ai tecnici/Admin).
- **Associazione Dinamica Ricambi:** Possibilità di inserire il pezzo di ricambio e associarlo alla macchina in fase di attività (dato che a monte non si sanno tutti i ricambi). *Aggiornamento 3 Ago 2026 (ADR-022): si inserisce dal form intervento — checkbox "Ricambio effettuato" + righe con **nome** e **scadenza garanzia**; il codice costruttore è facoltativo.*
- **Completamento Interventi:** Utilizzo della spunta "Fatto" per contrassegnare l'esecuzione di interventi programmati o passati, con conseguente integrazione dell'azione anche nel tab "Ricambi".
