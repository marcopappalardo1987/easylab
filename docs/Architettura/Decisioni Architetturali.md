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

*Stato: Accettata — **superata in parte da ADR-019** (3 Ago 2026): la garanzia a ore **non esiste**, e con essa spariscono `tipo_scadenza`, `soglia_ore`, `data_scadenza_prevista` e le letture contaore. Restano validi il motore sdoppiato macchina/ricambio, il campo unico `data_scadenza_effettiva` e la privacy delle garanzie ricambio. **Integrata da ADR-020**: la garanzia del ricambio pesa sul semaforo dello strumento.*

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

*Stato: Accettata — **estesa da ADR-020** (3 Ago 2026): la fonte "garanzie" del calcolo comprende anche le garanzie dei **ricambi** montati sullo strumento, con la stessa soglia di 30 giorni. **Estesa da ADR-024**: il motore non restituisce più solo uno stato ma una **diagnosi** (stato + motivi), mostrata nel tab Panoramica.*

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

*Stato: Accettata — **raffinata da ADR-022** (3 Ago 2026): la chiave di autocomplete passa dal `codice` al **nome** (codice nullable) e il punto d'ingresso è il form intervento (checkbox "Ricambio effettuato" + righe ripetitore con garanzia obbligatoria). Il meccanismo collega-o-crea e la ricerca incrociata restano invariati.*

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

*Stato: Accettata — l'elenco dei tipi di intervento, qui lasciato aperto ("es. `tipo = taratura`"), è **fissato da ADR-021** (3 Ago 2026). Il valore è oggi `taratura_e_certificazione`: nel linguaggio del cliente taratura e certificazione sono **una voce sola**, con lo stesso trattamento documentale.*

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
  - `garanzie.ricambio.view` / `garanzie.ricambio.manage` → **mai** concedibili al `Tenant` (🔗 ADR-004). *Corretto l'8 Ago 2026 da ADR-027: questa riga diceva «a `Tenant` o `Tecnico`», ma ADR-004 nomina solo il Tenant — e il Tecnico è personale EasyLab. Il divieto al Tecnico era un'estensione mai decisa, poi congelata da un test.*
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

---

## Briefing cliente del 3 Agosto 2026 → ADR-019 ÷ ADR-024

*Le sei decisioni che seguono nascono dallo stesso incontro col cliente destinatario della webapp. Sono raggruppate qui perché si leggono insieme: due riguardano le garanzie (una toglie, una estende), una la nomenclatura degli interventi, una il punto d'ingresso dei ricambi, una la forma della relazione coi fornitori e l'ultima una nuova vista di sintesi. Le ultime due sono arrivate a integrazione, poche ore dopo le prime quattro.*

---

**ADR-019 — Garanzie solo a data: la garanzia "a ore" è eliminata**

*Stato: Accettata (3 Ago 2026) — **supera ADR-004** nella parte "garanzie a ore" e ne rimuove l'infrastruttura di supporto.*

**Contesto.** ADR-004 nasce da un requisito raccolto in fase di scoping: "garanzia legata alle ore di utilizzo del macchinario". Il briefing del 3 Agosto 2026 chiarisce che si trattava di un **fraintendimento**: nel dominio del cliente la garanzia a ore **non esiste**. Tutte le garanzie — macchina e ricambio — scadono a data.

**Decisione.**
- La garanzia ha **una sola forma**: `data_inizio` + `durata_mesi` → `data_scadenza_effettiva`. Sparisce la distinzione `tipo_scadenza`.
- Si eliminano: enum `TipoScadenzaGaranzia` e colonne `tipo_scadenza`, `soglia_ore`, `data_scadenza_prevista` su `garanzie`.
- Si elimina **anche il contaore**: tabella `letture_contaore`, modello, UI di registrazione, permessi `letture_contaore.*`. Esisteva unicamente come input alternativo per la data prevista delle garanzie a ore (ADR-004, metodo 2): senza quel consumatore raccoglierebbe un dato che nessun motore legge.
- Cade con esse la voce V1.1 "estrapolazione automatica del ritmo ore/giorno".

**Cosa di ADR-004 sopravvive.** Il **motore sdoppiato** (garanzia macchina + garanzia ricambio), il campo unico **`data_scadenza_effettiva`** come sola grandezza che pilota semaforo e notifiche, e la **privacy delle garanzie ricambio** verso il Tenant.

**Alternative scartate.**
- *Tenere il contaore come dato operativo autonomo (usura, pianificazione):* plausibile in astratto, ma il cliente non l'ha chiesto e nessun motore lo consumerebbe. Si reintroduce con un ADR proprio se e quando nascerà il bisogno.
- *Congelare le colonne invece di rimuoverle (dead code difensivo):* lascia campi che la UI non scrive più e che il prossimo lettore deve interpretare; ricrearli, se mai servisse, è una migration additiva.

**Conseguenze.**
- `Garanzia::normalizzaScadenza()` si riduce a un ramo solo: sparisce la classe di errore "garanzia a ore salvata senza data prevista".
- Migration **distruttiva** (drop di 3 colonne + 1 tabella): le righe oggi `tipo_scadenza = ore` hanno `durata_mesi` NULL e vanno **convertite prima** del drop, altrimenti la normalizzazione esplode al primo salvataggio. Ordine obbligato: backfill → drop.
- **Il backfill sposta qualche scadenza, e va bene così.** Una data arbitraria non è esprimibile come "N mesi esatti da `data_inizio`": si sceglie l'intero N più vicino. L'alternativa — scrivere `durata_mesi` lasciando intatta `data_scadenza_effettiva` — lascerebbe in tabella un valore che il modello non sa più riprodurre e che cambierebbe da solo al primo salvataggio: uno scarto noto e misurato adesso è preferibile a una divergenza silenziosa domani.
- **`durata_mesi >= 1` va imposta nel model, non solo nel form.** Esistevano garanzie "a ore" la cui data prevista precedeva `data_inizio` (il vecchio modello non legava le due), e la conversione le riduceva a durata 0 — righe che il `min:1` del form non avrebbe mai permesso. Il `min:1` copre l'utente; seeder, import e migration scrivono senza passare di lì.
- Documenti da allineare: ERD §5.3/§6.1/§12/§13, Schema Ruoli §4.3 + matrice §5, Elenco Funzionalità §3, Wireframe (vista tecnico mobile), Roadmap S3.

**Emendamento del 9 Agosto 2026 (S4 blocco 3, attuando ADR-022): la garanzia si può esprimere anche come DATA.**

⚠️ **Questo emendamento non riapre nulla.** La garanzia *a ore* resta eliminata — `tipo_scadenza`, `soglia_ore`, `data_scadenza_prevista` e le letture contaore restano spariti — e la garanzia continua a scadere **solo a data**. Cambia la *forma di input*, non la sostanza.

**Il problema, emerso implementando.** ADR-022 vuole che nel form intervento l'operatore scriva «scadenza garanzia del pezzo»: una **data**, quella che il fornitore ha fissato. Ma la forma unica di ADR-019 (`data_inizio + durata_mesi`) non sa rappresentare una data arbitraria: convertirla nell'intero di mesi più vicino la sposta di giorni. È lo stesso scarto già **misurato** dal backfill di ADR-019 (3176 righe invariate, 1855 spostate di 1-15 giorni) — là accettato consapevolmente, una volta sola, su righe storiche; qui sarebbe diventato sistematico su ogni pezzo registrato da un tecnico, e su una grandezza che è **contrattuale** e che accende l'arancione a 30 giorni. Uno scarto di 15 giorni è metà della soglia.

**Decisione.** La garanzia ha **due forme di input e una sola di output**:

| Forma | Input | Chi la usa |
|---|---|---|
| A durata (ADR-019) | `data_inizio` + `durata_mesi` | garanzia macchina, form garanzia |
| A data (ADR-022) | `data_inizio` + `data_scadenza_dichiarata` | garanzia del pezzo montato, form intervento |

- Vincolo: **esattamente una delle due**, imposto in `Garanzia::normalizzaScadenza()`. Il NOT NULL su `durata_mesi` non è indebolito, è **spostato di livello** — dalla colonna alla coppia.
- `data_scadenza_effettiva` resta **derivata al 100%** e resta l'unico campo che pilota semaforo e notifiche: nessun consumatore (semaforo, scadenzario, elenco, scope) sa che le forme sono due.

**Alternative scartate.**
- *Convertire la data in mesi accettando lo scarto:* falsifica in silenzio un dato che l'utente ha digitato e che il fornitore ha fissato. Sarebbe stato accettabile solo mostrando in UI la data realmente salvata — cioè ammettendo il problema invece di risolverlo.
- *Rendere `data_scadenza_effettiva` scrivibile:* la strada apparentemente più corta, e la peggiore. Quel campo è inattaccabile per **due** ragioni, non una: è fuori da `$fillable` **e** l'hook lo riscrive incondizionatamente a ogni salvataggio. Un ramo che lo leggesse come input avrebbe distrutto la seconda, e nessun docblock l'avrebbe ricostruita. Con una colonna di input dedicata la falla non si chiude con una guardia: **non si apre**.
- *Chiedere i mesi anche per il pezzo:* nessuna modifica al dominio, ma infedele al gesto reale — il fornitore dà una data, non una durata.

**Conseguenze.**
- Migration additiva `add_scadenza_dichiarata_to_garanzie_table`; `durata_mesi` torna nullable. ⚠️ La colonna **non** si chiama `data_scadenza_prevista`: quel nome lo ricrea il `down()` della migration di ADR-019, e un rollback esploderebbe con "column already exists".
- `data_scadenza_dichiarata` è **fuori da `$fillable`** come l'effettiva: si scrive solo da `fissaScadenzaDichiarata()` / `fissaDurata()`, che azzerano sempre l'altro lato — così il vincolo non è violabile da chi passa dai metodi di dominio, e un payload che portasse entrambe non è nemmeno costruibile.
- ERD §6.1 e §13 aggiornati. Il form garanzia macchina, `scopeEntroSoglia`, `isScaduta`, `Semaforo` e `GaranziaDepartmentScope` non cambiano di una riga.

---

**ADR-020 — La garanzia del ricambio concorre al semaforo dello strumento su cui è montato**

*Stato: Accettata (3 Ago 2026) — **estende ADR-005**; convive con il vincolo di privacy di ADR-004. **Attuata il 9 Ago 2026** (S4 blocco 4). Il bypass del privacy scope è scritto in UN punto solo, `Garanzia::scopeDeiPezziMontati()`; a non far trapelare il dettaglio non è una guardia ma la **forma della query** — i chiamanti selezionano id e scadenza, e il nome del pezzo non entra nel result set. La resa neutra delle etichette è andata oltre la lettera di questo ADR: oltre alla colonna "Prossima scadenza" riguarda anche il motivo del tab Panoramica (🔗 ADR-024), che nel frattempo era nato.*

**Contesto.** ADR-004 modella la garanzia del singolo pezzo (`soggetto = ricambio`, agganciata a `ricambio_utilizzo`) ma non dice se pesa sul semaforo; l'implementazione S3 alimenta il calcolo con le sole garanzie `macchina`. Il briefing del 3 Agosto 2026 lo chiarisce: **anche i ricambi condizionano lo stato dello strumento**, con la stessa scala della garanzia macchina — dentro i termini → verde; scadenza entro un mese → arancione; scaduta → arancione.

**Decisione.**
- La **fonte garanzie** del calcolo semaforo diventa l'unione di: garanzie `macchina` dello strumento **∪** garanzie `ricambio` dei pezzi montati su quello strumento (doppio salto `garanzie → ricambio_utilizzo → strumento_id`).
- "Manca 1 mese" ≡ la soglia "imminente" già in uso, **30 giorni** (`config/easylab.php`, `Semaforo::giorniImminente()`). Il cliente non chiede una seconda soglia dedicata ai ricambi.
- **Il pallino è un aggregato, il dettaglio no.** Il Tenant continua a **non** vedere le righe garanzia-ricambio (ADR-004, set 🔒 `garanzie.ricambio.*`), ma **vede** l'arancione che ne deriva. Ogni etichetta che nomini la fonte — colonna "Prossima scadenza" in testa a tutte — degrada a dicitura neutra per chi non ha `garanzie.ricambio.view`.

**Alternative scartate.**
- *Nascondere al Tenant anche l'effetto (pallino verde per lui, arancione per EasyLab):* produrrebbe due verità sullo stesso strumento a seconda di chi guarda, minando la fiducia nel semaforo — che è l'oggetto centrale del prodotto. Scartata.
- *Soglia dedicata ai ricambi:* nessuna evidenza che serva, e una seconda costante è una seconda cosa che può divergere dalla prima.

**Conseguenze.**
- `GaranziaRicambioPrivacyScope` è un **global scope**: la query che alimenta il semaforo deve leggere le garanzie ricambio **esplicitamente senza quello scope** (stesso idioma delle dashboard globali di ADR-018). Senza questa accortezza, per il Tenant l'arancione da ricambio non si accenderebbe mai — un bug invisibile proprio a chi lo subisce.
- Il calcolo bulk dell'elenco strumenti (una query per pagina, S3 punto 4) va esteso al doppio salto senza tornare N+1; idem filtro e ordinamento per stato.
- Salda il debito S3 punto 7 lettera **(c)** (colonna dettaglio filtrata per permesso, pallino no) e impone di sciogliere la lettera **(b)** (livello 2 dello scope sulle righe ricambio, che passa dallo stesso doppio salto).
- 🧪 Test negativo obbligatorio: *un Tenant vede arancione uno strumento la cui unica scadenza è la garanzia di un ricambio, e non vede da nessuna parte il nome del pezzo.*

**Precisazione del 9 Ago 2026 (in fase di attuazione): «montati» si legge alla lettera, e a dirlo è `ricambio_utilizzo.data`.** Quando questo ADR è stato scritto la colonna era NOT NULL e la fonte si poteva definire col solo doppio salto; renderla nullable lo stesso giorno (la data di montaggio segue la **chiusura** dell'intervento) ha creato una terza categoria che prima non esisteva — il pezzo **registrato ma non ancora installato**. La sua garanzia **non** concorre al semaforo: un pezzo che non è sulla macchina non ne descrive lo stato, e senza il filtro un intervento pianificato per l'anno prossimo accenderebbe l'arancione oggi. Emersa provando il flusso reale in browser, non dai test: la fonte era fedele alla lettera dell'ADR di allora.

---

**ADR-021 — Tipologie di intervento: elenco fissato dal cliente**

*Stato: Accettata (3 Ago 2026) — fissa la lista lasciata "estendibile" da ADR-009 / ERD §5.2.*

**Contesto.** L'enum `TipoIntervento` era stato popolato con voci verosimili (`manutenzione`, `taratura`, `ispezione`, `riparazione`, `altro`) in assenza della nomenclatura reale. Il briefing del 3 Agosto 2026 la fornisce: è organizzata per **regime contrattuale di manutenzione**, non per natura tecnica del lavoro.

**Decisione.** Le tipologie sono:

| Valore | Etichetta UI |
|---|---|
| `manutenzione_ordinaria` | Manutenzione ordinaria |
| `manutenzione_straordinaria` | Manutenzione straordinaria |
| `manutenzione_full_risk` | Manutenzione full risk |
| `taratura_e_certificazione` | Taratura e certificazione |
| `altro` | Altro |

- **Eliminate `ispezione` e `riparazione`**: non esistono nel linguaggio del cliente; il lavoro che vi ricadeva è manutenzione ordinaria o straordinaria.
- **`manutenzione` generica sparisce**, sostituita dai tre regimi contrattuali.
- **"Taratura e certificazione" è UNA voce**, non due. ⚠️ La prima stesura di questo ADR l'aveva spezzata in `taratura` + `certificazione`, leggendo la congiunzione dell'elenco come separatore: **errore di lettura**, corretto dal cliente il 4 Ago 2026. Nel suo linguaggio le due cose viaggiano insieme — la taratura si chiude con il certificato. È la voce con valenza documentale di ADR-009: certificato allegato, scadenza che alimenta il semaforo, nessun motore separato.
- **`altro` resta** come voce di fallback (decisione presa contro l'ipotesi di lista chiusa a quattro): sul campo l'intervento non classificabile deve comunque essere registrabile, e `descrizione` porta il dettaglio.

**Alternative scartate.**
- *Tabella `tipi_intervento` configurabile per tenant:* la nomenclatura è di dominio, non di cliente; una tabella renderebbe non tipizzabile ciò che oggi è un enum PHP con `match` esaustivi.
- *Lista chiusa a cinque senza `altro`:* più pulita per le statistiche, ma produce l'intervento non registrabile. Preferita la flessibilità, accettando che `altro` vada sorvegliata (se cresce, manca una voce).

**Conseguenze.**
- Da aggiornare: enum, seeder demo, test, etichette UI, e una **migration di rimappatura dati** — `manutenzione` e `ispezione` → `manutenzione_ordinaria`, `riparazione` → `manutenzione_straordinaria`, `taratura` → `taratura_e_certificazione`.
- **Le correzioni di dati vanno sempre in avanti.** La fusione taratura+certificazione è arrivata quando la prima migration era già applicata al DB di sviluppo: si aggiunge una seconda migration, non si riscrive la prima — riscriverla la renderebbe una bugia (girata con un contenuto, versionata con un altro) e imporrebbe un rollback su dati reali.
- `interventi.tipo` è una `string` senza CHECK (ERD §5.2), quindi la rimappatura è un `UPDATE` — ma va eseguita **prima** che l'enum PHP smetta di conoscere i vecchi valori, o ogni `from()` su una riga storica lancerà `ValueError`.
- Le etichette non sono più derivabili dal valore (`manutenzione_full_risk` → "Manutenzione full risk"): serve un `label()` sull'enum come **unica** fonte, altrimenti la stessa stringa viene riscritta in ogni vista.

---

**ADR-022 — I ricambi si registrano dall'intervento, con nome libero e garanzia per riga**

*Stato: Accettata (3 Ago 2026) — **raffina ADR-008**: cambia la chiave di ricerca del catalogo e il punto d'ingresso. **Attuata il 9 Ago 2026** (S4 blocco 3). Implementando è emerso che «scadenza garanzia per riga» non era rappresentabile dal dominio di ADR-019 senza spostare la data di giorni → **emendamento ad ADR-019** (due forme di input, una sola di output), scritto in coda a quell'ADR. Il nodo di permessi lasciato aperto qui era già stato sciolto da ADR-027.*

**Contesto.** ADR-008 fissa il catalogo ricambi incrementale con autocomplete **per codice**. Il briefing del 3 Agosto 2026 descrive il gesto reale dell'operatore: nel form di un nuovo intervento c'è una **checkbox "Ricambio effettuato"**; spuntandola compare una **riga ripetitore** dove si scrivono a mano il **nome** del ricambio e la **scadenza della sua garanzia**. Due tensioni con ADR-008: (a) il cliente nomina il pezzo, non lo codifica; (b) l'inserimento parte dall'intervento, non dal tab Ricambi.

**Decisione.**
- **Punto d'ingresso primario = il form intervento.** Checkbox `ricambio_effettuato` (stato di UI, **non** persistito: la verità è "esistono righe ricambio per questo intervento") → repeater di N righe `{nome, data_scadenza_garanzia}` con aggiungi/rimuovi.
- Ogni riga, al salvataggio, produce in **una transazione**: la voce di catalogo `ricambi` (collegata se il nome corrisponde a una esistente del tenant, creata al volo altrimenti — è il collega-o-crea di ADR-008, con il **nome** come chiave al posto del codice), una riga `ricambio_utilizzo` (strumento + ricambio + intervento) e una `garanzia` con `soggetto = ricambio` agganciata a quella riga.
- **`ricambi.codice` diventa nullable**: l'autocomplete lavora sul nome normalizzato; il codice resta per chi lo conosce e per il merge doppioni. La chiave pratica del catalogo passa a `(tenant_id, nome normalizzato)`.
- **La garanzia della riga è obbligatoria**: è il dato che il cliente ha chiesto e che alimenta il semaforo (ADR-020). Nessun ricambio senza scadenza garanzia.
- Le righe così create sono **le stesse** che il tab "Ricambi" mostra: il tab resta il luogo di lettura, ricerca incrociata e correzione, non il punto d'ingresso abituale.

**Alternative scartate.**
- *Tenere il codice obbligatorio come da ADR-008 originale:* fedele all'ADR ma infedele al gesto reale — durante l'intervento il codice spesso non è a portata di mano e si finirebbe per inventarlo, degradando proprio la ricerca incrociata che il codice doveva proteggere.
- *Testo libero senza catalogo:* form più semplice, ma perde la ricerca incrociata, cioè il requisito che ha generato ADR-008. Scartata.
- *Registrazione solo dal tab Ricambi:* meno codice, ma spezza in due passaggi un gesto unico.

**Conseguenze.**
- La **normalizzazione del nome** (trim, spazi multipli, maiuscole) diventa il punto delicato: i near-duplicati da refuso restano possibili, mitigati da autocomplete e merge doppioni (ADR-008, STRETCH S4).
- ✅ ~~⚠️ **Nodo di permessi da sciogliere in S4.**~~ **Sciolto da ADR-027** (8 Ago 2026, implementato l'8 Ago in S4 blocco 0). Il nodo era: il Tecnico ha `ricambio_utilizzo.create` ma **non** `garanzie.ricambio.manage` (set 🔒), quindi compilando la riga scriverebbe comunque una garanzia ricambio. Le tre vie ipotizzate — (a) creazione contestuale per conto del dominio, in un servizio che non richiede il permesso; (b) permesso allargato alla sola creazione contestuale; (c) garanzia completata da un Admin — **erano tutte aggiramenti di un divieto che non era mai stato deciso**: ADR-004 nomina il solo Tenant. Nessuna serve più; il Tecnico ha `garanzie.ricambio.view` e `.manage`, e ogni sua scrittura è tracciata (🔗 ADR-027).
- ADR-008 resta valido nella sostanza (catalogo incrementale, collega-o-crea, ricerca incrociata): cambiano chiave di ricerca e punto d'ingresso.

---

**ADR-023 — Fornitore: uno per macchinario, non molti**

*Stato: Accettata (3 Ago 2026) — **corregge l'ERD §7.3**, che modellava la relazione come pivot molti-a-molti.*

**Contesto.** L'ERD prevedeva `fornitore_strumento`, un pivot N-N scelto in S0 in assenza di indicazioni. Il briefing precisa: **ogni macchinario è associato a un fornitore**, quello da cui è stato acquistato — coerente con l'Elenco Funzionalità §2, che già diceva "l'anagrafica dei fornitori **da cui vengono acquistati**". L'anagrafica è popolata da ciascun Ente e scopata per tenant come ogni altra tabella di business: ognuno vede i propri fornitori.

**Decisione.**
- Relazione **1-N**: `strumenti.fornitore_id` FK → `fornitori.id`. Il pivot `fornitore_strumento` **non si crea**.
- **Obbligatorio nel form, nullable nello schema.** La colonna resta nullable perché gli strumenti già a sistema — e gli import CSV futuri — non hanno un fornitore, e una FK NOT NULL li renderebbe non salvabili; l'obbligo vive nella validazione del form. È scritto qui perché è la divergenza che il prossimo lettore scambia per una dimenticanza.
- `fornitori` è tabella di business a tutti gli effetti: `tenant_id` + `BelongsToTenant`, soft delete, coperta dal meta-test di tenancy (nessuna eccezione, 🔗 ADR-001/018).
- **Cancellazione protetta.** Un fornitore con strumenti associati non si elimina. E poiché `fornitori` usa soft delete, la scheda strumento deve mostrare il nome di un fornitore cestinato con un badge esplicito, non una cella vuota: un vuoto silenzioso si legge come "dato mai inserito".

**Alternative scartate.**
- *Mantenere il pivot N-N:* coprirebbe il caso "fornitore d'acquisto + fornitore d'assistenza", che però il cliente non ha posto. Un pivot per un rapporto 1-N costringe ogni lettura a una join e ogni scrittura a decidere quale delle righe sia "quella giusta". Se il caso emergerà, si aggiunge un secondo campo tipizzato (`fornitore_assistenza_id`) o si promuove a pivot con `ruolo`: entrambe migration additive.
- *`fornitore_id` NOT NULL:* vincolo più forte, ma blocca righe storiche e import — e un vincolo che costringe a inventare un valore non protegge nulla.

**Conseguenze.**
- ERD §5.1 (colonna + indice), §7.3 riscritta, §2 diagramma; Elenco Funzionalità §2.
- Il campo entra nel form strumento, nel tab Anagrafica e nel blocco di sintesi della Panoramica (🔗 ADR-024).
- **Filtro "Fornitore" nell'elenco strumenti**: conseguenza naturale (l'elenco ha già filtri Ente/stato/obsoleti), da valutare in S4.
- **`fornitori.view` concesso al ruolo Tenant** in sola lettura (approvato 3 Ago 2026): l'anagrafica è dell'Ente, e il cliente sa già da chi ha comprato la propria macchina. Modifica la matrice di S1 → serve un test sul nuovo default del seeder.
- 🧪 Il test di isolamento vale anche qui: un Ente non deve poter associare uno strumento al fornitore di un altro Ente — la whitelist del select e la validazione al save devono avere **una sola definizione**, come già fatto per l'assegnatario degli interventi.

---

**ADR-024 — Tab Panoramica: il semaforo spiega sé stesso**

*Stato: Accettata (3 Ago 2026) — estende ADR-005; nuova prima scheda della vista strumento.*

**Contesto.** Fra S3 e S4 le cause del semaforo si sono sparpagliate: un arancione può nascere da un intervento scaduto (tab Interventi), da una garanzia macchina (tab Garanzie), dalla garanzia di un ricambio (tab Ricambi + Garanzie, 🔗 ADR-020) o da una forzatura manuale (badge in testa alla scheda). Il cliente chiede di vedere **a colpo d'occhio il motivo del semaforo acceso**, più qualche dato di sintesi utile — "prossimo intervento", qualche statistica.

**Decisione.**
- Nuovo tab **"Panoramica"**, **primo e di default** della scheda strumento.
- Il motore semaforo smette di restituire solo uno stato e restituisce una **diagnosi**: stato **+ elenco dei motivi** che lo determinano, ciascuno con la propria scadenza e il collegamento alla riga che lo genera. `Semaforo::calcola()` resta come sottile involucro sopra la diagnosi, così i test puri già scritti restano validi.
- **La Panoramica non calcola nulla di suo.** Riusa le regole già uniche nel progetto — `Intervento::isScaduto()`/`scopeScadute()`, `Garanzia::scopeEntroSoglia()`, `Strumento::isObsoleto()`. Un pannello che ricalcolasse "scaduto" a modo proprio diventerebbe una **terza verità** dopo il semaforo e la lista attività: esattamente ciò che ADR-005 esiste per evitare.
- **La forzatura non nasconde.** Con `forced_state` valorizzato la Panoramica mostra **entrambi**: lo stato forzato che vince (con chi/quando/perché) **e** lo stato calcolato coi suoi motivi. È il principio di ADR-005 finalmente reso visibile invece che solo dichiarato.
- **Privacy** (🔗 ADR-004/020). Un motivo che nasce da una garanzia ricambio si mostra, a chi non ha `garanzie.ricambio.view`, in forma **neutra**: "Garanzia di un componente in scadenza il gg/mm/aaaa", senza nome del pezzo e senza link alla riga. È la stessa regola della colonna "Prossima scadenza", ma qui il testo è molto più esposto — il test negativo è obbligatorio, non consigliato.
- **Ogni blocco è gated dal proprio permesso.** La Panoramica compone dati di aree diverse e non deve diventare la scorciatoia che aggira i `@can` degli altri tab.

**Contenuto V1**, in ordine di importanza:
1. **Stato e motivi** — pallino grande; elenco dei motivi con data e link alla riga d'origine.
2. **Prossimo intervento** — data, tipo, tecnico assegnato. "Nessuno pianificato" è a sua volta un'informazione, e va scritto.
3. **Ultimo intervento eseguito** — data e tipo.
4. **Garanzie** — garanzia macchina con scadenza e stato; le garanzie ricambio in **aggregato** ("N pezzi coperti") per chi non ha il permesso di vederle nel dettaglio.
5. **Sintesi anagrafica** — fornitore (🔗 ADR-023), ubicazione corrente, data installazione, età e stato di obsolescenza.
6. **Statistiche leggere** — interventi negli ultimi 12 mesi, scaduti-non-fatti aperti, ricambi montati, documenti allegati.

**Alternative scartate.**
- *Pannello sul pallino invece di un tab:* è il "badge cliccabile" già rimandato in S3. Su mobile un pannello galleggiante con sei blocchi è inservibile, e il cliente ha chiesto esplicitamente un tab.
- *Persistere stato e motivi in colonna:* renderebbe la Panoramica istantanea, ma introduce un ricalcolo da tenere in sincronia a ogni evento. Rimandato insieme all'ipotesi di materializzazione già annotata come debito in S3 (paginazione a OFFSET): se arriverà, arriverà per la dashboard S6 — non per un tab che carica un solo strumento.
- *Una sola grande dashboard al posto del tab:* risponde a una domanda diversa ("come stanno le mie macchine") e c'è già in S6. La Panoramica risponde a "perché **questa** macchina è arancione".

**Conseguenze.**
- Il refactor di `Semaforo` va fatto **prima** di aggiungervi la fonte "garanzie ricambio" (🔗 ADR-020), o la stessa classe viene toccata due volte con due criteri diversi.
- La Panoramica carica **un solo** strumento: nessun rischio N+1 di lista. Ma i suoi contatori **non vanno riusati in una lista** senza ripensarli — è la trappola classica di questi pannelli, e qui l'elenco strumenti ha già un calcolo bulk fatto apposta.
- Il tab diventa la landing della scheda: i test che oggi asseriscono il contenuto immediatamente visibile vanno aggiornati **consapevolmente** (la lista interventi non è più il primo pannello).
- I motivi sono un elenco tipizzato, non stringhe: servono per il testo, per il link e — in S5 — per il corpo dell'"email del futuro", che oggi dovrebbe ricostruirseli da capo.

---

**ADR-025 — Infrastruttura: Laravel Cloud per l'applicazione, Backblaze B2 per i documenti**

*Stato: Accettata (5 Ago 2026) — **supera l'assunzione tecnica "Forge + DigitalOcean"** della roadmap §0 e del Tech Stack §6, che era un default proposto e mai eseguito.*

**Contesto.** Roadmap e Tech Stack davano per scontati **Laravel Forge su droplet DigitalOcean** per hosting e **DigitalOcean Spaces** per i documenti. Erano assunzioni del giorno 1, dichiarate "default proposti — modificabili", e il provisioning non è mai stato fatto: nessuna riga di codice usa `Storage`, `FILESYSTEM_DISK` è ancora `local` e il driver S3 non è installato. Quando è arrivato il momento di scegliere davvero, la decisione è cambiata.

**Decisione.**
- **Applicazione, database e Redis su Laravel Cloud.** Piattaforma gestita: deploy da Git, Postgres e Valkey/Redis gestiti, worker di coda e scheduler inclusi. Sostituisce Forge + droplet.
- **Documenti su Backblaze B2**, bucket **privato** in **regione UE** (Amsterdam, `eu-central-003`), via il driver S3 standard di Laravel puntato all'endpoint B2.
- **Credenziali: Application Key limitata al singolo bucket**, mai la master key. Se finisce in un log o in un `.env` sbagliato il danno resta confinato a quel bucket.

**Alternative scartate.**
- *Laravel Object Storage* (l'object storage incluso in Laravel Cloud, Cloudflare R2 sotto il cofano): sarebbe **zero sub-responsabili nuovi**, perché Cloudflare resterebbe sub-responsabile *di Laravel* e coperto dal loro DPA. Ha però un ricarico di circa 3× sullo storage rispetto a B2 diretto ($0,02 contro ~$0,006 per GB-mese). **Scelta consapevole del prezzo sopra la semplicità contrattuale**, presa dopo aver messo a confronto le due cose: la differenza è nell'ordine di una decina di euro l'anno ai volumi previsti, contro un DPA in più da firmare e una voce in più nel registro.
- *DigitalOcean Spaces:* aveva senso finché l'hosting era su DigitalOcean — un fornitore già presente. Con Laravel Cloud quell'argomento decade, e restano solo i 5 $/mese di minimo fisso, pagati anche con 1 GB dentro.
- *Disco locale del server:* costo zero, ma incompatibile con una piattaforma che scala e ricrea i container, e complica i backup.

**Conseguenze.**
- ⚠️ **Un sub-responsabile in più.** Backblaze entra nel registro dei trattamenti accanto a Laravel Cloud, e serve il **DPA firmato prima che i documenti dei clienti arrivino nel bucket** (art. 28). È il costo accettato di questa scelta e non va scoperto al momento del go-live: un account personale gratuito va bene per provare, non per dati veri.
- Va installato `league/flysystem-aws-s3-v3` (oggi assente) e configurato il disco `s3` con `AWS_ENDPOINT` verso B2. **Nessuna riga di applicazione cambia**: il provider resta un dettaglio di `.env`.
- **La reversibilità dipende da come si servono i file**, e la scelta è ancora aperta. ADR-009 dice "URL firmate" senza specificare quale: una **URL pre-firmata S3** è di fatto un bearer token — chi ce l'ha legge il file fino alla scadenza, con la Policy fuori dal giro — mentre una **rotta firmata Laravel che fa da tramite** ricontrolla l'autorizzazione a ogni richiesta e funziona identica su qualunque disco. La seconda è più coerente con ADR-003 ("mai dati senza auth") e ADR-018 (fail-closed), e rende il provider davvero sostituibile. **Da decidere in S4**, quando nasce la feature documenti.
- L'egress di B2 è gratuito fino a **3× lo storage medio mensile**, poi si paga: ampiamente sufficiente per PDF serviti a utenti autenticati, ma è la clausola da conoscere prima di firmare.
- Documenti da allineare: Tech Stack §6, `Setup Repository e Ambienti.md` (ambienti, variabili, provisioning, pipeline di deploy), **`Privacy GDPR e Registro Trattamenti.md`** (elenco sub-responsabili — è un documento di compliance, non di sviluppo), ERD §8.1, roadmap §0/S1/S4, wireframe §2.
- Cambia anche la **storia di backup e restore** (`[CORE]` in S7): il database non è più un Postgres su droplet da gestire a mano ma un servizio gestito, con proprie procedure da verificare.

**Nota operativa — setup B2 verificato l'8 Ago 2026.** Connessione provata con successo su percorsi della forma reale dell'ERD §8.1 (`3/documenti/…`, `17/documenti/…`: scrittura, esistenza, rilettura, dimensione, elenco, cancellazione, e cartelle per tenant indipendenti). Le tre cose su cui si perde tempo, in ordine di quanto sono costate:

1. **Il prefisso della chiave.** Creando una Application Key, Backblaze offre un campo *"file name prefix"* che limita la chiave ai file il cui nome inizia con quella stringa. Va lasciato **vuoto**: i percorsi dell'applicazione iniziano con il `tenant_id`, quindi qualunque prefisso li blocca tutti. La restrizione utile è quella **al bucket**, che va invece tenuta.
2. **Prefisso e restrizione vivono sulla chiave, non sul bucket.** Ricreare il bucket non le tocca — e una chiave vincolata al bucket vecchio risponde `AccessDenied — not entitled` su quello nuovo.
3. **`AccessDenied — not entitled` è un messaggio unico per violazioni diverse**: capability mancante, percorso fuori prefisso, bucket non autorizzato. Non distingue, quindi da solo porta fuori strada. Peggio: lo strato S3 di B2 **non applica le restrizioni in modo uniforme** — `PutObject` e `GetObject` fuori prefisso possono passare mentre `HeadObject` e `ListObjectsV2` falliscono, così una chiave troppo stretta sembra funzionare finché non si tocca `exists()` o `size()`.

Dettagli di configurazione: `AWS_ENDPOINT` vuole lo schema **`https://`** (il pannello mostra l'host nudo, e l'SDK rifiuta l'URI senza schema); il keyID è di **25 caratteri** e il secret di **31** — lunghezze diverse significano incollatura troncata; `AWS_USE_PATH_STYLE_ENDPOINT=false`. Le API native di Backblaze rispondono su realm diversi per regione e **non** sono utilizzabili come diagnostica generica: conviene verificare direttamente con l'API S3.

---

**ADR-026 — Download dei documenti: rotta firmata Laravel, non URL pre-firmata dell'object store**

*Stato: Accettata (8 Ago 2026) — scioglie il "da decidere in S4" lasciato aperto da ADR-025 e precisa cosa significa "URL firmate" in ADR-009.*

**Contesto.** ADR-009 dice che i documenti si scaricano con "URL firmate", senza specificare quale delle due cose molto diverse che quella espressione può indicare. La domanda è diventata concreta con ADR-025, perché la risposta decide anche quanto siamo legati al provider di storage.

**Le due strade.**
1. **URL pre-firmata S3** (`Storage::temporaryUrl()`): il browser scarica direttamente da B2. Nessuna banda a carico dell'app. Ma quell'URL è di fatto un **bearer token**: chiunque l'abbia legge il file fino alla scadenza, e dopo l'emissione la Policy è fuori dal giro — non c'è modo di revocarla né di sapere chi l'ha usata.
2. **Rotta firmata Laravel che fa da tramite**: `signed` middleware + Policy, il file viene servito in streaming dall'applicazione.

**Decisione: la seconda.**
- L'**autorizzazione viene ricontrollata a ogni richiesta**, contro Policy, tenancy e sotto-albero del Responsabile. È l'unico modo perché ADR-003 ("mai dati senza auth") e ADR-018 (scoping fail-closed) valgano anche per i file, e non solo per le schermate che li elencano.
- **Funziona identica su qualunque disco**, `local` compreso: lo sviluppo non richiede credenziali B2 e il provider resta sostituibile con una riga di `.env`. Senza questo, la scelta di ADR-025 sarebbe un vincolo e non una preferenza.
- Rende possibile **tracciare i download** come qualunque altra azione sensibile (🔗 ADR-027), cosa che con una URL pre-firmata non è tecnicamente possibile.

**Alternative scartate.**
- *URL pre-firmata:* più veloce e a costo zero di banda, ma consegna un permesso che non si può più governare. Ha senso per contenuti pubblici ad alto traffico; qui i file sono certificati di taratura e report d'intervento, serviti a poche decine di utenti autenticati.
- *Ibrido (pre-firmata solo per i file "non sensibili"):* richiederebbe di stabilire e mantenere quale documento è sensibile — la stessa classificazione che ADR-003 ha già scartato per il QR pubblico.

**Conseguenze.**
- Il download costa banda dell'applicazione. Per PDF di manuali e certificati è irrilevante; se un domani ci finissero video o allegati molto pesanti, la decisione va rivista con numeri alla mano.
- Serve una rotta dedicata con `signed` + Policy sul modello `Documento`, e i test negativi delle aree rosse: utente di altro tenant → 404, Responsabile fuori sotto-albero → 404, URL scaduta → 403.
- `Storage::disk()` va indirizzato **esplicitamente**: `FILESYSTEM_DISK` resta `local` come default applicativo, così nessuna altra parte del sistema finisce sull'object store per errore.

---

**ADR-027 — Tracciabilità delle scritture di dominio: la traccia sostituisce il divieto**

*Stato: Accettata (8 Ago 2026) — **corregge ADR-016** su `garanzie.ricambio.*` e scioglie il nodo aperto di ADR-022.*

**Contesto.** ADR-022 aveva lasciato un nodo scomodo: il Tecnico registra il ricambio dal form intervento, ma quel gesto crea anche la garanzia del pezzo, e `garanzie.ricambio.manage` gli era negato dal set bloccato. Le tre vie d'uscita ipotizzate erano tutte aggiramenti.

**Il divieto non era mai stato deciso.** ADR-004 dice: «Le garanzie sui ricambi restano visibili solo a EasyLab/Admin, **mai al Tenant**». Nomina il Tenant, e il Tecnico *è* personale EasyLab. Il divieto al Tecnico compare per la prima volta nello Schema Ruoli §4.3 e in ADR-016, entrambi citando ADR-004 come fonte — ma quella fonte non lo dice. La citazione si è allargata oltre il suo contenuto, è entrata in `config/rbac.php` e nella matrice, e infine è stata congelata da un test. **Una volta che un test la difende, una svista è indistinguibile da una decisione**: è il motivo per cui vale la pena rileggere la fonte quando un vincolo costringe a contorsioni.

**Decisione.**
1. **Il Tecnico ottiene `garanzie.ricambio.view` e `.manage`.** È la persona che monta fisicamente il pezzo, quindi la fonte del dato sulla garanzia. Il set bloccato resta, ma il suo significato torna quello di ADR-004: **mai al Tenant**.
2. **Ogni scrittura di dominio è tracciata** — chi, cosa, quando — sul canale `audit` già esistente. Dove il rischio è che qualcuno faccia una modifica sbagliata o inopportuna, **la risposta è la traccia, non il divieto**: negare il permesso a chi deve fare il lavoro sposta il problema su un'altra persona senza eliminarlo, e produce dati inseriti da chi non li conosce.
3. **Attuazione per sprint**, non tutta insieme: garanzie (S4, sblocca ADR-022) → interventi e ricambi (S4) → spostamenti e installazione (S4/S5) → il resto quando serve. Il principio è deciso una volta; la copertura cresce a tappe.

**Perimetro.** La traccia riguarda le **scritture** (creazione, modifica, cancellazione) delle entità di dominio, non le letture. Fa eccezione l'accesso cross-tenant del Tecnico, già tracciato per conto suo (🔗 ADR-007), e il download dei documenti, che ADR-026 rende tracciabile.

**Alternative scartate.**
- *Lasciare il divieto e far completare la garanzia a un Admin:* due passaggi per un gesto unico, e il dato lo inserisce chi non ha visto il pezzo. È esattamente ciò che ADR-022 voleva evitare.
- *Permesso allargato solo alla "creazione contestuale":* una regola che vale in un punto dello schermo e non in un altro è difficile da spiegare e facile da aggirare.
- *Tracciare tutto e subito con `LogsActivity` su ogni modello:* copertura immediata, ma rende urgente la questione retention prima di averla decisa col legale, e allarga S4 senza necessità.

**Conseguenze.**
- Da aggiornare: `config/rbac.php` (Tecnico), matrice Schema Ruoli §5, ERD §10, la nota §4.3, l'elenco del set bloccato in ADR-016, e **il test `never grants spare-part warranties to Tenant or Tecnico`**, che va riscritto per il solo Tenant. È una modifica di test **consapevole**, non un adeguamento: congelava una regola sbagliata.
- ⚠️ **La retention dell'audit diventa più urgente.** Il registro dei trattamenti segna T6 con "retention definita (APERTO)". Tracciare ogni scrittura significa più dati personali **sui dipendenti**, conservati più a lungo: la decisione col legale va presa prima che il volume renda scomodo cambiare idea.
- **"Tracciato" non significa ancora "visibile".** La vista Audit è in S6: fino ad allora i dati si accumulano e si leggono solo dal database. Va detto a chi si aspetta di vederli subito.
- Il volume di `activity_log` cresce in modo non banale: va tenuto d'occhio e si intreccia con la retention sopra.
- ~~**Come tracciare**, da decidere in implementazione~~ → **deciso e attuato il 9 Ago 2026** (S4). Servono entrambi, come si era ipotizzato, con una regola netta a separarli: **un model usa O il trait O le chiamate `activity()` esplicite, mai entrambi** — altrimenti la vista Audit di S6 mostrerebbe due righe per un gesto solo.
  - Il trait `App\Models\Concerns\AuditsDomainWrites` (`LogsActivity` di spatie sul canale `audit`) copre le scritture CRUD dove basta sapere *cosa è cambiato*: applicato a `Garanzia`, `Ricambio`, `RicambioUtilizzo`. Il suo hook `attributiDerivatiTracciati()` aggiunge al set le colonne **derivate** che decidono qualcosa (`data_scadenza_effettiva`, `nome_normalizzato`): senza, l'audit registrerebbe l'input e non l'effetto.
  - Le `activity()` esplicite restano dove il **messaggio** vale più dell'elenco dei campi: `Strumento::forzaSemaforo()`/`rimuoviForzatura()` descrivono un ATTO, non un evento CRUD, ed è giusto che si distinguano a colpo d'occhio in una lista.
  - Descrizioni in forma **nome-primo** («Creazione garanzia», non «Garanzia creata»): l'italiano concorda il participio col genere, e un trait condiviso o inventa un campo `genere` su ogni model o prima o poi scrive «Intervento creata».
  - `dontLogEmptyChanges()` è indispensabile e non cosmesi: `logEmptyChanges` è `true` di **default** in activitylog v5, e l'hook `saving` di `Garanzia` riscrive la scadenza a ogni salvataggio — senza, ogni save innocuo lascerebbe una riga vuota.
  - ⚠️ **Copertura parziale, dichiarata**: 3 entità su 5. Restano `Intervento` e gli spostamenti, con la loro casella di roadmap: il trait su `Intervento` traccerebbe superfici (`segnaFatto`, `riapri`, cancellazioni) che quel passo non tocca né testa, e chiudere a metà una casella che sembra chiusa è peggio che lasciarla aperta.

---

**ADR-028 — Ogni intervento ha un assegnatario**

*Stato: Accettata (9 Ago 2026) — **precisa ADR-007**, che tratta l'assegnazione come uno dei due modi di dare accesso al Tecnico e non come un obbligo.*

**Contesto.** `interventi.tecnico_id` è nato nullable, e il form lo offriva con una voce «— Nessun assegnatario —». Emerso dall'uso: un intervento senza assegnatario non è una situazione reale, è un dato lasciato a metà. Nessuno lo "prende in carico", non compare nel lavoro di nessuno, e l'unico modo di accorgersene è cercarlo.

**Decisione.**
- Un intervento è **sempre assegnato a un tecnico**.
- **Obbligatorio nel form, nullable nello schema** — la stessa divergenza di `strumenti.fornitore_id` (🔗 ADR-023), per la stessa ragione, qui misurata: sul database di sviluppo **4178 interventi su 20672** non hanno assegnatario. Una FK NOT NULL li renderebbe non salvabili e costringerebbe a **inventare** un tecnico pur di farli passare — e un vincolo che obbliga a inventare un valore non protegge nulla, sposta solo il problema nei dati.
- Le righe storiche restano com'è finché nessuno le tocca, ma **modificarne una obbliga a scegliere**. È il prezzo della scelta e va detto, non scoperto.
- La regola è **condizionata al permesso `interventi.assign`**: chi non ce l'ha non mette la chiave nel payload (comportamento preesistente, ADR-007), quindi un `required` secco lo bloccherebbe del tutto invece di lasciargli fare ciò che può.

**Alternative scartate.**
- *FK NOT NULL con backfill:* il vincolo più forte, ma richiede di assegnare 4178 interventi storici a qualcuno che non li ha mai visti. Un dato inventato è peggio di un dato assente, perché è indistinguibile da uno vero.
- *Un utente "non assegnato" di sistema:* rende la colonna NOT NULL senza inventare persone, ma sposta la stessa ambiguità dentro i dati e costringe ogni lettura a conoscere quel caso speciale.
- *Regola nel model (hook `saving`) invece che nel form:* coerente con lo stile del progetto per gli invarianti, ma bloccherebbe seeder, import e migration su tutte le righe storiche — e renderebbe impossibile perfino correggerle.

**Conseguenze.**
- ERD §5.2: la colonna resta nullable, con la divergenza dichiarata sulla riga — è ciò che il prossimo lettore scambierebbe per una dimenticanza.
- ⚠️ **Accoppiamento fra permessi da sorvegliare**: un ruolo con `interventi.create` ma senza `interventi.assign` creerebbe interventi non assegnati, aggirando la regola senza toccare il form. Oggi non esiste — un **meta-test in `RbacSeederTest`** congela l'accoppiamento, così una modifica alla matrice se ne accorge lì e non da interventi senza padrone comparsi in produzione.
- Il segnaposto del select è `disabled`: serve ancora, perché aprendo una riga storica il campo deve poter mostrare «non ancora scelto» invece del primo tecnico dell'elenco — che sarebbe un'assegnazione fatta di fatto da un default.
- Rafforza ADR-007: se ogni intervento ha un assegnatario, il grant puntuale del Tecnico smette di essere un'eccezione e diventa il caso normale.
