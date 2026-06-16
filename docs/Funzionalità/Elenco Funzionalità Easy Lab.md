📋 Documento delle Funzionalità: Easy Lab

*Versione 1.1 - Master Feature List Aggiornata*

1. Architettura Multi-Tenant e Struttura Gerarchica

Il sistema deve scalare dal piccolo laboratorio al grande polo universitario, mantenendo l'isolamento dei dati.

- **Albero Gerarchico Dinamico:** Strutturazione flessibile su più livelli: Ente/Cliente Principale ➔ Dipartimento ➔ Sotto-laboratorio ➔ Strumento.
- **Gestione Permessi Granulare (RBAC):**

  - *Admin Cliente:* Visione e gestione totale di tutto l'albero del proprio Ente.
  - *Responsabile Reparto/Sotto-laboratorio:* Accesso limitato solo agli strumenti del proprio reparto assegnato.

- **Data Isolation:** Nessun cliente può mai vedere i dati, i macchinari o i documenti di un altro cliente.

2. Anagrafica Strumenti, Ricambi e Tracciamento Interventi (Risevata solo a EasyLab, il tenant non vedrà questo)

Il cuore operativo per la catalogazione degli asset fisici e della loro manutenzione logica e cronologica.

- **Scheda Macchinari (Asset):** Ogni strumento ha una scheda digitale completa con data installazione, modello, parametri tecnici e ubicazione attuale.
- **Tracciamento Spostamenti:** Logica per registrare se e quando una macchina cambia luogo fisico (es. spostata dal laboratorio A al laboratorio B), mantenendo l'esatto storico di ogni trasferimento.
- Prevedere la ricerca per ogni singolo strumento in quali laboratori è montato quello strumento.
- **Tracciamento Interventi (Cronologia e Scadenze) visibile anche al tenant:** Sistema di registrazione e programmazione della manutenzione:

  - Inserimento interventi con data di esecuzione passata (storico) o futura (preventiva/pianificata).
  - Spunta di completamento (Checkbox "Fatto") per segnare l'avvenuta esecuzione di un intervento futuro o passato.
  - Integrazione Tab Ricambi: Se un intervento riguarda la sostituzione o l'ispezione di specifici pezzi, l'azione deve essere visibile, collegata e gestibile anche direttamente dal tab "Ricambi" della macchina.

- **Gestione Fornitori:** creare un'associazione diretta tra i macchinari e l'anagrafica dei fornitori da cui vengono acquistati.

3. Gestione Manutenzione, Garanzie e Obsolescenza

Il motore logico per garantire l'efficienza degli strumenti e prevenire i guasti.

- **Sistema Visivo "A Semaforo":** Dashboard con indicatore visivo immediato per ogni macchina:

  - 🟢 *Pallina Verde:* Strumento in regola, manutenzione ok.
  - 🔴 *Pallina **Arancione**:* Intervento o scadenza da eseguire, attenzione richiesta. Quindi fine garanzia e necessità di garanzia. Deve essere sia automatico che non automatico.
  - 🔴 *Pallina Rosso:* Macchinario non idoneo. Dopo verifica avviene manualmente.
  > **Logica del semaforo (vedi ADR-005):** il semaforo è un **segnale di sintesi**; la fonte di verità è la **lista attività/interventi del macchinario** (scadenze passate fatte/non fatte e future), visibile entrando nella scheda. Lo stato è **calcolato automaticamente** (verde = tutto ok; arancione = attività scaduta-non-fatta o scadenza/garanzia imminente). È sempre possibile **forzarlo manualmente**: in tal caso lo **stato forzato vince**, ma viene **tracciato** *chi* lo ha forzato, *quando* e (opzionale) il *motivo*, con un badge "forzato" sul pallino. La forzatura non nasconde i problemi reali, che restano nella lista attività.

- **Motore delle Garanzie Sdoppiato:** Tracciamento della garanzia del macchinario principale E tracciamento della garanzia del singolo pezzo di ricambio sostituito (es. garanzia di 12 mesi o legata alle ore di utilizzo del macchinario). Il discorso garanzie ricambi deve essere visibile solo a easylab e non al tenant.
  > **Modello garanzie a ore (vedi ADR-004):** ogni garanzia si normalizza in un'unica `data_scadenza_effettiva` che pilota semaforo e notifiche (il motore ragiona sempre su date). Per le garanzie "a ore" l'utente può, in alternativa: (a) inserire a mano la **data prevista** di raggiungimento della soglia ore; oppure (b) registrare periodicamente le **letture del contaore** (storico salvato). In V1 la data prevista è sempre inserita manualmente; l'estrapolazione automatica del ritmo d'uso dalle letture è prevista per V1.1.
- **Calcolo Obsolescenza (Regola dei 10 anni):** Sistema che segnala quando una macchina supera i 10 anni di vita (i ricambi non sono più garantiti per legge), dichiarandola "obsoleta" ma ancora manutenibile ove possibile.

4. Interfaccia Tecnici e QR Code (Mobile-First)

Funzionalità pensate per l'operatività sul campo.

- **Generazione QR Code Univoco:** Il sistema genera automaticamente un QR code per ogni macchina registrata, pronto per essere stampato e applicato fisicamente.
- **Scansione e Accesso Istantaneo:** Il tecnico (o il cliente) inquadra il QR con lo smartphone e accede immediatamente alla scheda digitale dello strumento, bypassando la ricerca manuale. Funzionalità solo per EasyLab o tecnici.
  > **Regola di sicurezza (vedi ADR-003):** il QR è una scorciatoia di navigazione, non un accesso che bypassa i permessi. La scansione apre una **URL firmata**; se l'utente non è autenticato viene portato al login; la scheda è mostrata **solo** se ha i permessi su quello strumento (stesso tenant/ruolo). Nessun dato è mai visibile senza autenticazione.
- **UI Responsiva Mobile:** Interfaccia ottimizzata per schermi piccoli, per permettere ai tecnici di leggere lo storico e inserire i report di fine intervento direttamente in laboratorio.

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

Non sappiamo a monte tutti i prodotti che monta una macchina ma dobbiamo avere solo il macchinario con la scheda tecnica. Poi quando viene fatta un attività potrò inserire il pezzo di ricambio con codice e descrizione e associarlo a una macchina.

> **Gestione ricambi (vedi ADR-008):** l'inserimento "al volo" alimenta un **catalogo ricambi incrementale**. Quando inserisci codice e descrizione, l'autocomplete collega la voce se il codice esiste già, altrimenti la crea (riutilizzabile e ricercabile). Così la **ricerca incrociata** ("in quali macchine/laboratori è montato il ricambio X") resta affidabile. L'associazione macchina↔ricambio↔intervento è registrata a parte; la garanzia del singolo pezzo si aggancia a quella associazione.

Se è un piano in abbonamento il cliente deve essere autonomo nel tracciamento di interventi e manutenzioni. Può essere venduto a terzi che diventano come se fossero easylab.
