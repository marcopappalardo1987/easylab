🧭 Decisioni Architetturali (ADR) — Easy Lab

*Log delle decisioni tecniche e di prodotto prese durante la fase di scoping. Ogni voce registra la scelta, le alternative valutate e il perché. Da consultare prima di mettere in discussione un'assunzione architetturale.*

---

**ADR-001 — Strategia Multi-tenancy: Single-Database con Row-Level Scoping**

*Stato: Accettata — punto "Superadmin/Developer bypassano lo scope" **superato da ADR-018** (nessun ruolo bypassa; accesso cross-tenant solo via impersonazione).*

**Contesto.** Easy Lab ha una gerarchia a due livelli (EasyLab → Admin/Rivenditore → Tenant/Laboratorio) e richiede dashboard globali aggregate (MRR totale, numero strumenti/laboratori su tutti i clienti).

**Decisione.** Un unico database condiviso. Isolamento dei dati a livello applicativo tramite Global Scope di Eloquent. Ogni record di business porta:
- `tenant_id` → il laboratorio/ente finale proprietario del dato;
- `reseller_id` → l'Admin/rivenditore che gestisce quel tenant (NULL se cliente diretto EasyLab).

Filtraggio in base al ruolo:
- **Tenant** → vede solo i record con il proprio `tenant_id`;
- **Reseller/Admin** → vede tutti i `tenant_id` sotto il proprio `reseller_id`;
- **Superadmin EasyLab** → bypassa il Global Scope (vede tutto, indispensabile per le dashboard globali);
- **Developer** → accesso tecnico totale.

**Alternative scartate.**
- *Multi-database (un DB per tenant, es. stancl/tenancy multi-db):* isolamento fisico più forte, ma rende complesse le dashboard globali aggregate (richiederebbero query e merge su N database) e complica la gerarchia rivenditori. Overhead operativo elevato.
- *Single-DB con pacchetto stancl/tenancy:* introduce una dipendenza e convenzioni esterne senza un vantaggio netto rispetto allo scoping manuale per il nostro caso.

**Conseguenze.**
- Le dashboard globali/MRR diventano semplici query aggregate.
- Serve un **test di isolamento automatico** ("il tenant A non legge mai dati del tenant B") fin dal giorno 1.
- Disciplina obbligatoria: ogni nuova tabella di business deve includere `tenant_id` (+ `reseller_id`) e il relativo Global Scope.

---

**ADR-002 — Rivenditori e relativo billing rimandati a V1.1**

*Stato: Accettata*

**Contesto.** Un Admin può "vendere a terzi che diventano come EasyLab" (rivenditore). Il punto critico è *chi incassa* dai laboratori finali: se i rivenditori incassano dai propri clienti servirebbe Stripe Connect (onboarding KYC, split payment, payout), che raddoppia la complessità del billing. La deadline V1 è fine Settembre.

**Decisione.** La funzionalità Rivenditori (gestione clienti terzi + billing relativo) è **rimandata a V1.1**. La V1 esce con i soli **clienti diretti EasyLab**:
- *Free (chiavi in mano)* — contratto di manutenzione fisico;
- *SaaS a pagamento* — il cliente autogestisce i propri laboratori.

Lo schema dati prevede già la colonna `reseller_id` (valorizzata a NULL in V1), così l'attivazione del tier Rivenditore in V1.1 non richiederà migrazioni distruttive.

**Alternative scartate (per la V1).**
- *EasyLab fattura solo il Rivenditore (tenant del rivenditore = Free):* semplice (solo Cashier), ma comunque fuori scope per la V1; resta un'opzione valida da valutare in V1.1.
- *Rivenditore incassa via Stripe Connect:* potente ma ad alta complessità; rimandata.

**Conseguenze.**
- V1: nessuna logica di rivendita; `reseller_id = NULL` ovunque.
- La roadmap marca con `[V1.1 — Rivenditori]` gli elementi rinviati.

---

**Aggiornamento (14 Giu 2026) — Modello di incasso del rivenditore deciso.**

A seguito del chiarimento del modello di business (EasyLab è il Superadmin unico; offre la piattaforma a laboratori privati e pubblici, sempre trattati come *Enti*; può venderla ad aziende similari — i *rivenditori* — che la ripropongono ai propri clienti), si fissa il modello di incasso che ADR-002 aveva lasciato aperto. Esistono **tre rapporti di incasso distinti**:

| # | Chi paga | Chi incassa | Mezzo | Scope |
|---|---|---|---|---|
| **A** | Ente diretto (privato o pubblico) | EasyLab | Stripe / Laravel Cashier (account EasyLab) | V1 |
| **B** | Rivenditore | EasyLab — quota mensile fissa | Stripe / Laravel Cashier (account EasyLab) | V1.1 |
| **C** | Ente del rivenditore | **il Rivenditore** (proprio Stripe) | Stripe del rivenditore — **i fondi non transitano mai su EasyLab** | V1.1 |

**Decisione per C (la parte nuova): Stripe Connect — account "Standard" con "direct charges".**
1. Il rivenditore collega il **proprio** account Stripe via OAuth ("Connetti con Stripe"); è lui il *merchant of record* dei suoi Enti.
2. Gli addebiti agli Enti del rivenditore sono creati **sul suo connected account** (SDK Stripe con `stripe_account`, **non** Cashier): i fondi si depositano sul conto del rivenditore e **non passano mai dal saldo di EasyLab** (requisito esplicito del modello di business).
3. EasyLab resta fuori dal flusso denaro: niente KYC su EasyLab, niente payout/riconciliazione, nessuna responsabilità sui chargeback del rivenditore. `application_fee = 0` (il rivenditore paga già la quota fissa B); resta la leva di trattenere una fee per transazione in futuro senza cambiare architettura.

*Alternativa scartata:* **BYO API keys** (il rivenditore inserisce le proprie chiavi Stripe nell'app) — funzionerebbe ma impone a EasyLab la custodia di chiavi segrete live di terzi (cifratura, responsabilità in caso di leak) e webhook per-rivenditore ingestibili. Tenuta solo come fallback.

**Conseguenze aggiornate.**
- **A + B** condividono lo stesso meccanismo: **Laravel Cashier sull'account EasyLab** (il pagatore è a volte un Ente, a volte un Rivenditore; entrambi sono "account fatturabili"). Già compatibile con lo stack V1.
- **C** richiede un'integrazione Connect dedicata (SDK Stripe su connected account) → è il "raddoppio di complessità" che giustifica il rinvio a V1.1; la V1 esce con il solo flusso A.
- **Modello dati:** si introduce la tabella **`resellers`** a livello piattaforma (definita ma non popolata in V1), target di `reseller_id`, con i campi della connessione Stripe Connect (`stripe_connect_account_id`, stato). Dettaglio in `Modello Dati (ERD).md` §4.2.
- Scegliere **Standard + direct charges** (non destination/separate charges) è anche una scelta di responsabilità: EasyLab non detiene mai fondi altrui e non diventa intermediario di pagamento.

**Avvertenza settore pubblico (collegata ad ADR-010).** Per gli Enti **pubblici**, l'incasso self-service via Stripe (carta, abbonamento ricorrente) è spesso impraticabile: la PA tipicamente paga con **determina/mandato → bonifico a 30–60 gg**, può richiedere **split payment (scissione IVA)** e acquisto via **MEPA/CONSIP**, e impone la **fattura elettronica PA** (FatturaPA verso il **Codice Univoco Ufficio** iPA, 6 caratteri — diverso dal Codice Destinatario SDI a 7 dei privati). Conseguenza realistica: molti enti pubblici staranno sul percorso **Free + contratto + fattura offline** anziché sul SaaS Stripe. Il campo `codice_destinatario_sdi` va quindi interpretato con doppia semantica (privato vs PA). Verifica di business consigliata col commercialista prima di spingere il tier pubblico a pagamento.

---

**ADR-003 — Accesso via QR Code: login obbligatorio + scope dei permessi**

*Stato: Accettata*

**Contesto.** I documenti funzionalità erano contraddittori: in un punto "il tecnico *o il cliente* inquadra il QR e accede immediatamente alla scheda", in un altro "funzionalità *solo per EasyLab o tecnici*". Poiché il valore centrale del prodotto è l'isolamento dei dati, un QR che espone la scheda senza autenticazione sarebbe una fuga di dati (URL indovinabili o condivise mostrerebbero strumenti di altri clienti).

**Decisione.** La scansione del QR **non dà mai accesso a dati senza autenticazione**:
1. Il QR contiene una **URL firmata** (`URL::signedRoute`) verso la scheda dello strumento.
2. Se l'utente non è autenticato, viene reindirizzato al **login**.
3. Dopo il login, il **Global Scope** (ADR-001) verifica che l'utente abbia i permessi su quello strumento (stesso `tenant_id`/ruolo). In caso contrario: 403.
4. La scheda è mostrata **solo** se l'utente è autorizzato.

Il QR è quindi una scorciatoia di navigazione (evita la ricerca manuale), non un canale di accesso alternativo che bypassa i permessi.

**Alternative scartate.**
- *QR pubblico con scheda ridotta "non sensibile":* comodo, ma richiede di definire e mantenere con precisione cosa è "non sensibile"; rischio di esporre per errore dati utili a profilare un cliente. Rivalutabile in futuro se nascesse l'esigenza.
- *QR completamente pubblico:* incompatibile con l'isolamento dati. Scartato.

**Conseguenze.**
- Le rotte degli strumenti raggiunte da QR usano `signed` middleware + policy di autorizzazione.
- L'esperienza "mobile sul campo" presuppone che il tecnico sia loggato (sessione persistente sullo smartphone per ridurre l'attrito).

---

**ADR-004 — Garanzie a ore: normalizzazione in "data di scadenza effettiva"**

*Stato: Accettata*

**Contesto.** Il Motore Garanzie Sdoppiato prevede garanzie "legate alle ore di utilizzo del macchinario", ma non esisteva un meccanismo per far conoscere al sistema le ore di una macchina. Tracciare le ore in automatico (IoT/telemetria) è fuori scope.

**Decisione.** Qualunque sia il metodo di input, tutto si **normalizza in un unico campo `data_scadenza_effettiva`**: il motore di alert e il semaforo ragionano sempre e solo su date, mai su ore. Le ore sono solo un modo per arrivare a quella data. Due metodi di input convivono:
1. **Data prevista manuale** — l'utente stima e inserisce direttamente la data in cui le ore raggiungeranno la soglia.
2. **Letture contaore manuali** — l'utente registra periodicamente la lettura del contaore (es. durante un intervento); lo storico letture resta a sistema. (In V1 la data prevista resta comunque inserita a mano — vedi sotto.)

**Modello dati.**
- *Garanzia*: `soggetto` (macchina | ricambio), `tipo_scadenza` (`data` | `ore`), `data_inizio`, `durata_mesi` (se a data), `soglia_ore` (se a ore), `data_scadenza_prevista`, **`data_scadenza_effettiva`** (campo unico che guida alert/semaforo).
- *LetturaContaore* (opzionale): `strumento_id`, `data`, `ore`.

**Scope V1 vs V1.1.**
- *V1:* input manuale di entrambi (data prevista O letture). La garanzia a ore usa la `data_scadenza_prevista` così come inserita.
- *V1.1:* **estrapolazione automatica** — con ≥2 letture il sistema calcola il ritmo (ore/giorno) e aggiorna da solo la data prevista a ogni nuova lettura.

**Avvertenza.** La data prevista è una stima, non l'ora reale raggiunta: adeguata per gli alert ("ricorda di controllare"). Per un'eventuale rivalsa legale sulle ore esatte, fa fede la lettura contaore reale registrata al momento dell'intervento (metodo 2).

**Conseguenze.**
- Il motore semaforo/notifiche resta uniforme (solo date).
- Le garanzie sui ricambi restano visibili **solo a EasyLab/Admin**, mai al Tenant (vincolo di privacy già previsto).

---

**ADR-005 — Semaforo: stato automatico calcolato + forzatura manuale tracciata**

*Stato: Accettata*

**Contesto.** Il semaforo (🟢/🟠/🔴) era descritto come "sia automatico che non automatico" per l'arancione e "manuale dopo verifica" per il rosso, senza una regola chiara su come automatico e manuale convivono.

**Principio guida (importante).** Il **semaforo è solo un segnale d'impatto di sintesi**. La **fonte di verità è la lista delle attività/interventi del macchinario**: entrando nella scheda si vede l'elenco completo delle scadenze passate (attività fatte o non fatte) e future. Per questo una forzatura non può "nascondere" problemi reali: restano sempre visibili nella lista.

**Decisione.**
- Lo **stato è calcolato automaticamente** di default dalla lista attività/garanzie:
  - 🟢 *Verde:* nessuna attività scaduta-non-fatta né scadenza/garanzia imminente.
  - 🟠 *Arancione:* almeno un'attività scaduta e non eseguita, oppure una scadenza/fine garanzia imminente.
  - 🔴 *Rosso:* macchinario non idoneo (tipicamente esito di una forzatura manuale dopo verifica).
- È sempre possibile **forzare manualmente** il semaforo. **Se forzato, lo stato forzato vince** su quello calcolato.
- Ogni forzatura è **tracciata**: `forced_state`, `forced_by` (utente), `forced_at` (timestamp), `forced_reason` (motivo, opzionale ma raccomandato). UI: **badge "forzato"** sul pallino per distinguerlo dallo stato calcolato.

**Alternativa scartata.** *Solo rosso manuale, verde/arancione sempre automatici:* più semplice ma toglie la possibilità di forzare un'attenzione manuale; scartata perché serve poter forzare qualunque stato.

**Modello dati.**
- *Strumento*: oltre ai dati anagrafici, espone uno `stato_semaforo_calcolato` (derivato, non persistito o ricalcolato) e un blocco override opzionale (`forced_state`, `forced_by`, `forced_at`, `forced_reason`). Stato mostrato = override se presente, altrimenti calcolato.
- *Intervento/Attività* (fonte di verità del calcolo): `strumento_id`, `descrizione`, `data_scadenza` (passata o futura), `stato` (fatto | non fatto), `data_esecuzione`. Le attività scadute-non-fatte e le scadenze imminenti determinano l'arancione automatico.

**Conseguenze.**
- Il calcolo del verde/arancione è una funzione pura sulla lista attività + garanzie (`data_scadenza_effettiva` da ADR-004): facile da testare.
- Le forzature alimentano l'audit log (vedi nota su activity log nel Tech Stack), specialmente il "non idoneo".

---

**ADR-006 — Confine di isolamento: il Tenant è l'Ente/Cliente**

*Stato: Accettata*

**Contesto.** L'albero è Ente → Dipartimento → Sotto-laboratorio → Strumento, ma il "Responsabile Reparto" ha accesso limitato ai soli strumenti del proprio reparto. Non era chiaro se il confine di isolamento (`tenant_id`) fosse l'Ente o il singolo laboratorio.

**Decisione.** Il **`tenant_id` punta all'Ente/Cliente top-level**. Dipartimenti, sotto-laboratori e strumenti sono **sotto-divisioni interne al tenant**. L'isolamento dei dati avviene **tra Enti diversi** (tenancy, ADR-001); l'accesso ristretto *dentro* un Ente (es. il Responsabile Reparto che vede solo i suoi strumenti) si gestisce con **RBAC**, non con la tenancy.

**Conseguenze.**
- Un cliente (Ente) può avere la visione d'insieme di tutta la propria organizzazione.
- Serve un modello di **ruoli con ambito sul sotto-albero** (Dipartimento/Sotto-lab): es. `spatie/laravel-permission` con il concetto di "team"/scope, oppure una relazione utente↔nodo-albero che limita la visibilità. (Dettaglio da fissare nell'ERD.)
- Il Global Scope filtra per `tenant_id` (= Ente); un secondo livello di filtro applicativo restringe per reparto in base al ruolo dell'utente.
- Schema permessi azione-livello: vedi `Schema Ruoli e Permessi.md` (S0.2).

---

**ADR-007 — Accesso dei Tecnici: per assegnazione di intervento E per portafoglio clienti (in unione)**

*Stato: Accettata*

**Contesto.** I tecnici (staff EasyLab) operano su macchine di clienti/tenant diversi: serve un'eccezione controllata allo scoping per tenant (ADR-001/006) senza dare a ogni tecnico la visione globale di tutti i clienti (rischio privacy/GDPR).

**Decisione.** Un tecnico ottiene l'accesso tramite **due meccanismi che si sommano (unione)**:
1. **Per assegnazione di intervento** — il tecnico vede una macchina se ha un intervento assegnato su di essa (esposizione minima).
2. **Per portafoglio clienti** — il tecnico può essere assegnato a uno o più Enti e vede tutte le macchine di quei clienti.

Insieme accessibile del tecnico = *(strumenti con intervento assegnato a lui)* ∪ *(strumenti dei tenant nel suo portafoglio)*.

**Modello dati.**
- *tecnico_cliente* (pivot): `tecnico_id`, `ente_id` (= tenant). Grant a livello di portafoglio.
- *intervento*: campo `tecnico_id` (assegnatario). Grant puntuale sullo strumento dell'intervento.
- Global Scope per ruolo Tecnico: strumento visibile se `strumento.tenant_id IN (portafoglio del tecnico)` **OR** `strumento.id IN (strumenti con intervento assegnato al tecnico)`.

**Conseguenze.**
- Flessibilità operativa (portafoglio per chi segue stabilmente un cliente; assegnazione puntuale per interventi spot) con esposizione comunque limitata e tracciabile.
- **Audit:** ogni accesso del tecnico a una scheda strumento va loggato (vedi activity log), data la natura cross-tenant.
- Il ruolo Tecnico non è un utente-tenant: vive a livello piattaforma con accesso derivato da queste due fonti.
- Permessi del ruolo Tecnico: vedi `Schema Ruoli e Permessi.md` (S0.2).

---

**ADR-008 — Ricambi: catalogo incrementale con autocomplete**

*Stato: Accettata*

**Contesto.** Contraddizione tra due requisiti: "inserisci codice e descrizione al volo" (i pezzi non sono noti a monte) e "ricerca incrociata del ricambio su tutte le macchine" (che con testo libero diventa inaffidabile per doppioni/errori di battitura).

**Decisione.** Esiste un'**anagrafica ricambi (catalogo) che cresce incrementalmente**. All'inserimento di un pezzo durante un'attività:
1. L'**autocomplete** cerca per codice nel catalogo.
2. Se il codice esiste → si **collega** la voce esistente.
3. Se è nuovo → si **crea al volo** una nuova voce di catalogo, da subito riutilizzabile e ricercabile.

Si ottengono insieme la velocità "al volo" e la ricerca incrociata affidabile (la ricerca opera sulla voce di catalogo, non sul testo libero).

**Modello dati.**
- *Ricambio* (catalogo): `id`, `codice` (chiave di ricerca/autocomplete), `descrizione`, `tenant_id` (+ scoping). Voce normalizzata e riutilizzabile.
- *RicambioUtilizzo* (associazione macchina↔ricambio↔intervento): `strumento_id`, `ricambio_id`, `intervento_id`, quantità, data, eventuale garanzia del pezzo (ADR-004). È ciò che registra "questo pezzo è montato su questa macchina".
- Ricerca incrociata: dato un `ricambio_id`, elenca tutti gli `strumento_id`/laboratori dove è utilizzato.

**Conseguenze.**
- Possibili near-duplicati da errori di battitura: mitigati da autocomplete + normalizzazione del codice; l'**admin può unire (merge) doppioni**.
- La garanzia del singolo ricambio (ADR-004) si aggancia a *RicambioUtilizzo* (il pezzo specifico montato), non alla voce di catalogo generica.

---

**ADR-009 — Scadenze documenti (taratura): modellate come Attività, non come motore separato**

*Stato: Accettata*

**Contesto.** L'area documentale era pensata come archivio passivo, ma i certificati di taratura hanno una validità legale a tempo: la loro scadenza deve accendere il semaforo e generare notifiche, come qualunque altra scadenza.

**Decisione.** Una taratura (e ogni documento con valenza a scadenza) si modella come un'**Attività/Intervento** (es. `tipo = taratura`) con `data_scadenza` e il **certificato allegato**. Riusa interamente il motore semaforo/notifiche già definito (ADR-005): una taratura scaduta-non-rifatta accende l'arancione e alimenta l'"email del futuro". Nessun secondo motore di scadenze.

**Conseguenze.**
- Fonte di verità unica: tutte le scadenze (interventi, garanzie, tarature) confluiscono nella lista Attività del macchinario.
- I documenti senza scadenza (manuali, conformità) restano semplice archivio allegato (allo strumento o all'attività).
- Un documento può quindi essere allegato: (a) allo *Strumento* (manuale, scheda); (b) a un'*Attività/Intervento* (certificato di taratura, report di fine lavoro — ADR già implicito).

---

**ADR-010 — Fatturazione elettronica SDI: rimandata a versione futura**

*Stato: Accettata (parziale — vedi raccomandazione)*

**Contesto.** In Italia la fattura B2B deve transitare per lo SDI in formato XML. Stripe/Cashier gestiscono l'incasso ma non emettono la fattura conforme allo SDI. Riguarda il tier SaaS a pagamento (il "Free chiavi in mano" è fatturato a parte col contratto fisico).

**Decisione.** L'integrazione e-invoicing **è rimandata a una versione futura**. In V1 Stripe gestisce solo incasso/abbonamento; l'emissione della fattura elettronica resta fuori dal software (commercialista/manuale sui dati dei pagamenti). In una versione successiva si automatizzerà tramite un **servizio e-invoicing italiano** (es. Fatture in Cloud / Aruba / openapi.it via API, partendo dai webhook Stripe).

**Raccomandazione (a costo quasi nullo) — da confermare.** Già in V1 raccogliere nell'anagrafica cliente i **dati fiscali**: `partita_iva`, `codice_fiscale`, `pec` e/o `codice_destinatario_sdi`. Così l'attivazione futura dell'e-invoicing non richiederà di ricontattare tutti i clienti per ottenerli (retrofit doloroso).

**Conseguenze.**
- V1: nessuna logica SDI nel software.
- Versione futura: nuovo ADR per scegliere il provider e-invoicing e il flusso Stripe → XML → SDI.
- Roadmap: l'e-invoicing automatico è un elemento post-V1.

---

**ADR-011 — Canali di notifica V1: email (SMTP) + in-app, no push**

*Stato: Accettata*

**Decisione.** In V1 le notifiche ("email del futuro" e alert) usano **email via SMTP** + **notifiche in-app** nella dashboard. Niente push (web/mobile) in V1: l'app è web-responsive, non nativa; le push si valuteranno più avanti.

**Conseguenze.** Invio email accodato sulle code Redis (già nello stack). Provider SMTP da definire (es. Postmark/SES/Mailgun o SMTP standard). Le notifiche in-app implicano una tabella `notifications` (notifiche di Laravel) consultabile dall'utente.

---

**ADR-012 — Onboarding: provisioning + self-signup (entrambi)**

*Stato: Accettata*

**Decisione.** Convivono due flussi di ingresso:
- **Provisioning gestito** da EasyLab/Admin per i clienti "Free / chiavi in mano" (creazione Ente + utente admin, invito via email, set password).
- **Auto-registrazione pubblica (self-signup)** per il tier SaaS self-service (registrazione + attivazione abbonamento autonoma).

**Conseguenze.** Auth scaffold con registrazione pubblica abilitata ma protetta (verifica email + verifica pagamento prima dell'attivazione del tenant). Servono flussi anti-abuso/spam sul signup pubblico. Il provisioning richiede un flusso di invito utente.

---

**ADR-013 — Blocco per insoluto: lockout totale**

*Stato: Accettata*

**Decisione.** Tenant bloccato per insoluto/abbonamento scaduto → **lockout totale**: nessun accesso finché non regolarizza. I dati restano conservati lato Superadmin. Leva di pagamento forte.

**Salvaguardia (GDPR/portabilità).** Anche in lockout, il **Superadmin mantiene la capacità di esportare e fornire i dati** del tenant (certificati, storici) su richiesta legittima del cliente: i certificati di taratura sono legalmente del cliente e il diritto alla portabilità va comunque onorato, anche se non self-service.

---

**ADR-014 — Obsolescenza: da data installazione, soglia configurabile**

*Stato: Accettata*

**Decisione.** L'obsolescenza ("regola dei 10 anni") si calcola da **`data_installazione`** (già nella scheda macchinario). La soglia è un **parametro configurabile** (default 10 anni), non un valore fisso nel codice — adattabile a future norme o categorie diverse.

**Conseguenze.** `obsoleta = (oggi - data_installazione) >= soglia_obsolescenza`. La macchina obsoleta resta manutenibile ove possibile (solo segnalazione, non blocco).

---

**ADR-015 — Spostamenti macchine: anche tra Enti, storico sempre tracciato e trasferito al nuovo proprietario**

*Stato: Accettata*

**Contesto.** Le macchine possono spostarsi tra laboratori dello stesso Ente e anche essere trasferite tra Enti diversi (es. rivendita). Serviva definire la tracciabilità degli spostamenti e la visibilità dello storico dopo un trasferimento cross-tenant.

**Decisione.**
- Gli spostamenti sono **sempre tracciati** in un log dedicato (data, da dove, a dove, chi).
- Lo spostamento **dentro lo stesso Ente** (tra dipartimenti/laboratori) è una normale funzione operativa.
- Il **trasferimento tra Enti diversi** è consentito (azione di livello Superadmin, perché attraversa il confine di isolamento ADR-006).
- Dopo il trasferimento X → Y, **il nuovo proprietario Y vede l'intero storico interventi**, compreso il periodo in cui la macchina era di X (continuità manutentiva totale).
- Il log completo degli spostamenti resta comunque sempre visibile a **EasyLab/Superadmin**.

**Modello dati.**
- *SpostamentoStrumento* (log): `strumento_id`, `da_nodo` (lab/ente origine), `a_nodo` (lab/ente destinazione), `data`, `eseguito_da`, eventuale nota. Append-only.
- Il trasferimento cross-tenant aggiorna il `tenant_id` corrente dello strumento; lo storico interventi resta legato allo strumento (quindi segue la macchina).

**Rischio noto e mitigazione (GDPR).** Trasferendo l'intero storico, dati operativi e potenzialmente identificativi del precedente proprietario X (sedi, nomi tecnici, attività) diventano visibili a Y. Scelta deliberata per la continuità manutentiva. Mitigazioni consigliate: (a) prevederlo esplicitamente nei contratti/informativa privacy come da prassi sul passaggio di proprietà del bene; (b) EasyLab, come titolare/responsabile del trattamento, mantiene la base giuridica del trasferimento. *Fallback disponibile se in futuro emergessero criticità:* anonimizzazione dei riferimenti identificativi del periodo precedente (mantenendo i dati tecnici).

---

**ADR-016 — Permessi configurabili a runtime: matrice ruolo→permesso editabile dal Superadmin, con default da seeder e set "bloccato" protetto**

*Stato: Accettata*

**Contesto.** La matrice ruolo→permesso (`Schema Ruoli e Permessi.md`) definisce dei default, ma EasyLab vuole poter aggiustare nel tempo cosa può fare ciascun ruolo senza rilasciare nuovo codice. `spatie/laravel-permission` salva già la relazione ruolo→permesso a DB (`role_has_permissions`), quindi i permessi sono modificabili a runtime per natura: serviva decidere **chi** li modifica, con **quale ambito** e con **quali tutele** per non rompere isolamento/privacy.

**Decisione.**
- **Ambito globale, editor solo Superadmin/Developer.** I ruoli sono **globali** (un'unica matrice valida per tutta la piattaforma, niente modalità "teams" di spatie in V1). La modifica avviene da una UI nella Dashboard Superadmin (permesso `roles.manage`). La personalizzazione per-Ente da parte dell'Admin è rimandata a V1.1.
- **Default da seeder.** Il `RolesAndPermissionsSeeder` (S1) imposta i default della matrice §5; da quel momento la **fonte di verità è il DB** (la UI può divergere dal seeder, che resta solo bootstrap/reset).
- **Set "bloccato" protetto.** Un sottoinsieme di permessi è **non modificabile dalla UI** perché vincolato da privacy/legge/sicurezza o strutturale. La UI li mostra in sola lettura. Elenco bloccato (V1):
  - `garanzie.ricambio.view` / `garanzie.ricambio.manage` → **mai** concedibili a `Tenant` o `Tecnico` (🔗 ADR-004).
  - `utenti.impersonate` → solo `Developer`/`Superadmin`.
  - `system.logs.view` → solo `Developer`.
  - `billing.manage_global`, `billing.lockout`, `tenants.view_all`, `tenants.provision` → solo `Developer`/`Superadmin`.
  - `roles.manage` → solo `Developer`/`Superadmin` (chi gestisce la matrice non può auto-delegarla).
- **La UI tocca solo il "cosa" (permesso), mai il "su quali righe" (scope).** Lo scope (isolamento `tenant_id`, sotto-albero Responsabile, unione Tecnico) resta nel codice (Global Scope/Policy) e **non** è configurabile (🔗 ADR-001/006/007).

**Modello dati.** Nessuna tabella nuova: si usano le tabelle spatie. Il set bloccato è una **costante applicativa** (config/codice), non un dato editabile. Ogni modifica alla matrice va loggata in `activity_log` (chi/cosa/quando).

**Conseguenze.**
- Flessibilità operativa senza deploy; default sensati out-of-the-box.
- Le guard del set bloccato vanno testate (test negativi: "il Superadmin non può concedere `garanzie.ricambio.view` al Tenant via UI").
- Aggiungere un nuovo permesso resta un'operazione di codice (catalogo §4 + seeder); la UI gestisce l'assegnazione, non la creazione di permessi.
- Nuovo permesso `roles.manage` da aggiungere al catalogo e alla matrice.

---

**ADR-018 — Nessun ruolo bypassa il Global Scope: Superadmin/Developer tenant-bound, accesso cross-tenant solo via impersonazione**

*Stato: Accettata (29 Giu 2026) — supera il punto di ADR-001 secondo cui Superadmin/Developer bypassano lo scope.*

**Contesto.** ADR-001 prevedeva che Superadmin (EasyLab) e Developer **bypassassero** il Global Scope, vedendo i dati di *tutti* i tenant in un'unica vista (motivazione: dashboard globali/MRR). All'atto pratico questo significa che il fornitore vede di default i dati dei clienti — debole sul piano GDPR (minimizzazione/necessità) e fonte di confusione UX (Enti di clienti diversi mescolati nella stessa schermata). Chiarimento del modello di business: EasyLab **non possiede** gli Enti dei clienti; ogni Ente è un tenant a sé col proprio Admin.

**Decisione.**
- **Nessun ruolo bypassa il Global Scope.** Le schermate operative del **Superadmin** e del **Developer** si comportano **esattamente come quelle di un Admin**: vedono solo i dati del proprio tenant (`tenant_id`). Anche Superadmin e Developer hanno un **proprio Ente**.
- **Accesso cross-tenant = solo impersonazione.** Per vedere/operare i dati di un altro utente, Superadmin/Developer **impersonano** (lab404). Il Developer resta l'unico account **non impersonabile** (🔗 [[impersonation-only-developer-protected]]) e con tutti i permessi.
- **Scoping fail-closed.** Un utente **autenticato senza `tenant_id`** non vede **nulla** (prima vedeva tutto). Contesto senza utente (console/seeder/job) resta non scopato. Questo chiude anche il buco del **Tecnico** (`tenant_id` null): vedrà nulla finché S4 non implementa l'accesso per unione portafoglio∪assegnazione (🔗 ADR-007).
- **Dashboard globali/MRR (S6).** Le viste aggregate di piattaforma (clienti totali, MRR, ecc.) usano query **esplicitamente non-scopate** (`withoutGlobalScopes`) gate da permessi di piattaforma — **non** un bypass di sessione. Idem per il selettore utenti dell'impersonazione.

**Alternative scartate.**
- *Mantenere il bypass di sessione (ADR-001 originale):* più semplice ma espone i dati clienti al fornitore di default e mescola i tenant in UI.
- *Selettore di tenant per il Superadmin (scoping a un Ente scelto senza impersonare):* comodo ma duplica la logica dell'impersonazione e aggiunge un secondo percorso di accesso cross-tenant da proteggere. L'impersonazione (già presente, già loggata) copre il bisogno.

**Conseguenze.**
- `TENANT_SCOPE_BYPASS_ROLES` rimosso; `CurrentTenant`/`TenantScope` diventano fail-closed; `BelongsToTenant` timbra `tenant_id` solo quando un tenant è risolvibile.
- Superadmin/Developer richiedono un proprio Ente al setup (provisioning).
- Le suite di isolamento aggiornano i casi "Developer/Superadmin vedono tutto" → ora scoped; aggiunti casi fail-closed.
- Le dashboard globali di S6 vanno scritte con query non-scopate esplicite.
