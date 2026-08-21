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
- 🔗 **ADR-032 (18 Ago 2026)** introduce un'altra entità sopra l'Ente — l'**Account**, il cliente con più sedi che paga EasyLab (rapporto A, Cashier). Non è un rivenditore e non lo sostituisce: il Rivenditore è un *merchant terzo che incassa in proprio* (rapporto C, Connect), e i due modelli di incasso restano separati. `reseller_id` e `account_id` convivono sul nodo ente.

**Avvertenza settore pubblico (collegata ad ADR-010).** Per gli Enti **pubblici**, l'incasso self-service via Stripe (carta, abbonamento ricorrente) è spesso impraticabile: la PA tipicamente paga con **determina/mandato → bonifico a 30–60 gg**, può richiedere **split payment (scissione IVA)** e acquisto via **MEPA/CONSIP**, e impone la **fattura elettronica PA** (FatturaPA verso il **Codice Univoco Ufficio** iPA, 6 caratteri — diverso dal Codice Destinatario SDI a 7 dei privati). Conseguenza realistica: molti enti pubblici staranno sul percorso **Free + contratto + fattura offline** anziché sul SaaS Stripe. Il campo `codice_destinatario_sdi` va quindi interpretato con doppia semantica (privato vs PA). Verifica di business consigliata col commercialista prima di spingere il tier pubblico a pagamento.

---

**ADR-003 — Accesso via QR Code: login obbligatorio + scope dei permessi**

*Stato: Accettata — **attuata il 15 Ago 2026** (S4). La contraddizione che l'ADR cita nel Contesto era ancora viva nei permessi: la matrice dava `qr.scan` ❌ al Tenant mentre l'Elenco Funzionalità §4 diceva «il tecnico **o il cliente** inquadra il QR», e il cliente prendeva 403 sulla propria macchina. Sciolta a favore dell'Elenco: il QR è una **scorciatoia verso una pagina già autorizzata**, non un accesso in più — la rotta reindirizza a `strumenti.show`, che resta gatata e scopata. Deciso inoltre che la firma **non scade** (un adesivo vive quanto la macchina; a invalidarne uno serve rigenerare il token) e che **ristampare non rigenera** (un adesivo rovinato si rifà identico).*

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

*Stato: Accettata — **superata in parte da ADR-019** (3 Ago 2026) e, sulla privacy, da **ADR-029** (9 Ago 2026, non ancora attuata: la visibilità al Tenant diventa un'impostazione dell'Ente col default «modifica»). La garanzia a ore **non esiste**, e con essa spariscono `tipo_scadenza`, `soglia_ore`, `data_scadenza_prevista` e le letture contaore. Restano validi il motore sdoppiato macchina/ricambio, il campo unico `data_scadenza_effettiva` e la privacy delle garanzie ricambio. **Integrata da ADR-020**: la garanzia del ricambio pesa sul semaforo dello strumento.*

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

*Stato: **Attuata il 17 Ago 2026** (S4 blocco 9) — **estesa da ADR-030**, che distingue il tecnico interno da quello esterno e ne unifica la regola di accesso. Nulla di quanto segue è stato ritirato: i due canali, il pivot `tecnico_cliente` e l'audit sono attuati alla lettera.*

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

**Aggiornamento (15 Ago 2026) — il rinnovo della taratura, deciso in implementazione.**

Attuando la voce è emerso il buco che ADR-009 non copriva: chiusa una taratura, il suo motivo esce dalla diagnosi e lo strumento torna verde — ma la validità del certificato (12, 24 mesi) **non è registrata da nessuna parte** e nulla crea la taratura successiva. «La taratura alimenta il semaforo» era quindi vero per quella *da fare* e falso per quella *fatta*, cioè per l'unica che ha un certificato.

**Decisione**: chiudendo una taratura, l'applicazione **propone** di pianificare la successiva, e chiede **la periodicità in mesi**. La spunta è preselezionata sulle sole tarature e resta una scelta, non un automatismo.

- **Non è una quarta fonte di scadenze**, ed è la ragione per cui si è scelta questa forma: produce un **intervento ordinario**, che il semaforo sa già contare da S3. Far pilotare il semaforo dalla scadenza del certificato sarebbe costato **nove punti d'ingresso**, due dentro l'espressione SQL più fragile del progetto (il min NULL-safe dell'ordinamento).
- **La periodicità è un input e non un default nascosto**: nessun documento del progetto la quantifica, e inventare «12 mesi» in codice avrebbe prodotto scadenze plausibili e non volute su un parco di migliaia di macchine. La chiede la modale, ogni volta, a chi sta chiudendo il lavoro.
- La successiva nasce **fuori dalla transazione** della chiusura: chiudere una taratura e pianificarne un'altra sono due gesti, e il primo non deve dipendere dal secondo.

---

**ADR-010 — Fatturazione elettronica SDI: rimandata a versione futura**

*Stato: Accettata (parziale — vedi raccomandazione). **Integrata da ADR-032** (18 Ago 2026): i dati fiscali nascono sull'**Account**, non sul nodo ente — l'intestatario della fattura è chi paga, e con N Enti la singola sede non lo è più.*

**Contesto.** In Italia la fattura B2B deve transitare per lo SDI in formato XML. Stripe/Cashier gestiscono l'incasso ma non emettono la fattura conforme allo SDI. Riguarda il tier SaaS a pagamento (il "Free chiavi in mano" è fatturato a parte col contratto fisico).

**Decisione.** L'integrazione e-invoicing **è rimandata a una versione futura**. In V1 Stripe gestisce solo incasso/abbonamento; l'emissione della fattura elettronica resta fuori dal software (commercialista/manuale sui dati dei pagamenti). In una versione successiva si automatizzerà tramite un **servizio e-invoicing italiano** (es. Fatture in Cloud / Aruba / openapi.it via API, partendo dai webhook Stripe).

**Raccomandazione (a costo quasi nullo) — da confermare.** Già in V1 raccogliere nell'anagrafica cliente i **dati fiscali**: `partita_iva`, `codice_fiscale`, `pec` e/o `codice_destinatario_sdi`. Così l'attivazione futura dell'e-invoicing non richiederà di ricontattare tutti i clienti per ottenerli (retrofit doloroso).

**Conseguenze.**
- V1: nessuna logica SDI nel software.
- Versione futura: nuovo ADR per scegliere il provider e-invoicing e il flusso Stripe → XML → SDI.
- Roadmap: l'e-invoicing automatico è un elemento post-V1.

---

**ADR-011 — Canali di notifica V1: email (SMTP) + in-app, no push**

*Stato: Accettata — **attuata il 18 Ago 2026** (S5, primo blocco). Attuandola sono emerse tre cose che la decisione non poteva prevedere, tutte annotate sotto: la forma dell'invio (digest e non una email per scadenza), il fatto che in console **nessuno scope protegge più nulla**, e la necessità di un primo avvio silenzioso.*

**Decisione.** In V1 le notifiche ("email del futuro" e alert) usano **email via SMTP** + **notifiche in-app** nella dashboard. Niente push (web/mobile) in V1: l'app è web-responsive, non nativa; le push si valuteranno più avanti.

**Conseguenze.** Invio email accodato sulle code Redis (già nello stack). ~~Provider SMTP da definire (es. Postmark/SES/Mailgun o SMTP standard).~~ → **Deciso da Marco il 18 Ago 2026: SMTP standard su server di posta interno**, vedi sotto. Le notifiche in-app implicano una tabella `notifications` (notifiche di Laravel) consultabile dall'utente.

**Il canale di uscita: SMTP interno, non un servizio di invio transazionale** *(deciso il 18 Ago 2026, chiude il «da definire» qui sopra)*.

Le email escono dal **server di posta di EasyLab**, via SMTP autenticato. Non si adotta Postmark/SES/Mailgun. Il codice non cambia di una riga — Laravel parla SMTP e il mailer è già solo configurazione (`MAIL_MAILER=smtp` + host/porta/credenziali, Setup Ambienti §2.1) — ma tre conseguenze sono operative e vanno sapute:

- ✅ **Nessun sub-responsabile in più per le email.** Con un servizio terzo, ogni indirizzo e ogni contenuto di notifica sarebbero passati da un fornitore esterno, da elencare come sub-processor e da coprire con un DPA. Restando interno il dato non lascia il perimetro: il registro dei trattamenti si accorcia invece di allungarsi. *(Se il mailserver è ospitato presso un fornitore terzo, quel fornitore resta sub-responsabile: cambia il nome sulla riga, non l'adempimento.)*
- ⚠️ **La recapitabilità diventa responsabilità nostra**: **SPF, DKIM e DMARC** sul dominio mittente sono la differenza fra un promemoria letto e uno nella cartella spam. Un servizio transazionale li avrebbe portati in dote; qui vanno configurati e verificati prima del primo invio a un cliente reale. Vale anche il PTR/reverse DNS dell'IP di uscita.
- ⚠️ **Nessun rilevamento automatico dei rimbalzi.** Un indirizzo che non esiste più non si segnala da solo: l'utente risulta avvisato e non lo è. Non serve risolverlo ora — il volume è un digest al giorno per persona — ma è la ragione per cui, se un domani servisse sapere *chi ha davvero ricevuto*, si tornerà a valutare un servizio transazionale. La decisione è quindi reversibile e per un motivo preciso, non per ripensamento.

Il volume gioca a favore: il digest è **una email al giorno per destinatario e per Ente**, e solo nei giorni in cui qualcosa cambia — non una per scadenza. Un server interno regge questo profilo senza throttling; sarebbe stato un altro discorso con la forma «una email per scadenza», scartata anche per questo.

**Attuazione (18 Ago 2026).**

- **Un digest giornaliero per Ente, non una email per scadenza.** Un laboratorio con venti garanzie in scadenza riceverebbe venti messaggi nello stesso minuto, che è il modo più rapido per far finire il mittente fra lo spam. Il digest riporta i soli **cambi di stato** del giorno; nei giorni in cui non cambia nulla non parte niente.
- **Destinatari:** Admin dell'Ente (tutto), Responsabile Reparto (il proprio sotto-albero, con lo stesso criterio dell'applicazione), Tecnico assegnato (i soli interventi suoi). Il ruolo `Tenant` **non** è destinatario, quindi l'impostazione di 🔗 ADR-029 non entra in gioco. Chi rientra da più canali riceve una notifica sola.
- **Il tecnico esterno riceve un digest per ciascun Ente** (🔗 ADR-030), mai un documento unico: mescolare in una email i dati di due clienti farebbe con la posta ciò che il global scope impedisce nell'applicazione.
- ⚠️ **In console i global scope si ritirano** (`CurrentTenant::shouldScope()` è false): `TenantScope`, `DepartmentScope` e **anche `GaranziaRicambioPrivacyScope`. Isolamento e privacy del digest vivono quindi nel codice del comando** — `where('tenant_id')` espliciti, sotto-albero riapplicato a mano, e per il ricambio nessuna guardia ma una **select che non legge il nome del pezzo**. Ognuna ha il proprio test negativo, verificato per mutazione.
- **L'anti-duplicati è una tabella, `avvisi_scadenza`**, con `(riferimento, transizione, data_scadenza)` unico. La data nella chiave non è ridondanza: è ciò che distingue un duplicato da una **proroga**, che merita un avviso nuovo. Ruota a 24 mesi, ed è la «rotazione» che il registro dei trattamenti (T4) dichiarava senza ancora averla.
- ⚠️ **Il primo avvio su dati esistenti va fatto con `--senza-invio`.** Alla prima esecuzione il comando non ha memoria, quindi ogni scadenza già aperta è un cambio mai notificato: sul solo database di sviluppo sono **3297 interventi entro soglia**, e sulla base demo la prima email sarebbe stata di **1306 righe**. L'opzione popola il log in silenzio; dal giorno dopo arrivano solo le novità. È il primo passo del deploy, non una scorciatoia di sviluppo.
- **Opt-out solo per l'email** (`users.riceve_email_scadenze`, default attivo, pagina `/settings/notifiche`): è il diritto di opposizione del registro T4. Le notifiche in-app restano sempre, perché sono la copia di ciò che l'utente vede comunque entrando e non un invio verso l'esterno. La preferenza si legge **sul worker al momento dell'invio**, così chi la spegne dopo l'accodamento è comunque rispettato.
- **La campanella non fa polling.** La posta arriva una volta al giorno: un `wire:poll` sarebbe circa millequattrocento richieste al giorno per utente per un evento quotidiano. Il conteggio si aggiorna al cambio pagina; quando le notifiche diventeranno frequenti (S6) il polling sarà una riga.
- **Il canale è lo SMTP interno** (deciso il 18 Ago 2026, sopra): il codice usa il mailer configurato e in locale resta `MAIL_MAILER=log`. Prima del primo invio a indirizzi reali servono `MAIL_FROM_ADDRESS` del dominio EasyLab e i record **SPF/DKIM/DMARC**; il DPA per le email non serve più.
- ⚠️ **Il template usa il markdown di Laravel, non ancora un layout nostro**: nella parte *testo semplice* dell'email le tabelle restano in forma markdown grezza, e il piè di pagina è quello del framework («All rights reserved», in inglese). Si sistemano insieme allo `[STRETCH]` dei template brandizzati per tenant, che è la sede giusta: pubblicare ora le viste del pacchetto per una riga di footer significherebbe farlo due volte.

---

**ADR-012 — Onboarding: provisioning + self-signup (entrambi)**

*Stato: Accettata. **Integrata da ADR-032** (18 Ago 2026): il self-signup crea **account + primo Ente + primo membro** in un colpo; il provisioning aggancia l'Ente a un account esistente o ne crea uno (`easylab:provision-tenant --account=`). **Metà provisioning attuata lo stesso giorno** (invito via email + set password); il self-signup aspetta Cashier, perché la sua condizione d'ingresso è la verifica del pagamento.*

***Deroga dichiarata — il Superadmin (19 Ago 2026).*** *Questa ADR ha tolto di mezzo la consegna di password, e il `SuperadminSeeder` ne è l'unica eccezione: è l'account che deve poter entrare **quando non esiste ancora nessuno che possa invitarlo**, come già il Developer. Il prezzo è una password in variabile d'ambiente, e per contenerlo il seeder **senza `SUPERADMIN_EMAIL`/`SUPERADMIN_PASSWORD` non crea nulla** invece di ripiegare su un default noto — che è l'errore che il Developer si porta dietro da S0. Nasce insieme al proprio Account e al proprio Ente perché il TenantScope è fail-closed (🔗 ADR-018): un utente con `tenant_id` NULL entrerebbe e troverebbe l'app vuota. Le credenziali si leggono da `config/easylab.php` e mai da `env()` nel seeder: su Cloud `optimize` gira in build e con la config cachata ogni `env()` fuori da `config/` torna null — è così che su staging il Superadmin non veniva creato.*

***Note di attuazione della metà provisioning (18 Ago 2026):***
- *Il link d'invito è una **URL firmata temporanea** (7 giorni) su una pagina dedicata, **non** il flusso di reset password di Fortify: quello esiste già stilizzato, ma il broker `users` scade in **60 minuti** e Fortify valida i token con quello — un invito morto in un'ora non è un invito. È il precedente del QR (🔗 ADR-003), con `signed` davanti a tutto perché manomettere id o scadenza invalidi l'URL **prima** del route-model binding.*
- *Lo stato «invitato» **non è una colonna**: è `email_verified_at IS NULL` con una password random di 64 caratteri mai comunicata. Il click sul link e la scelta della password **sono** la verifica della casella. ⚠️ **`users.is_active` non è mai nata** (l'ERD la documentava come se esistesse): un terzo stato sarebbe stata la terza sorgente di verità su «questo utente può entrare?». L'euristica regge finché `Features::updateProfileInformation()` resta spenta — è l'unico percorso che potrebbe azzerare `email_verified_at` a un utente vero, e va rivisto quando la si accenderà.*
- *L'invito parte **fuori dalla transazione** e in modo **sincrono** (nessun `ShouldQueue`): in console un worker di coda può non esserci, e una notifica accodata direbbe «inviato» a un invito fermo in Redis. SMTP giù → il provisioning è comunque riuscito e il comando lo dichiara. **Reinviare = rilanciare lo stesso comando** su chi non ha ancora attivato: nessun comando in più.*
- *Nessun auto-login dopo il set: l'Admin è un ruolo 2FA-required, e la catena invito → password → login → 2FA è il flusso giusto.*
- *⚠️ Difetto latente emerso attuando: `email_verified_at` **non è nel `Fillable`** di `User`, quindi il `firstOrCreate` del provisioning lo scartava in silenzio — ogni Admin creato restava non verificato contro l'intenzione del codice. Ora si scrive con `forceFill`, come `tenant_id`.*

**Decisione.** Convivono due flussi di ingresso:
- **Provisioning gestito** da EasyLab/Admin per i clienti "Free / chiavi in mano" (creazione Ente + utente admin, invito via email, set password).
- **Auto-registrazione pubblica (self-signup)** per il tier SaaS self-service (registrazione + attivazione abbonamento autonoma).

**Conseguenze.** Auth scaffold con registrazione pubblica abilitata ma protetta (verifica email + verifica pagamento prima dell'attivazione del tenant). Servono flussi anti-abuso/spam sul signup pubblico. Il provisioning richiede un flusso di invito utente.

---

**ADR-013 — Blocco per insoluto: lockout totale**

*Stato: Accettata. **Integrata da ADR-032** (18 Ago 2026): l'insoluto è dell'**Account** — `is_locked`/`locked_*` nascono su `accounts` e il lockout blocca **tutti** gli Enti dell'account. La salvaguardia GDPR qui sotto vale invariata, per tutti gli Enti coinvolti. **Attuata il 18 Ago 2026 nella parte manuale + enforcement, e il 21 Ago 2026 nell'innesco automatico** (blocco Cashier).*

***Note di attuazione — innesco automatico (21 Ago 2026):***
- ***Il dunning è di Stripe, non nostro.** Stripe ritenta la carta per giorni e manda le proprie email; noi chiudiamo solo quando si arrende. `customer.subscription.updated` in `unpaid`/`canceled` e `customer.subscription.deleted` → blocco; ritorno ad `active`/`trialing` → sblocco. **`past_due` non blocca** — è il primo tentativo fallito, e chiudere lì significa sbattere fuori un cliente in regola che ha solo la carta scaduta. `invoice.payment_failed` non è nemmeno fra gli eventi registrati.*
- *⚠️ **Il lockout ha due sorgenti ortogonali, e non è un vezzo.** `locked_at`/`locked_reason` restano la sorgente manuale; `stripe_locked_at`/`stripe_lock_reason` sono quella automatica; `is_locked` vale «almeno una delle due è accesa». Con una sorgente sola, e `blocca()` idempotente come no-op, si aprivano due buchi reali: (a) account già chiuso da Stripe e poi chiuso a mano per contenzioso → il gesto manuale sarebbe stato un no-op, e **il primo pagamento riuscito avrebbe riaperto il contenzioso**; (b) ordine inverso → nessuna traccia dell'insoluto, e allo sblocco manuale il cliente sarebbe tornato operativo **senza pagare**, perché nessun evento futuro l'avrebbe richiuso. Entrambi gli ordini hanno il loro test.*
- ***La firma è verificata sulla rotta, non nel controller.** Il `WebhookController` di Cashier aggancia `VerifyWebhookSignature` solo `if (config('cashier.webhook.secret'))`: senza segreto — per esempio su un ambiente dove la variabile non c'era al momento della build, con la config cachata — l'endpoint accetterebbe payload arbitrari, cioè **chiunque ne conosca l'URL potrebbe bloccare account altrui**. Fail-open su un percorso che scrive `is_locked`. Da noi `Cashier::ignoreRoutes()` spegne le rotte del pacchetto e la nostra dichiara il middleware incondizionatamente, visibile in `route:list`.*
- *⚠️ **E non bastava.** Il `/security-review` ha trovato che sotto la fail-open dell'**aggancio** ce n'era una **crittografica**: `WebhookSignature::verifyHeader()` calcola la firma attesa con `hash_hmac('sha256', $payload, $secret)`, quindi **con un segreto vuoto quella firma la può calcolare chiunque** — il middleware la verifica, la trova giusta e passa. Non è un caso di scuola: su Cloud `optimize` gira in build e il `whsec_…` esiste solo **dopo** aver creato l'endpoint in dashboard, quindi su ogni ambiente nuovo c'è una finestra garantita in cui la rotta è viva e il segreto è null. In quella finestra, conoscendo un `cus_…`, si bloccano tutti gli utenti di tutti gli Enti di un account, oppure ci si sblocca il proprio regalandosi il piano a pagamento. Chiuso da `App\Http\Middleware\VerificaFirmaWebhookStripe`, che rifiuta **prima** se il segreto è vuoto: senza segreto il webhook è **inerte, non ostile**, e il prezzo è un evento perso che Stripe ritenta. Il test che doveva coprirlo era verde per la ragione sbagliata — mandava un header di firma **vuoto**, scartato dal parser prima del confronto HMAC. È la stessa lezione delle mutazioni no-op: un negativo va scritto contro l'attacco vero, non contro la sua imitazione.*
- *La rotta sta in `bootstrap/app.php` (`withRouting(then:)`) e non in `routes/web.php`: lì avrebbe ereditato il gruppo `web` e quindi `ValidateCsrfToken`, e una POST di Stripe non porta alcun token — 419 a ogni evento, cioè **un lockout che non scatta mai, in silenzio**. Prima stesura fatta così, scoperta guardando `route:list`.*
- *⚠️ **Nessuno scope protegge questo percorso**: non c'è utente, e in contesto console/job i global scope si ritirano tutti (🔗 ADR-011 §292). L'unico filtro fra un payload e un `is_locked` è l'uguaglianza su `stripe_id` — che per questo è **UNIQUE a DB** — con il suo test di non-trapelamento.*
- ***Customer sconosciuto o account cestinato → 200 e nessuna scrittura.** Un 404 o un 500 farebbe ritentare Stripe per giorni e poi **disabilitare l'endpoint**, facendoci perdere anche gli eventi buoni: il danno sarebbe molto peggiore dell'evento ignorato.*
- ***Sincrono, mai accodato**: con la managed queue di Cloud lo scale-to-zero può interrompere un job, e un webhook già confermato 200 non torna. Un lockout perso è un cliente moroso che continua a lavorare.*
- ***Idempotenza strutturale**, senza tabella di eventi: il parent scrive con `firstOrNew` + `save()` sullo `stripe_id`, i gesti sono no-op se lo stato è già quello. *Rischio residuo dichiarato*: Stripe non garantisce l'**ordine** di consegna, quindi un `unpaid` vecchio recapitato dopo un `active` richiuderebbe un cliente in regola — compensazione immediata con `easylab:lockout --sblocca`, e la riga di audit dice quale evento ha bloccato. Una tabella `stripe_events` è stata valutata e rimandata.*
- *L'audit ha `causer_id` **null**: non l'ha fatto nessuno, l'ha fatto Stripe; il riferimento all'evento sta in `stripe_lock_reason`, che resta annotazione interna e non si mostra al bloccato.*

***Note di attuazione (18 Ago 2026):***
- *Il gesto è `Account::blocca(motivo)`/`sblocca()` — idempotente come no-op (`locked_at` documenta QUANDO è iniziato l'insoluto e non si riscrive), auditato dal trait via `attributiDerivatiTracciati()`. La leva è `easylab:lockout {account} [--sblocca] --motivo=`; la UI arriva in S6 con la dashboard, dietro `billing.lockout` (set 🔒, oggi dichiarato e non consumato).*
- *L'enforcement è il middleware `account.lockout` sul gruppo protetto, **prima** del 2FA (la condizione più forte parla per prima), e — prima registrazione del progetto — **persistente per Livewire**: senza, ogni azione Livewire (cioè quasi tutte le scritture) aggirerebbe il blocco, perché `/livewire/update` non passa dai middleware di pagina.*
- *Il bloccato finisce su **`/bloccato`** (guest-layout, fuori dal gruppo per collocazione): messaggio generico + logout + **fuga verso le sedi sane** dei suoi altri account, servita da `User::sediRaggiungibili()` — la stessa query dello switcher. `locked_reason` **non si mostra al bloccato**: è un'annotazione operativa interna (solleciti, riferimenti), il suo destinatario è la dashboard S6.*
- ***Il bypass in impersonazione È l'attuazione della salvaguardia GDPR qui sopra**: il Superadmin impersona, assiste ed esporta anche in lockout, e ogni passo è già tracciato; il membro reale resta fuori (controprova nei test).*
- *Il **Tecnico esterno** (`tenant_id` null) non è toccato dal middleware — scelta V1: il lockout ferma il cliente moroso, non l'assistenza che serve le macchine (ADR-030). Il punto d'innesto, se si cambiasse idea, è uno solo. `users.is_active` (ADR-012) resta un interruttore distinto: spegne una persona, non un contratto.*

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
- *Selettore di tenant per il Superadmin (scoping a un Ente scelto senza impersonare):* comodo ma duplica la logica dell'impersonazione e aggiunge un secondo percorso di accesso cross-tenant da proteggere. L'impersonazione (già presente, già loggata) copre il bisogno. *(Lo **switcher fra i propri Enti** di 🔗 ADR-032 non è questo selettore: naviga fra Enti dello **stesso account**, non verso dati altrui — il confine che qui si proteggeva lì non esiste.)*

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
- 🧪 Test negativo obbligatorio: *un Tenant vede arancione uno strumento la cui unica scadenza è la garanzia di un ricambio, e ~~non vede da nessuna parte il nome del pezzo~~ **nessuna etichetta del semaforo gli dice di che garanzia si tratta**.*

  ⚠️ **Ristretto il 15 Ago 2026, aprendo il tab Ricambi.** La formulazione originale («da nessuna parte») contraddiceva lo Schema Ruoli — nota ³: «il Tenant vede ricambi/utilizzi montati sulle proprie macchine» — e la matrice, che gli dà `ricambi.view` e `ricambio_utilizzo.view`. La contraddizione era rimasta invisibile per una ragione sola: **non esisteva nessuna schermata in cui il nome potesse comparire**, quindi il test passava per assenza di superficie e non per una regola. Sciolta a favore dello Schema Ruoli: ADR-004 protegge la **copertura** del pezzo, che è una condizione commerciale fra EasyLab e il cliente, non il fatto che sulla macchina del cliente sia stata montata una guarnizione. Resta protetta la garanzia — e l'etichetta che la nomina.

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

*Stato: Accettata (3 Ago 2026) — **corregge l'ERD §7.3**, che modellava la relazione come pivot molti-a-molti. **Attuata il 15 Ago 2026** (S4). Attuandola sono emerse due cose: il CRUD dell'anagrafica **non era un extra ma la precondizione** — senza una schermata da cui popolarla, il campo obbligatorio avrebbe reso `strumenti.create` inutilizzabile per tutti — e `fornitori.view` al Tenant, che questo ADR e lo Schema Ruoli davano per approvato dal 3 Ago, **non era mai entrato in `config/rbac.php`**: il documento affermava un default che il bootstrap non produceva.*

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

**Emendamento del 15 Ago 2026 (S4 blocco 7, completamento del tab).** Tre precisazioni, prese scrivendolo:

- **Il punto 4 si legge al contrario, e va corretto.** «N pezzi coperti *per chi non ha il permesso*» sottintendeva che l'aggregato fosse il ripiego di chi non vede il dettaglio. Non lo è: un aggregato di coperture **è** informazione sulle garanzie ricambio, e mostrarlo a chi non ha titolo a vederle direbbe a un Tenant in Ente `nascosta` quanti pezzi coperti ci sono sulla propria macchina — cioè la cosa che 🔗 ADR-029 gli nega. Il contatore è quindi **gated come tutto il resto dell'area**, sull'ability `view` di `GaranziaRicambioPolicy` (mai sul permesso nudo: `spatie` lo concederebbe prima che l'impostazione dell'Ente sia letta). Resta valido e distinto il caso dei **motivi del semaforo**, che si degradano a testo neutro per tutti: lì l'aggregato è il pallino, dovuto a chiunque subisca il suo effetto.
- **Il contatore delle coperture sta accanto ai RICAMBI, non nella card della garanzia macchina**, che è gated su `garanzie.macchina.view`: sono due aree con due permessi, e ospitare l'una nell'altra toglierebbe un dato della propria area a chi ha solo la prima. «Coperto» significa inoltre **coperto adesso**: una garanzia già scaduta non entra nel conteggio.
- **I ricambi del punto 6 sono due numeri, non uno**: «montati» conta le righe con la data di montaggio valorizzata, «in attesa di montaggio» le altre, e la seconda riga compare solo se maggiore di zero. È lo stesso confine con cui 🔗 ADR-020 decide se un pezzo pesa sul semaforo, e un conteggio unico lo contraddirebbe in una vista che di quel semaforo è la spiegazione.

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
- ✅ **Sciolto il 15 Ago 2026** (S4): si è scelta la **rotta firmata Laravel che fa da tramite**. Il download passa da `ScaricaDocumento`, che ricontrolla l'autorizzazione a ogni richiesta e legge dal disco `documenti`; il provider resta un dettaglio di `.env`. Verificato end-to-end sul bucket B2 vero: file caricato, riga creata, download servito dall'applicazione con `Content-Disposition` corretto. **La reversibilità dipende da come si servono i file**, e la scelta era aperta: ADR-009 dice "URL firmate" senza specificare quale: una **URL pre-firmata S3** è di fatto un bearer token — chi ce l'ha legge il file fino alla scadenza, con la Policy fuori dal giro — mentre una **rotta firmata Laravel che fa da tramite** ricontrolla l'autorizzazione a ogni richiesta e funziona identica su qualunque disco. La seconda è più coerente con ADR-003 ("mai dati senza auth") e ADR-018 (fail-closed), e rende il provider davvero sostituibile. **Da decidere in S4**, quando nasce la feature documenti.
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

*Stato: Accettata — **attuata il 15 Ago 2026** (S4). Attuandola: il disco `documenti` nasce separato **col solo scopo di avere `throw => true`** — con `false`, che è il default di Laravel e che gli altri tre dischi portavano, un upload fallito restituisce `false` in silenzio e una lettura mancante fa `readStream()` → `null` DENTRO lo StreamedResponse, cioè a header già inviati: un 200 troncato invece di un errore. Il controller controlla perciò l'esistenza **prima** di rispondere. Il download è tracciato sul canale `audit`, ed è la contropartita di aver scelto di far passare i file dall'applicazione.*

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
  - ✅ **Copertura completata il 15 Ago 2026** (S4): il trait è ora anche su `Intervento` e `SpostamentoStrumento`. Il criterio trait-vs-esplicite, riformulato dopo averlo applicato cinque volte: **le esplicite servono quando l'informazione che conta NON è una colonna** — in `forzaSemaforo()` conta che il forzato *scavalchi* uno stato calcolato che in tabella non esiste; chiudere un intervento invece *è* scrivere `stato` e `data_esecuzione`, e l'elenco dei campi dice già tutto.
  - **Le esenzioni sono dichiarate, non implicite**, ed è la parte che questo ADR chiedeva a sé stesso («chiudere a metà una casella che sembra chiusa è peggio che lasciarla aperta»): `Strumento` logga a mano; `User` è coperto da `AuditLogSubscriber` su un altro canale; `UnitaOrganizzativa` traccia **la sola** scrittura che conta — la visibilità garanzie ricambio (🔗 ADR-029) — e il resto dell'anagrafica no. Un **meta-test** (`AuditCoverageGuardrailTest`) tiene l'elenco: chi aggiungerà `Fornitore` o `Documento` dovrà scegliere invece di dimenticare, e la regola «mai entrambi» è verificata meccanicamente sul codice.
  - 🐛 **Difetto corretto nello stesso passaggio**: `RicambioUtilizzo` aveva il trait dal 9 Ago ma non il proprio sostantivo, e per una settimana ha scritto «Creazione ricambioutilizzo». Il test che c'era guardava il prefisso (`toStartWith('Creazione')`) e non il sostantivo: le righe già scritte restano così, perché l'audit non si riscrive.
  - ⚠️ **Perché anche gli spostamenti**, benché `spostamenti_strumento` sia già append-only con `eseguito_da` e `data`: quella `data` è di BUSINESS e retrodatabile dal form, mentre `activity_log.created_at` è l'istante reale; `eseguito_da` è nullable e `nullOnDelete()`, quindi cancellando l'utente sparisce l'unico riferimento; e la vista Audit di S6 filtra per canale — ciò che non è sul canale non esiste.
  - ⛔ **Fuori perimetro, dichiarato**: i **tentativi respinti** (403/404) non lasciano traccia. L'audit registra le scritture avvenute; il tentativo negato è un dato di sicurezza, cioè un canale e una decisione diversi. Tre test negativi lo congelano.

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

---

**ADR-029 — La visibilità delle garanzie ricambio al Tenant è un'impostazione dell'Ente, non un divieto**

*Stato: Accettata (9 Ago 2026) — **supera il vincolo di privacy di ADR-004** (e con esso la voce corrispondente del set 🔒 di ADR-016). **Attuata il 15 Ago 2026** (S4 blocco 5). Attuandola sono emerse due cose che la decisione non poteva prevedere: il vincolo di scrittura **non è esprimibile con un permesso** (spatie concede prima che l'impostazione sia letta) ed è finito nella **prima Policy del progetto**; e un permesso nudo rimasto in una vista — la Panoramica — scavalcava l'impostazione, trovato da un test e non rileggendo il codice.*

**Contesto.** Dal documento di Fase 2 in poi il progetto ha portato una regola sola: «le garanzie sui ricambi restano visibili solo a EasyLab/Admin, mai al Tenant». ADR-004 la ratifica dichiarandola «vincolo di privacy **già previsto**» — cioè la eredita senza motivarla —, lo Schema Ruoli la irrigidisce mettendo `garanzie.ricambio.*` nel set 🔒, il registro GDPR la giustifica a posteriori come minimizzazione, e ADR-020 ci costruisce sopra la distinzione fra aggregato (il pallino, dovuto a tutti) e dettaglio (la riga, negata al Tenant).

Verificando ADR-020 sui dati veri, il 9 Ago 2026, è emerso che la regola poggia su una **premessa mai scritta**: che i ricambi li fornisca EasyLab, e che la loro copertura sia quindi un dato del suo rapporto commerciale. La premessa non regge al modello di prodotto: il Tenant è **l'intestatario dell'abbonamento** e il pezzo è montato su una macchina sua, spesso pagata da lui. Nascondergli quel dato per difetto significa nascondergli i propri dati.

Ma il caso opposto esiste davvero — un Ente servito da EasyLab in full service, dove la copertura dei pezzi è informazione del fornitore — e una regola unica non può essere giusta per entrambi.

**Decisione.**
- La visibilità delle garanzie ricambio al ruolo `Tenant` diventa un'**impostazione per-Ente**, governata dal Superadmin (EasyLab), a **tre stati**: `nascosta`, `lettura`, `modifica`.
- **Il default alla creazione dell'Ente è «modifica»**: chi paga l'abbonamento possiede i propri dati, e l'eccezione va giustificata da chi la impone — non il contrario. È l'inversione esatta della postura precedente.
- `garanzie.ricambio.*` **esce dal set 🔒**: smette di essere «mai concedibile» e diventa concedibile *per Ente*. Non diventa però un permesso come gli altri — resta governato dal Superadmin, non dall'Admin dell'Ente, perché è una clausola del rapporto commerciale e non una preferenza interna.

**Alternative scartate.**
- *Due soli stati, `lettura` e `modifica`:* è la forma in cui la decisione era stata espressa, ed è stata scartata **misurandone la conseguenza**. In sola lettura il Tenant vede comunque le righe e i nomi dei pezzi: senza un terzo stato il caso full service non sarebbe più rappresentabile, e con esso perderebbero oggetto il bypass del privacy scope nel calcolo semaforo, il degrado delle etichette e — soprattutto — il **test negativo obbligatorio di ADR-020**, che andrebbe cancellato invece che riscritto. Il terzo stato costa una variante in più da testare su ogni superficie; toglierlo costava una capacità del prodotto e una difesa.
- *Concederlo a tutti i Tenant senza impostazione:* semplice, ma toglie a EasyLab la possibilità di servire in full service un cliente a cui la copertura dei pezzi non va mostrata. Il caso che ha originato la regola sparirebbe invece di essere gestito.
- *Lasciare il divieto e concedere caso per caso via RBAC:* il set 🔒 è nato apposta perché quei permessi **non** fossero ridistribuibili dalla UI; usarlo al contrario ne svuoterebbe il senso, e un'eccezione concessa a mano non lascia traccia di *chi* l'ha decisa.
- *Impostazione a livello di utente:* è una clausola contrattuale, e vale per l'Ente. Per-utente moltiplicherebbe gli stati senza rispondere a nessuna domanda reale.

**Conseguenze.**
- ✅ **Attuata il 15 Ago 2026**: colonna `visibilita_garanzie_ricambio` sull'Ente (NOT NULL, default `modifica`), `GaranziaRicambioPolicy`, privacy scope che delega alla Policy, uscita dal set 🔒 in `config/rbac.php` con riseeding del DB di sviluppo (confrontato prima ruolo per ruolo: nessuna personalizzazione da perdere), controllo nel form Ente gated su `roles.manage`, quattro test riscritti consapevolmente e quattro mutazioni verificate.
- ⚠️ **Limite noto: oggi il controllo si usa solo sul proprio Ente.** Il Superadmin è tenant-bound (🔗 ADR-018) e nell'anagrafica vede un Ente solo, quindi dall'interfaccia può impostare la visibilità di quello e non degli altri; per gli altri si passa da console. Non è aggirabile con l'impersonazione — l'Admin dell'Ente non ha `roles.manage` e il campo non lo vede, che è esattamente il gate voluto. La sede giusta è la **dashboard Superadmin di S6**, che per definizione interroga la piattaforma senza scoping. Emerso provando il flusso reale, non leggendo il codice.
- ⚠️ **La colonna sta fuori da `$fillable`** e si scrive solo da `fissaVisibilitaGaranzieRicambio()`: il form dell'anagrafica scrive nome, note e soglia per mass-assignment, e senza quell'esclusione il controllo «solo il Superadmin» vivrebbe unicamente nella vista — cioè nel posto più facile da aggirare. Un test lo verifica forgiando il campo da un Admin.
- **Il lavoro di ADR-020 non si butta e non cambia:** l'aggregato resta dovuto a tutti e il dettaglio resta condizionato: cambia solo *da cosa* dipende il permesso. Bypass e degrado dell'etichetta servono ancora, per gli Enti in stato `nascosta` e per i ruoli che il permesso non l'hanno; il test negativo di ADR-020 si riscrive puntandolo a un Ente configurato così, invece di sparire.
- **`lettura` e `modifica` non si distinguono per scope ma per Policy**: in entrambi gli stati le righe si vedono, quindi il global scope non le filtra — a cambiare è il permesso di scrittura. Confondere i due piani (nascondere una riga per negarne la modifica) è l'errore che renderebbe il tab Ricambi incomprensibile: pulsanti assenti e righe mancanti si leggono in modo diverso.
- **Va fatta prima del tab Ricambi** (S4 blocco 5), che è la schermata in cui quelle righe si leggono e si correggono: scriverlo con la regola vecchia significherebbe rifarlo.
- Da aggiornare quando si attua: ADR-004 (che qui viene superato), Schema Ruoli §4/§6 e il set 🔒 di ADR-016, il registro GDPR — dove la minimizzazione va riformulata: il dato non è più negato per difetto, è configurabile.
- **Due meccanismi, due domande diverse — e il motivo per cui non è ridondanza.** `spatie/laravel-permission` gira con `teams = false` (scelta di Schema Ruoli §7: «un'unica matrice per tutta la piattaforma; personalizzazione per-Ente → V1.1»), quindi i permessi di un ruolo sono **globali**: concedere `garanzie.ricambio.*` al ruolo `Tenant` lo concede a *tutti* i Tenant di *tutti* gli Enti, insieme. RBAC può quindi esprimere il **default** ma non l'eccezione. La gerarchia è perciò:
  - **RBAC (config + editor UI di S6) decide il default globale** — il permesso è la condizione necessaria;
  - **l'impostazione dell'Ente restringe** per il solo ruolo `Tenant`, ed è l'unica sede in cui «questo Ente sì, quell'altro no» è dicibile.
  L'impostazione **non allarga mai**: un Ente in `modifica` non dà nulla a chi il permesso non ce l'ha. Conseguenza da mettere in conto: uscito dal set 🔒, l'editor di S6 potrà revocare il permesso al ruolo `Tenant` per tutti gli Enti con un click, scavalcando di fatto ogni impostazione — è coerente (chi governa la piattaforma governa il default), ma va saputo.
- **Il vincolo di scrittura NON può appoggiarsi al permesso nudo.** `spatie` registra un `Gate::before` che **concede** non appena il permesso esiste sul ruolo: un `Gate::define` omonimo non verrebbe mai raggiunto, e un secondo `before` registrato dopo nemmeno. Serve quindi un'**ability di Policy** distinta dal nome del permesso — che è del resto ciò che Schema Ruoli §6 prescriveva già («applicato via Policy + Global Scope»).
- 🔗 Si incrocia con la questione aperta **«un utente, N Enti»** (vedi roadmap S5): se l'abbonamento arriverà a coprire più Enti, questa impostazione resta per-Ente ma il suo intestatario sarà l'account, non il singolo Ente. → **Sciolto da ADR-032 (18 Ago 2026), esattamente così**: l'impostazione resta per-Ente, l'intestatario del rapporto è l'Account.

---

**ADR-030 — Il Tecnico esiste in due forme (interno ed esterno), e la regola di accesso è una sola**

*Stato: Accettata (17 Ago 2026) — **estende ADR-007**, che resta valido in ogni sua parte: i due canali, il pivot, l'audit. Attuata nello stesso giorno (S4 blocco 9).*

***Emendata in giornata (S4 blocco 10)**: il punto sull'ubicazione, vedi «Conseguenze».*

**Contesto.** ADR-007 descrive il Tecnico come **staff EasyLab** che opera su clienti diversi, e chiude dicendo che «non è un utente-tenant: vive a livello piattaforma». L'ERD, di conseguenza, dà `users.tenant_id` NULL per il Tecnico. Il `DemoSeeder` però glielo valorizzava, e non per errore: esiste una seconda figura, reale e frequente, che ADR-007 non nomina — il **tecnico dipendente del laboratorio**, che non serve più clienti perché ne ha uno solo, il proprio.

Fino a S4 la contraddizione non costava nulla, perché il Tecnico non vedeva niente in ogni caso (fail-closed di ADR-018, congelato da un test). Implementando l'accesso andava sciolta, e sceglierne una sola forma avrebbe fatto danni in entrambe le direzioni: «solo esterno» rende inesprimibile il tecnico interno; «solo interno» cancella il portafoglio clienti, cioè metà di ADR-007.

**Decisione.**

1. **Due forme, distinte dal dato**: `users.tenant_id` NULL → tecnico **esterno** (staff EasyLab); valorizzato → tecnico **interno** (dipendente dell'Ente).
2. **Una sola regola di visibilità, per entrambi**:

   > visibile ⇔ riga di un Ente nel **portafoglio** ∪ riga della macchina di un **intervento assegnato**

   Anche l'interno vede quindi solo le macchine che gli sono state affidate, e **non più tutto il proprio Ente**. È l'esposizione minima che ADR-007 già voleva; applicarla a una sola delle due forme avrebbe significato due regole da tenere allineate, cioè due regole destinate a divergere.
3. **Il `tenant_id` dell'interno resta, come difesa in profondità**: si applica in AND al criterio. Non è più un criterio di accesso, ma impedisce che un errore nel portafoglio — una riga inserita per sbaglio dalla futura pagina permessi — porti un dipendente del laboratorio A dentro i dati del laboratorio B.
4. **L'audit vale per tutti i tecnici**, interni compresi. È la **seconda eccezione** al perimetro di ADR-027 «si tracciano le scritture, non le letture» — la prima è il download dei documenti (ADR-026). Distinguere interno da esterno vorrebbe dire far dipendere la traccia da `tenant_id`, che questo ADR declassa espressamente da criterio a difesa: il registro si baserebbe su un dato che abbiamo appena dichiarato non portante.

**Conseguenze.**

- **Il fail-closed di ADR-018 sopravvive per costruzione, non per una guardia.** Entrambi i canali sono `IN (sottoquery)`: due sottoquery vuote danno falso, quindi un tecnico senza portafoglio né assegnazioni non vede nulla senza che nessuno debba ricordarsi di scrivere il ramo «se non ha niente».
- **Il ramo vive in `TenantScope` e non in uno scope separato**, perché il criterio **sostituisce** il confine Ente — per l'esterno non c'è alcun Ente da cui partire — mentre un secondo scope avrebbe potuto solo comporsi in AND, cioè restringere ciò che non esiste ancora.
- **Nuovo contratto `ReachesStrumento`** (`app/Models/Contracts/`): il modello sa **restringere una query** alle proprie righe che si riferiscono a un insieme di strumenti. Il contratto è la restrizione e non la colonna, ed è `Garanzia` a imporlo: allo strumento arriva per due strade — `strumento_id` sulle righe macchina, il doppio salto via `ricambio_utilizzo` su quelle ricambio — e con un contratto «dammi la colonna» il secondo ramo sarebbe inesprimibile, perché `NULL IN (...)` è UNKNOWN. È lo stesso difetto che il blocco 2 aveva corretto per il Responsabile.
- **Dimenticare il contratto non apre falle ma toglie accessi in silenzio**: il modello resta raggiungibile per il solo portafoglio. Un meta-test (`TenantScopeGuardrailTest`) esige quindi che ogni modello con `strumento_id` lo dichiari — e alla prima esecuzione ha segnalato `SpostamentoStrumento`, cioè lo storico che la vista di campo del blocco 10 deve mostrare.
- **Cinque test preesistenti hanno cambiato affermazione**, tutti per la restrizione del punto 2 e ciascuno annotato sul posto: quello che congelava il debito di ADR-007 (rovesciato), due di `GaranziaPrivacyTest` (il tecnico riceve ora il portafoglio, o misurerebbero l'accesso invece della privacy), uno di `InterventoActionsTest` e uno di `SchedaInterventiTest`, che si chiamava «shows the list to a Tecnico of the ENTE» — un titolo che era già la regola sbagliata.
- ⚠️ **Emendamento del 17 Ago 2026 — l'ubicazione di una macchina visibile si legge sempre.** La prima stesura di questo ADR dichiarava accettabile che il canale «assegnazione» non desse accesso all'albero organizzativo: `UnitaOrganizzativa` non raggiunge uno strumento, quindi al tecnico restava il solo portafoglio. Sul desktop la conseguenza non si notava; aperta la scheda su un telefono si vedeva «Christ Alpha 2-4 · **·** Installato 09/2017» — un buco al posto del laboratorio in cui il tecnico deve andare a lavorare, cioè proprio il dato che il wireframe §3 mette subito sotto il nome della macchina perché è così che la si trova. **Dire dove sta una macchina che l'utente già vede non rivela nulla che quella macchina non riveli da sé**, mentre nasconderlo rende inutilizzabile il caso d'uso per cui l'accesso è stato concesso. La risalita vive ora in `Strumento::percorsoUbicazione()`, che legge i nodi senza global scope: è la **terza eccezione nominata** del progetto, dopo `User::ente()` e `Garanzia::deiPezziMontati()`, e come quelle sta in un metodo solo e motivato. Nota: lo stesso difetto colpiva **silenziosamente** il Responsabile Reparto, il cui percorso si fermava al confine del sotto-albero perdendo il nome dell'Ente.
- **La UI del portafoglio non esiste ancora**: in S4 nascono pivot, scope, audit e seeder. La gestione arriva in S6 con la pagina permessi, dove vivranno anche gli altri controlli di questo tipo.
- 🔗 Si incrocia con **«un utente, N Enti»** (roadmap S5): il tecnico esterno è oggi l'unico utente della piattaforma che legittimamente attraversa più Enti, e lo fa **senza** `tenant_id`. Se quella questione porterà a un'entità *account* sopra l'Ente, il portafoglio sarà la relazione da rileggere per prima. → **Riletto da ADR-032 (18 Ago 2026)**: il pivot è servito da modello per `account_user` e **resta com'è** — elenca Enti, non account, perché il tecnico lavora su sedi, non su contratti.

---

**ADR-031 — Esportazione PDF con dompdf (HTML→PDF in PHP), non con un browser headless**

*Stato: Accettata (17 Ago 2026) — scioglie l'alternativa lasciata aperta dal Tech Stack §47 («barryvdh/laravel-dompdf **o** spatie/laravel-pdf»). Attuata lo stesso giorno (S4 STRETCH).*

**Contesto.** L'Elenco Funzionalità chiede di esportare «storici, certificati e report di fine lavoro in formato PDF formattato», e il permesso `documenti.export_pdf` era a catalogo dal S1. Le due librerie candidate hanno nature diverse: **dompdf** è PHP puro e interpreta un sottoinsieme di HTML/CSS; **spatie/laravel-pdf** guida un Chromium headless via Browsershot e rende esattamente come un browser.

**Decisione.** Si usa **`barryvdh/laravel-dompdf`**.

**Perché.**

- **Nessun binario esterno da installare e tenere aggiornato.** 🔗 ADR-025 mette l'applicazione su Laravel Cloud: Browsershot richiederebbe Chromium e Node nel container, cioè una superficie di attacco e una catena di aggiornamenti in più per produrre un foglio A4. Un PDF non vale un browser sul server.
- **La resa fedele non serve, perché il documento non è la pagina.** Un report di fine lavoro non è la scheda strumento stampata: è un foglio con intestazione, tabella e firme, scritto in HTML dedicato. Il CSS moderno che dompdf non regge — flexbox, grid, i token Tailwind v4 — in un foglio del genere non serve, e usarlo sarebbe un errore anche col browser: **un PDF non è responsive**.
- **Prevedibilità.** Un rendering in-process fallisce con un'eccezione PHP; un browser headless fallisce per timeout, memoria o versione, cioè in modi che si vedono solo in produzione.

**Conseguenze.**

- I fogli si scrivono con **HTML e CSS semplici** (tabelle, `@page`, unità assolute), in view dedicate sotto `resources/views/pdf/`. Non si riusano i blade dell'app: sarebbero due consumatori con esigenze opposte sullo stesso markup.
- **Nessun font esterno**: dompdf ha i font di base incorporati, e caricarne uno via `@font-face` significherebbe scaricarlo a ogni render o versionarlo nel repository. I fogli usano quindi un font di sistema, e la coerenza col Design System si esprime in struttura e gerarchia, non nel disegno delle lettere.
- Se un domani servisse un documento con resa grafica fedele — un'offerta commerciale, non un report tecnico — la decisione si riapre: le due librerie possono convivere, perché la scelta è per-documento e non di piattaforma.
- ⚠️ **Il PDF si genera al volo e non si archivia.** Un file salvato invecchia: lo storico cambia, l'intervento si riapre, il report si corregge — e resterebbe in giro un foglio che dice un'altra cosa. Se servirà congelare un documento (una firma, un invio al cliente), sarà una decisione esplicita con una sua riga in `documenti`, non un effetto collaterale dell'export.

---

**ADR-032 — L'intestatario dell'abbonamento è un Account sopra l'Ente; `tenant_id` non si tocca**

*Stato: Accettata (18 Ago 2026) — scioglie il punto 🚩 della roadmap S5 («un utente Tenant può avere N Enti», segnalato da Marco il 9 Ago 2026 e confermato il 17 Ago 2026, conferma che aveva già escluso gli Enti gerarchici). Non supera nessuna decisione precedente: ADR-006 e ADR-018 restano validi alla lettera. Chiude i rimandi lasciati aperti da ADR-029 e ADR-030. **Fondamenta attuate lo stesso giorno** (schema, backfill 1:1, provisioning `--account=`, switcher in top bar — suite a 739); le colonne Cashier, il middleware di lockout e l'onboarding restano ai punti S5 successivi.*

***Note di attuazione (18 Ago 2026):***
- *La unique del pivot è `(user_id, account_id)` — l'ERD nasceva con l'ordine opposto — perché il percorso caldo è «gli account dell'utente X», letto dallo switcher a ogni pagina (stesso ragionamento di `tecnico_cliente`).*
- *Nel backfill i membri sono gli **Admin del tenant** (fallback: i Superadmin; altrimenti account senza membri, con warning nel log): l'invariante «ogni account ha ≥1 membro» vale da qui in avanti, non retroattivamente, e vive in `Account::rimuoviMembro()`.*
- *La guardia `tipo = ente` dello switcher è **difesa in profondità** (forma ADR-030): il check di appartenenza basterebbe finché l'invariante «account_id solo sui nodi ente» regge — un test la esercita corrompendo il dato apposta, perché la prima stesura della prova di mutazione l'aveva trovata non provabile.*
- *Lo switcher rifiuta gli account in **lockout** già oggi: le colonne nascono con questo blocco e un ingresso nuovo nasce chiuso; il middleware sulla navigazione ordinaria resta al punto ADR-013.*

***Note di attuazione — blocco Cashier (21 Ago 2026):*** *suite da 791 a **853 test verdi** su SQLite e Postgres, 12 guardie provate per mutazione.*
- *`laravel/cashier ^16.7` (`^13` di Illuminate entra in 16.5.0), installato **senza `--with-dependencies`**: 4 pacchetti aggiunti, zero update, `guzzlehttp/guzzle` fermo — la lezione del 19 Ago che si conferma su un secondo caso.*
- *`Cashier::useCustomerModel(Account::class)` e `use Billable`. **Le migration del pacchetto non sono state pubblicate**, e non per gusto: col Billable su `Account`, `ManagesSubscriptions::subscriptions()` risolve `getForeignKey()` in **`account_id`**, quindi quelle del pacchetto — che creano `user_id` e colonne su `users` — sono inservibili, non solo scomode. Tre migration additive nostre, con `accounts.stripe_id` **unique** (vedi sotto il perché) e le colonne `meter_*` che il pacchetto aggiunge nel 2025 già dentro.*
- *`config/cashier.php` **è** pubblicata, per una ragione sola: restringere `webhook.events` ai tre `customer.subscription.*`. `cashier:webhook` non ha un'opzione `--events`, e gli 8 eventi di default trascinerebbero handler del pacchetto che fanno round-trip verso Stripe **dentro** la richiesta del webhook.*
- ***Il limite di Enti vive su `Account::puoAggiungereEnte()`**, non dentro il comando: ADR-032 dice «al provisioning», ma in S6 il provisioning diventa una UI Livewire e una guardia scritta in `handle()` non sarebbe lì. `easylab:provision-tenant` la consuma prima della transazione. **Downgrade = grandfathering**: chi scende di piano tiene tutte le sedi, non ne apre di nuove.*
- *⚠️ **Difetto preesistente trovato attuando**: `Account::enti()` usava `withoutGlobalScopes()` **nudo**, che rimuove anche `SoftDeletingScope` — gli Enti cestinati finivano nel conteggio. Invisibile finché l'unico consumatore era una riga informativa di `easylab:lockout`; con il limite di piano quel conteggio decide se un cliente può aprire una sede, e una sede chiusa mesi fa gliene avrebbe occupata una per sempre. Ora i due scope sono elencati per nome, come chiede la Policy di Code Review.*
- *`accounts.piano` è l'**unica** fonte del piano: nessun percorso lo deduce da `subscribed()`, perché il piano Free non ha subscription da interrogare. Il catalogo (`free` max 1, `saas` max 5) sta in `config/easylab.php` ed è consumato da `App\Support\Piani`, accanto a `Rbac` e `Semaforo`. La **gratuità si dichiara** (`gratuito => true`) e non si deduce dall'assenza del prezzo: un piano a pagamento col price id non configurato è un errore di deploy da segnalare per nome, e la prima stesura li confondeva — l'ha trovato un test.*
- *`AccountPolicy` con ability dal nome **diverso** dal permesso (`manage`, non `billing.manage_own`): il `Gate::before` di spatie concede appena il permesso esiste sul ruolo, quindi un'ability omonima non verrebbe mai raggiunta e l'Admin passerebbe su account altrui. Ramo `billing.manage_global` **prima** dell'appartenenza, o il Superadmin — che ha quel permesso proprio per gli account altrui — avrebbe `manage` falso ovunque. Un guardrail vieta la stringa nuda fuori dalla Policy, cercandola in `app/`, `resources/` **e `routes/`** (dov'è l'idioma `->middleware('can:…')`, dodici occorrenze) e togliendo prima i commenti col tokenizer.*
- *`config/rbac.php` **non è stato toccato**: i cinque permessi billing erano a catalogo da S1. Nessun riseeding, `RbacSeederTest` (54/6/53/7) invariato.*
- *Fuori perimetro e dichiarati: self-signup pubblico (ADR-012), Stripe Billing Portal, UI dei dati fiscali e dashboard MRR (S6).*

**Contesto.** Il requisito è di business: chi paga di più gestisce più Enti, e i piani si tarano su quel numero. Il modello attuale non ci arriva, e non per una dimenticanza ma per tre decisioni esplicite: il `tenant_id` punta all'Ente top-level (🔗 ADR-006), ogni utente è legato a **un solo** Ente senza bypass per nessun ruolo (🔗 ADR-018), e il modello di incasso del 14 Giu 2026 mette **l'Ente** come pagatore del rapporto A. La conseguenza si tocca con mano perfino da console: `easylab:provision-tenant`, rilanciato con l'email di un Admin esistente, **ignora** il secondo Ente invece di agganciarglielo (`User::firstOrCreate` trova l'utente e scarta il `tenant_id` nuovo) — oggi «un utente con due Enti» non è esprimibile nemmeno volendo.

Due forme restavano in gara: **(a)** un'entità *account* sopra l'Ente, intestataria dell'abbonamento, con `tenant_id` invariato ovunque; **(b)** l'utente multi-Ente. La differenza non è di stile. Il contratto della tenancy è `CurrentTenant::id(): ?int` — **un** intero, o niente — ed è il punto singolo da cui dipendono `TenantScope` (compreso il ramo fail-closed `1 = 0`, dove «senza tenant» significa «non vede nulla»), `BelongsToTenant` (che in `creating` timbra **un** `tenant_id` per riga), `AccessibleNodes` e tutti gli scope di livello 2. La forma (b) rompe quel contratto e con esso riapre ADR-018: 131 riferimenti a `tenant_id` in 28 file di `app/`, 215 in 54 file di test, 661 test verdi che affermano l'isolamento nella forma attuale. E c'è un argomento più tagliente del costo: **(b) non evita comunque il concetto di «Ente attivo»** — una riga nuova va pur timbrata con un solo `tenant_id`, quindi anche l'utente multi-Ente avrebbe bisogno di un contesto corrente, cioè del pezzo centrale della (a), *in aggiunta* alla riscrittura dello scoping. Infine spatie gira con `teams = false` (Schema Ruoli §7): i permessi di un ruolo sono globali, e un utente che è Admin in un Ente e Tenant in un altro non è dicibile senza riaprire anche quella scelta.

Il precedente indica la strada: il **Tecnico esterno** (🔗 ADR-030) è già oggi l'unico utente che attraversa legittimamente più Enti, e lo fa **senza toccare `tenant_id`**, con il pivot `tecnico_cliente` che elenca gli Enti a cui ha accesso. È la forma (a) applicata a un caso più piccolo. E l'ERD aveva già prenotato la via d'uscita: la «Scelta documentata» di §4.1 prevede di poter «estrarre una tabella 1-1 col nodo ente senza toccare le altre relazioni». Il billing, intanto, è ancora tutto da costruire: Cashier non è installato e le colonne fiscali/lockout documentate in ERD §4.1 **non esistono a DB** (nessuna migrazione le crea) — si decide dove *nasceranno*, non dove spostarle.

**Decisione.** Si adotta la forma **(a)**: nasce l'**Account**, l'entità che sta sopra l'Ente ed è l'intestatario del rapporto commerciale. Lo scoping non cambia di una riga.

1. **Nuova tabella `accounts`, a livello piattaforma** (fuori dall'albero, come `resellers`), e colonna `account_id` sul nodo `ente` di `unita_organizzativa`. Un account ha **N Enti**; ogni Ente appartiene a **un** account. `tenant_id` resta ovunque quello che è: il confine di isolamento (ADR-006) e il criterio dello scoping (ADR-018) non si toccano.
2. **Il customer Stripe è l'account.** Le colonne Cashier (`stripe_id`, `pm_type`, …) e il trait `Billable` vivono su `accounts` — si chiude l'ambiguità di ERD §9, che diceva «su `users`/nodo ente». Il **limite di Enti è un attributo del piano** e si fa rispettare **al provisioning** (dove un Ente nasce), non nello scope (dove si leggerebbe a ogni query un vincolo che cambia solo quando si compra).
3. **L'appartenenza all'account è un pivot `account_user`** (unique `(account_id, user_id)`), gemello per forma di `tecnico_cliente`: elenca gli utenti che amministrano il rapporto commerciale — alla nascita uno solo, chi ha sottoscritto. Un pivot e non una FK `owner_user_id`, per due ragioni: gli utenti hanno soft delete, e un account non deve morire o cambiare natura perché il suo unico proprietario è stato disattivato (invariante applicativo: **ogni account ha sempre almeno un membro**); e la co-titolarità futura è una riga in più, non una migrazione. `billing.manage_own` resta il permesso, e la condizione «membro dell'account» vive in una **Policy** — la stessa gerarchia di ADR-029: il permesso è la condizione necessaria, la Policy restringe, e nessuno dei due allarga l'altro.
4. **Il passaggio da un Ente all'altro è uno switcher che riscrive `users.tenant_id`**, con la stessa disciplina dell'impersonazione: azione dedicata, consentita solo verso un Ente di un account di cui l'utente è membro, loggata in `activity_log`. Una richiesta vede sempre **un** tenant: `CurrentTenant`, `TenantScope` e il fail-closed restano intatti. Non è il «selettore di tenant» che ADR-018 ha scartato: quello apriva un secondo percorso verso **dati altrui**, questo naviga fra Enti **propri** dello stesso rapporto commerciale — l'isolamento attraversato lì è esattamente quello che qui non esiste. ⚠️ Oggi `tenant_id` è in `$fillable` su `User`: attuando lo switcher va **tolto da lì**, così la riscrittura passa da un solo metodo dedicato — la stessa forma di `fissaVisibilitaGaranzieRicambio()` (ADR-029), e per la stessa ragione: un vincolo che vive solo nella vista è un vincolo che non c'è.
5. **Il lockout (ADR-013) è dell'account.** L'insoluto è del rapporto commerciale, non di una sede: `is_locked`/`locked_at`/`locked_reason` nascono su `accounts` e bloccano **tutti** gli Enti dell'account. La salvaguardia GDPR di ADR-013 (export lato Superadmin) vale invariata, per tutti gli Enti coinvolti.
6. **I dati fiscali (ADR-010) sono dell'account.** `partita_iva`, `codice_fiscale`, `pec`, `codice_destinatario_sdi` nascono su `accounts`: l'intestatario della fattura è chi paga. Il caso «più sedi, fatture separate» si esprime con **più account** — è il prodotto che vende per account, non una colonna duplicata per-Ente.
7. **Onboarding (ADR-012):** il self-signup crea **account + primo Ente + primo membro** in un colpo; il provisioning aggancia un Ente nuovo a un account esistente **o** ne crea uno. `easylab:provision-tenant` si estende con `--account=`; senza opzione mantiene il comportamento 1:1 di oggi (account nuovo). Il `firstOrCreate` sull'email smette di essere un caso muto: email esistente = «aggiungi l'Ente all'account di quell'utente», detto esplicitamente.
8. **Migrazione degli esistenti: additiva, 1:1.** Ogni Ente attuale ottiene un account con lo stesso intestatario e come membro il suo Admin; `account_id` è backfillato, nessuna riga si sposta. È la stessa forma della migrazione `tecnico_cliente`: si aggiunge la relazione, non si riscrive la tenancy.
9. **ADR-029 resta per-Ente.** `visibilita_garanzie_ricambio` è una clausola sul servizio di *quella* sede e non migra sull'account; cambia solo l'intestatario commerciale del rapporto in cui la clausola vive — come la riga 🔗 di quell'ADR già anticipava. Il portafoglio del Tecnico (ADR-030) resta anch'esso com'è: elenca **Enti**, non account, perché il tecnico lavora su sedi, non su contratti.

**Alternative scartate.**
- *(b) Utente multi-Ente:* misurata sopra — rompe il contratto `CurrentTenant::id(): ?int`, riapre ADR-018 (131+215 riferimenti, 661 test), collide con `teams = false`, e finisce comunque per dover costruire l'«Ente attivo». Tutta la spesa della (a), più la chirurgia.
- *(c) Enti gerarchici:* esclusa già dalla conferma del 17 Ago 2026: se il proprietario dell'abbonamento è un utente e non una radice organizzativa, la gerarchia risolve un problema diverso da quello posto — e rompe l'assunto «l'Ente è la radice» su cui poggiano `AccessibleNodes` e tutti i livelli 2.
- *Riusare `resellers`/`reseller_id`:* l'entità che già sta «sopra gli Enti» esiste, ma è un **merchant terzo che incassa in proprio** (rapporto C, Stripe Connect, ADR-002) — l'account è un **cliente con più sedi** che paga EasyLab (rapporto A, Cashier). Piegare l'una sull'altro confonderebbe due modelli di incasso che ADR-002 ha separato apposta; un account resta rivendibile (`reseller_id` sul nodo ente convive con `account_id`).
- *`Billable` su `users`:* l'utente è una credenziale, non un cliente — ha soft delete, può cambiare, e legare l'abbonamento a chi ha fatto il primo login significa perderlo quando quella persona se ne va. Era mezza ambiguità di ERD §9.
- *`Billable` sul nodo ente:* è la scelta implicita di oggi, e con N Enti significherebbe N abbonamenti per lo stesso cliente — esattamente il problema da cui questo ADR nasce.

**Conseguenze.**
- **Schema — attuato il 18 Ago 2026** (le sole colonne Cashier restano al blocco Stripe): tabella `accounts` (dati fiscali, lockout), pivot `account_user`, colonna `unita_organizzativa.account_id` (nullable, backfill 1:1 eseguito; resta nullable perché d'ora in poi la valorizza il provisioning). Tutte migrazioni **additive**. La «Scelta documentata» di ERD §4.1 si scioglie così: la tabella 1-1 lì prenotata diventa l'account — che però è 1-N, ed è tutta la differenza.
- **Lo switcher è l'unico punto nuovo che tocca la tenancy** — non lo scope, ma il dato su cui lo scope si appoggia. Area rossa della Policy di Code Review: servono **test negativi** (il non-membro non switcha; il membro non switcha verso un Ente fuori dai propri account; l'account in lockout non fa entrare nessuno dei suoi Enti), non solo il caso felice.
- **Nessun test esistente cambia affermazione**: le suite lavorano con `actingAs` a tenant fisso per richiesta, e lo switcher avviene *fra* le richieste. È la verifica che la forma (b) non avrebbe mai potuto passare.
- ⚠️ **L'amministrazione degli account è cross-tenant per natura** e quindi, per ADR-018, vive in viste **esplicitamente non-scopate** gate da permessi di piattaforma: la sede è la Dashboard Superadmin di S6, come per il controllo di ADR-029. Fino ad allora, console.
- ⚠️ **`users.is_active` (ADR-012) e il lockout dell'account sono due interruttori diversi** e non vanno fusi: il primo spegne una persona, il secondo un contratto. Il middleware di lockout deve guardare l'account dell'Ente corrente, non l'utente.
- 🔗 Chiude i rimandi di **ADR-029** (l'intestatario dell'impostazione è l'account) e **ADR-030** (il portafoglio riletto: resta per-Ente). Dà a **ADR-010/012/013** l'intestatario che aspettavano; **ADR-002** resta intatto, coi rivenditori dall'altra parte del confine.
- Il nome del ruolo `Tenant` comincia a stare stretto — indica la persona di *un* Ente, mentre «tenant» resta l'Ente stesso (ADR-006). Non si rinomina nulla oggi: è un debito di nomenclatura, annotato qui perché il prossimo lettore non lo scambi per un concetto nuovo.

---

**ADR-033 — Il primary è il blu del marchio; il teal dello Sprint 0 decade**

*Stato: Accettata (21 Ago 2026) — decisa da Marco dopo la consegna dei file di brand in `docs/Design/assets/`. **Documentale, non ancora attuata**: `docs/Design/Design System Base.md` §2 e §6 e il campione `docs/Design/design-system.html` sono allineati al blu; `resources/css/app.css` porta ancora i token teal. Il restyling è un intervento a parte. Nessuna decisione precedente viene superata: ADR-005 (semaforo) e ADR-014 (obsolescenza) restano validi alla lettera, e i loro colori non cambiano di un grado.*

**Contesto.** Il Design System nasce allo Sprint 0 (task S0.4) quando il logo non esisteva ancora, e sceglie un primary **teal** `#0D9488` per ragioni di tono — «affidabile, lab/medicale». Il 20 Ago 2026 arrivano i file di marchio definitivi: `Logo-EasyLab.svg` è **blu**, costruito su due soli colori, `#06589C` e `#2997D4`, legati da un gradiente sulla curva della «y». Da quel momento l'applicazione e il proprio marchio sono di due colori diversi, e il documento che dovrebbe essere il contratto dei colori descrive un colore che il brand non usa.

**Decisione.** La scala `primary` si deriva dal marchio, con `primary-400` e `primary-600` **ancorati agli hex reali del file** e non interpolati. Il teal esce dal progetto. Tutto il resto della palette — semaforo, neutri, accenti — resta invariato: cambia l'identità, non il significato degli stati.

**Perché non solo una sostituzione di hex.**
- **`info-500` non può restare un secondo blu.** Nasceva `#2563EB` perché col brand teal un blu informativo non somigliava a niente. Col brand blu i due sarebbero **simili senza essere uguali**, che è il caso peggiore: chi guarda non sa se la differenza voglia dire qualcosa. Il token resta — `x-ui.badge` espone la variante `info` — ma diventa un alias di `primary-600`. *Si toglie un colore, non se ne aggiunge uno.*
- **`primary-400` non è un colore da testo**: su bianco fa 3.24:1. È il colore del marchio, e serve a bordi, riempimenti dei grafici e testo su fondo scuro. Il gradiente d'identità non va mai dietro a un paragrafo.
- **I grafici usano un gradino diverso dal badge** (§2.5 del DS): un riempimento di superficie deve staccarsi dal fondo di almeno 3:1 e il gradino chiaro non ci arriva. Stessa famiglia, gradino scelto per il lavoro.

**Conseguenze e trappole.**
- ⚠️ **Nessun test si accorgerà mai di questa decisione**, né della sua attuazione, né di una sua attuazione sbagliata: la suite non guarda i colori. La verifica è **visiva e manuale**. È la stessa forma dell'errore già pagato due volte dal progetto — le migration non applicate al DB di sviluppo, `config/rbac.php` non riseminato: *ciò che non vive nello schema non viene allineato da un comando.*
- ⚠️ **Fino all'attuazione, `app.css` e il Design System dicono due cose diverse, e lo dicono apposta.** Lo scarto è dichiarato in testa al DS. Chi lo incontra non ha trovato un bug; chi lo «corregge» di sorpresa fa un restyling non verificato.
- **Il PDF dello storico non è toccato**: ha colori scritti a mano (dompdf non vede Tailwind), ma sono neutri più `#15803d` e `#b91c1c` — nessun primary. Verificato il 21 Ago 2026. Le viste di autenticazione usano `primary-50/600/700`, cioè token, e si ridipingono da sole.
- **Due difetti trovati misurando, non guardando**, corretti nel DS con la stessa decisione: il **placeholder era a `neutral-400`** (2.56:1 su bianco, sotto AA) e va a `neutral-500`; la scala neutra era **rada**, e `app.blade.php` usava già un `text-neutral-500` inesistente nel tema, che ricadeva in silenzio sulla scala di default di Tailwind. Un token assente non dà errore: dà un colore diverso.
- **Il §6 del DS mostrava un `tailwind.config.js`** rimasto dallo Sprint 1 e mai aggiornato al passaggio a Tailwind v4 CSS-first. Chi lo avesse seguito alla lettera avrebbe creato un file che Tailwind ignora, senza nessun errore.
- 🔗 **`components/brand-logo.blade.php` punta ancora al logo vecchio** (`public/brand/easy-lab-continuity.svg`) e non è usato da nessuna parte: `app.blade.php` disegna a mano un'icona a becher. Il file nuovo va in `public/brand/` e il layout va agganciato al componente. ⚠️ Un `<img src>` **non** eredita le variabili CSS della pagina: o il logo si inlinea, o servono due file per i due fondi.
