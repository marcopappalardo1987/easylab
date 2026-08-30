📋 Documento delle Funzionalità: Easy Lab

*Versione 1.1 - Master Feature List Aggiornata*

1. Architettura Multi-Tenant e Struttura Gerarchica

Il sistema deve scalare dal piccolo laboratorio al grande polo universitario, mantenendo l'isolamento dei dati.

- **Albero Gerarchico Dinamico:** Strutturazione flessibile su più livelli: Ente/Cliente Principale ➔ Dipartimento ➔ Sotto-laboratorio ➔ Strumento.
- **Gestione Permessi Granulare (RBAC):**

  - *Admin Cliente:* Visione e gestione totale di tutto l'albero del proprio Ente.
  - *Responsabile Reparto/Sotto-laboratorio:* Accesso limitato solo agli strumenti del proprio reparto assegnato.

- **Data Isolation:** Nessun cliente può mai vedere i dati, i macchinari o i documenti di un altro cliente.
- **Persone dell'Ente (🔗 ADR-038):** `/utenti` consente di invitare persone nel solo Ente corrente, conferire Admin, Responsabile Reparto, Tenant o Tecnico interno, cambiare ruolo, reinviare l'invito, cestinare e ripristinare. L'ultimo Admin e i ruoli Developer/Superadmin sono protetti; un Admin invitato o promosso entra fra i membri dell'Account.

2. Anagrafica Strumenti, Ricambi e Tracciamento Interventi (Risevata solo a EasyLab, il tenant non vedrà questo)

Il cuore operativo per la catalogazione degli asset fisici e della loro manutenzione logica e cronologica.

- **Scheda Macchinari (Asset):** Ogni strumento ha una scheda digitale completa con data installazione, modello, parametri tecnici e ubicazione attuale.
- **Tracciamento Spostamenti:** Logica per registrare se e quando una macchina cambia luogo fisico (es. spostata dal laboratorio A al laboratorio B), mantenendo l'esatto storico di ogni trasferimento.
- Prevedere la ricerca per ogni singolo strumento in quali laboratori è montato quello strumento.
- **Tracciamento Interventi (Cronologia e Scadenze) visibile anche al tenant:** Sistema di registrazione e programmazione della manutenzione:

  - Inserimento interventi con data di esecuzione passata (storico) o futura (preventiva/pianificata).
  - Spunta di completamento (Checkbox "Fatto") per segnare l'avvenuta esecuzione di un intervento futuro o passato.
  - Integrazione Tab Ricambi: Se un intervento riguarda la sostituzione o l'ispezione di specifici pezzi, l'azione deve essere visibile, collegata e gestibile anche direttamente dal tab "Ricambi" della macchina.

  > **Tipologie di intervento (briefing 3 Ago 2026 — vedi ADR-021):** manutenzione **ordinaria**, manutenzione **straordinaria**, manutenzione **full risk**, **taratura e certificazione** (+ `altro` come fallback). Sono **quattro** voci più il fallback: "taratura e certificazione" è **una voce sola**, non due — la taratura si chiude con il certificato. Le voci `ispezione` e `riparazione` della prima bozza **non esistono** nel linguaggio del cliente e sono state eliminate: quel lavoro è manutenzione ordinaria o straordinaria.
  >
  > **Ricambio effettuato (briefing 3 Ago 2026 — vedi ADR-022):** il form di un nuovo intervento porta una **checkbox "Ricambio effettuato"**; spuntandola compaiono righe ripetitore in cui si scrivono **nome del ricambio** e **scadenza della sua garanzia**. Ogni riga alimenta il catalogo ricambi, il tab "Ricambi" della macchina e — tramite la garanzia del pezzo — il **semaforo** (ADR-020). È il modo in cui l'"integrazione col tab Ricambi" descritta qui sopra si realizza in pratica.

- **Gestione Fornitori:** creare un'associazione diretta tra i macchinari e l'anagrafica dei fornitori da cui vengono acquistati.
  > **Precisazione del 3 Agosto 2026 (vedi ADR-023):** **ogni macchinario è associato a *un* fornitore** — quello d'acquisto. La relazione è quindi 1-N (`strumenti.fornitore_id`) e non il pivot molti-a-molti della prima stesura dell'ERD. L'anagrafica è **popolata da ciascun Ente** e scopata per `tenant_id` come ogni altra tabella di business: ognuno vede e gestisce solo i propri fornitori. Il campo è **obbligatorio nel form**, ma nullable nello schema, perché le righe storiche e gli import non ne hanno uno.

3. Gestione Manutenzione, Garanzie e Obsolescenza

Il motore logico per garantire l'efficienza degli strumenti e prevenire i guasti.

- **Sistema Visivo "A Semaforo":** Dashboard con indicatore visivo immediato per ogni macchina:

  - 🟢 *Pallina Verde:* Strumento in regola, manutenzione ok.
  - 🔴 *Pallina **Arancione**:* Intervento o scadenza da eseguire, attenzione richiesta. Quindi fine garanzia e necessità di garanzia. Deve essere sia automatico che non automatico.
  - 🔴 *Pallina Rosso:* Macchinario non idoneo. Dopo verifica avviene manualmente.
  > **Tab Panoramica (richiesta del 3 Agosto 2026 — vedi ADR-024).** Con le cause del semaforo sparse fra i tab (interventi, garanzie macchina, garanzie ricambio, forzatura), la scheda strumento apre su un **tab "Panoramica"** che mostra a colpo d'occhio **il motivo** dello stato acceso — ogni motivo cliccabile porta alla riga che lo genera — più prossimo intervento, ultimo eseguito, garanzie, sintesi anagrafica e qualche statistica della macchina. Non è una terza verità: non calcola nulla di suo, riusa le stesse regole del semaforo e della lista attività.
  >
  > **Logica del semaforo (vedi ADR-005):** il semaforo è un **segnale di sintesi**; la fonte di verità è la **lista attività/interventi del macchinario** (scadenze passate fatte/non fatte e future), visibile entrando nella scheda. Lo stato è **calcolato automaticamente** (verde = tutto ok; arancione = attività scaduta-non-fatta o scadenza/garanzia imminente). È sempre possibile **forzarlo manualmente**: in tal caso lo **stato forzato vince**, ma viene **tracciato** *chi* lo ha forzato, *quando* e (opzionale) il *motivo*, con un badge "forzato" sul pallino. La forzatura non nasconde i problemi reali, che restano nella lista attività.

- **Motore delle Garanzie Sdoppiato:** Tracciamento della garanzia del macchinario principale E tracciamento della garanzia del singolo pezzo di ricambio sostituito (es. garanzia di 12 mesi ~~o legata alle ore di utilizzo del macchinario~~). Il discorso garanzie ricambi deve essere visibile solo a easylab e non al tenant.
  > **⚠️ Correzione del 3 Agosto 2026 — la garanzia "a ore" non esiste (vedi ADR-019).** Era un fraintendimento del briefing di scoping, confermato tale dal cliente. Ogni garanzia ha una sola forma: `data_inizio` + `durata_mesi` → `data_scadenza_effettiva`, il campo unico che pilota semaforo e notifiche. Cadono con essa la soglia ore, la "data prevista", le **letture contaore** e la voce V1.1 sull'estrapolazione automatica del ritmo d'uso.
  >
  > **I ricambi condizionano il semaforo (vedi ADR-020).** Anche la garanzia di un ricambio pesa sullo stato dello strumento su cui è montato, con la stessa scala della garanzia macchina: **dentro i termini → verde**, **manca un mese (30 gg) → arancione**, **scaduta → arancione**. Il Tenant continua a **non vedere** le righe garanzia-ricambio, ma vede l'arancione che ne deriva: il divieto è sul dato, non sul suo effetto — un semaforo che dice cose diverse a seconda di chi guarda non sarebbe più un semaforo.
- **Calcolo Obsolescenza (Regola dei 10 anni):** Sistema che segnala quando una macchina supera i 10 anni di vita (i ricambi non sono più garantiti per legge), dichiarandola "obsoleta" ma ancora manutenibile ove possibile.

4. Interfaccia Tecnici e QR Code (Mobile-First)

Funzionalità pensate per l'operatività sul campo.

- **Generazione QR Code Univoco:** Il sistema genera automaticamente un QR code per ogni macchina registrata, pronto per essere stampato e applicato fisicamente.
- **Scansione e Accesso Istantaneo:** Il tecnico (o il cliente) inquadra il QR con lo smartphone e accede immediatamente alla scheda digitale dello strumento, bypassando la ricerca manuale. Funzionalità solo per EasyLab o tecnici.
  > **Regola di sicurezza (vedi ADR-003):** il QR è una scorciatoia di navigazione, non un accesso che bypassa i permessi. La scansione apre una **URL firmata**; se l'utente non è autenticato viene portato al login; la scheda è mostrata **solo** se ha i permessi su quello strumento (stesso tenant/ruolo). Nessun dato è mai visibile senza autenticazione.
- **UI Responsiva Mobile:** Interfaccia ottimizzata per schermi piccoli, per permettere ai tecnici di leggere lo storico e inserire i report di fine intervento direttamente in laboratorio.
- **Assegnatari coerenti col portafoglio (🔗 ADR-038):** per ogni macchina la tendina offre le persone vive del suo Ente e i soli tecnici EasyLab che hanno quella sede in portafoglio. Il tecnico interno appartiene all'Ente; quello esterno ha `tenant_id = NULL` e lavora su più clienti tramite portafoglio ∪ assegnazioni.

5. Automazioni e Comunicazioni ("Email del Futuro")

Il sistema lavora in background per avvisare i clienti senza intervento umano.

- **Scadenziario Interventi Preventivi:** Pianificazione automatica o manuale delle date future in cui bisogna intervenire (es. pulizia filtri programmata tra 8 mesi).
- **Notifiche "Email del Futuro":** Cron jobs che inviano automaticamente email (ed eventuali notifiche push/in-app) all'approssimarsi di scadenze di manutenzione o per il rinnovo di contratti di assistenza globale.

6. Gestione Documentale e Reportistica

Archivio senza carta per la burocrazia tecnica.

- **Archivio Certificazioni:** Area upload nella scheda dello strumento per allegare certificati di taratura, manuali o conformità.
  > **Scadenze documenti (vedi ADR-009):** i documenti con validità a tempo (es. taratura) non sono solo archivio: si modellano come un'**Attività/Intervento** (tipo "taratura") con data di scadenza e certificato allegato, così la scadenza alimenta il **semaforo** e l'**email del futuro** riusando il motore esistente (ADR-005). I documenti senza scadenza (manuali, conformità) restano semplice allegato.
- **Report di Fine Lavoro:** Generazione e archiviazione dei fogli di intervento al termine di ogni riparazione/manutenzione. Allegato all’attività di manutenzione o ricambi.
- **Esportazione PDF:** Possibilità per il cliente e per l'Admin di esportare storici, certificati e report di fine lavoro in formato PDF formattato.

7. Motore SaaS e Gestione Abbonamenti (Cashier)

Logica di fatturazione e accesso alla piattaforma.

- **Due Modelli di Business:**

  - *Abbonamento Free (Omaggiato):* Per i clienti che firmano un contratto di manutenzione "chiavi in mano" fisico.
  - *Abbonamento a Pagamento (SaaS):* Per i clienti che usano solo il software per autogestire le proprie macchine.

- **Sincronizzazione Stato Pagamenti:** Integrazione con Stripe/Cashier per controllare lo stato dell'abbonamento.
- **Blocco Automatico Insoluti:** Se l'abbonamento scade o il pagamento fallisce, il sistema blocca automaticamente l'accesso del cliente (tenant) mantenendo i dati salvi lato Super Admin.

8. Pannello Super Admin (Controllo Globale)

Gli strumenti per te e per la gestione del business.

- **User Impersonation:** Funzionalità con 1-click per "entrare nei panni" di un cliente (senza conoscerne la password) per fare assistenza tecnica sul software, risolvere bug o verificare la configurazione, con banner persistente per tornare Admin.
- **Dashboard Globale:** Vista generale su tutti i clienti, numero di laboratori attivati, strumenti totali gestiti, MRR (Ricavi Mensili Ricorrenti) e log di sistema.
- **Tecnici EasyLab e portafoglio (🔗 ADR-038):** `/piattaforma/tecnici`, riservata da `tenants.view_all` a Developer e Superadmin, invita tecnici col ruolo fisso `Tecnico` e nessun Ente, mostra quante sedi hanno in portafoglio e assegna/revoca le sedi. Ogni spunta apre tutte le macchine della sede e rende il tecnico selezionabile come assegnatario.

> **Stato al 30 Ago 2026:** codice e documentazione della funzione esistono; restano pendenti l'applicazione della migration `users.deleted_at` al DB di sviluppo e la verifica manuale delle due pagine e della tendina.

Non sappiamo a monte tutti i prodotti che monta una macchina ma dobbiamo avere solo il macchinario con la scheda tecnica. Poi quando viene fatta un attività potrò inserire il pezzo di ricambio con codice e descrizione e associarlo a una macchina.

> **Gestione ricambi (vedi ADR-008):** l'inserimento "al volo" alimenta un **catalogo ricambi incrementale**. Quando inserisci il pezzo, l'autocomplete collega la voce se esiste già, altrimenti la crea (riutilizzabile e ricercabile). Così la **ricerca incrociata** ("in quali macchine/laboratori è montato il ricambio X") resta affidabile. L'associazione macchina↔ricambio↔intervento è registrata a parte; la garanzia del singolo pezzo si aggancia a quella associazione.
>
> **Aggiornamento 3 Agosto 2026 (vedi ADR-022).** Il punto d'ingresso è il **form dell'intervento**: checkbox "Ricambio effettuato" + righe ripetitore con **nome** del pezzo e **scadenza garanzia** (obbligatoria). L'autocomplete lavora quindi sul **nome** e non più sul codice, che diventa facoltativo: durante l'intervento il codice costruttore spesso non è a portata di mano, e obbligarlo porterebbe a inventarlo — degradando proprio la ricerca incrociata che doveva proteggere.

Se è un piano in abbonamento il cliente deve essere autonomo nel tracciamento di interventi e manutenzioni. Può essere venduto a terzi che diventano come se fossero easylab.
