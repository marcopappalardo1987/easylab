**1. Sviluppo Dashboard Developer**

- [ ] Configurare l'accesso ai log di sistema globali per il monitoraggio e l'identificazione dei bug.
- [ ] Implementare la funzionalità "User Impersonation" (accesso account con 1-click) e inserire un banner persistente per il ripristino dei privilegi.

**2. Sviluppo Dashboard Superadmin (EasyLab)**

- [ ] Integrare la gestione dell'infrastruttura SaaS e dei piani in abbonamento tramite Stripe/Cashier.
- [ ] `[V1.1 — Rivenditori]` Creare il sistema di abilitazione e gestione per i Rivenditori/Admin terzi.
- [ ] Costruire la Dashboard Globale (Master) per visualizzare metriche totali (clienti, laboratori, strumenti, MRR globale).
- [ ] Implementare l'erogazione degli abbonamenti "Free" per i clienti con contratto "chiavi in mano".
- [ ] Sviluppare il blocco automatico degli accessi per insoluti o abbonamenti scaduti.
- [ ] Assicurarsi che la dashboard erediti e includa tutte le funzionalità previste per gli Admin.

**3. Sviluppo Dashboard Admin (Proprietari Abbonati)**

- [ ] Abilitare la gestione autonoma per il tracciamento di interventi e manutenzioni.
- [ ] Strutturare l'anagrafica per la creazione schede macchinari, tracciamento trasferimenti e ricerca incrociata degli strumenti.
- [ ] Costruire il motore sdoppiato delle garanzie (macchina e singolo ricambio), rendendolo invisibile ai Tenant. *(Aggiornato dal briefing del 3 Ago 2026: **solo garanzie a data**, la garanzia a ore non esiste — 🔗 ADR-019; le garanzie ricambio **restano invisibili al Tenant ma accendono il suo semaforo** — 🔗 ADR-020.)*
- [ ] Implementare gli alert automatici per l'obsolescenza dei macchinari che superano i 10 anni.
- [ ] Creare l'associazione tra fornitori e macchinari, e aggiungere la funzionalità manuale per attivare la "Pallina Rossa" in caso di inidoneità.

**4. Sviluppo Dashboard Tenant (Laboratori Finali)**

- [ ] Garantire l'isolamento assoluto dei dati (il tenant vede solo i propri macchinari).
- [ ] Creare il sistema visivo a semaforo (Pallina Verde: OK; Pallina Arancione: intervento/scadenza; Pallina Rossa: inidoneo).
- [ ] Sviluppare la vista per il tracciamento degli interventi con date passate o future.
- [ ] Configurare i cron jobs per l'automazione delle notifiche ("Email del Futuro") su scadenze e rinnovi.
- [ ] Strutturare l'area documentale per l'upload di certificati di taratura, download report ed esportazione PDF.
- [ ] Verificare il blocco di visibilità (Privacy) per nascondere le dinamiche delle garanzie sui ricambi.

**5. Sviluppo Interfaccia Manutentori / Tecnici**

- [ ] Ottimizzare l'interfaccia UI in ottica Mobile-First per facilitare il lavoro direttamente nei laboratori.
- [ ] Integrare la funzionalità di scansione QR Code per accedere immediatamente alla scheda dello strumento (riservata a tecnici/Admin).
- [ ] Abilitare l'associazione dinamica dei ricambi, permettendo l'inserimento del pezzo in fase di intervento. *(Aggiornato dal briefing del 3 Ago 2026 — 🔗 ADR-022: checkbox "Ricambio effettuato" + righe con **nome** e **scadenza garanzia**; il codice costruttore diventa facoltativo.)*
- [ ] Sviluppare il sistema di spunta "Fatto" per il completamento degli interventi, collegandolo dinamicamente al tab "Ricambi".
