**1. Dashboard Developer (Tu)**

Questa è la vista di massimo livello tecnico, dove tu come sviluppatore controlli l'infrastruttura e il codice, invisibile a chiunque altro.

- **Monitoraggio di Sistema:** Accesso ai log di sistema globali per identificare bug o malfunzionamenti.
- **User Impersonation (Tecnica):** Funzionalità con 1-click per impersonificare qualsiasi utente nel database allo scopo di fare assistenza tecnica profonda o risolvere bug, con banner persistente per tornare ai privilegi massimi.

**2. Dashboard Superadmin (EasyLab)**

Questa è la cabina di regia commerciale e operativa del proprietario originario della piattaforma (EasyLab).

- **Controllo Infrastruttura SaaS:** Gestione dei piani in abbonamento tramite Stripe/Cashier.

- **Gestione Rivenditori/Admin:** Possibilità di vendere il software a terzi (che diventeranno Admin/Proprietari).
- **Dashboard Globale (Master):** Vista generale su tutti i clienti (sia propri che degli Admin), numero totale di laboratori attivati, strumenti totali gestiti e MRR (Ricavi Mensili Ricorrenti) dell'intera piattaforma.
- **Gestione Contratti Propri:** Erogazione dell'abbonamento "Free (Omaggiato)" per i clienti diretti di EasyLab che firmano un contratto di manutenzione "chiavi in mano" fisico.
- **Blocco Automatico Insoluti:** Gestione e visualizzazione del blocco automatico dell'accesso per i tenant/admin a cui scade l'abbonamento o fallisce il pagamento.
- **Tutte le funzionalità del Admin** sono integrate nel superadmin

**3. Dashboard Admin (Proprietari che si abbonano)**

Questi sono i clienti che acquistano il SaaS per operare in autonomia sui propri laboratori o per gestire i propri clienti terzi. Agiscono come un "piccolo EasyLab" limitato al proprio recinto.

- **Gestione Autonoma del SaaS:** Completa autonomia nel tracciamento di interventi e manutenzioni per i propri clienti/laboratori.
- **Anagrafica e Tracciamento:** Creazione delle schede macchinari, registrazione dei trasferimenti fisici tra laboratori e ricerca per singolo strumento nei vari laboratori.
- **Gestione Garanzie e Ricambi:** Accesso riservato (nascosto ai Tenant) al motore delle garanzie sdoppiato (macchina intera e singolo pezzo di ricambio).
- **Valutazione Obsolescenza:** Alert automatico e gestione per le macchine che superano i 10 anni (ricambi non più garantiti per legge).
- **Gestione Fornitori e Anomalie:** Associazione diretta tra macchinari e fornitori, oltre alla gestione manuale della "Pallina Rossa" (macchinario non idoneo) a seguito di verifiche.

**4. Dashboard Tenant (I Laboratori / Enti Finali)**

Questa è l'interfaccia usata dai dipartimenti o laboratori fisici. Vedono solo i propri strumenti, con isolamento assoluto dei dati da altri clienti.

- **Sistema Visivo "A Semaforo":** Controllo immediato delle proprie macchine (Pallina Verde = in regola; Pallina Arancione = intervento/scadenza garanzia da eseguire; Pallina Rossa = non idoneo).
- **Tracciamento Interventi:** Visualizzazione degli interventi con data passata o futura.
- **Automazioni e "Email del Futuro":** Ricezione delle notifiche programmate tramite cron jobs per l'approssimarsi di scadenze o rinnovi contrattuali.
- **Gestione Documentale:** Area dedicata per caricare certificati di taratura, scaricare report di fine lavoro ed esportare storici o certificati in PDF.
- **Limitazione di Visibilità (Privacy):** Il tenant è escluso dalla visione delle dinamiche interne di garanzia sui singoli ricambi.

**5. Interfaccia Manutentori / Tecnici**

L'ambiente operativo dedicato esclusivamente al personale sul campo.

- **Interfaccia UI Mobile-First:** Ottimizzazione per schermi piccoli per inserire report di fine intervento e leggere lo storico direttamente dal laboratorio.
- **Scansione QR Code Istantanea:** Accesso immediato alla scheda dello strumento inquadrando il QR code, bypassando la ricerca (riservato ai tecnici/Admin).
- **Associazione Dinamica Ricambi:** Possibilità di inserire il pezzo di ricambio (con codice e descrizione) e associarlo alla macchina in fase di attività (dato che a monte non si sanno tutti i ricambi).
- **Completamento Interventi:** Utilizzo della spunta "Fatto" per contrassegnare l'esecuzione di interventi programmati o passati, con conseguente integrazione dell'azione anche nel tab "Ricambi".
