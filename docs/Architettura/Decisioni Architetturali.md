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

*Stato: Accettata. **Integrata da ADR-032** (18 Ago 2026): il self-signup crea **account + primo Ente + primo membro** in un colpo; il provisioning aggancia l'Ente a un account esistente o ne crea uno (`easylab:provision-tenant --account=`). **Metà provisioning attuata lo stesso giorno** (invito via email + set password). **Dal 21 Ago 2026 la dipendenza è sciolta**: Cashier c'è, quindi la verifica del pagamento — condizione d'ingresso del self-signup — è finalmente esprimibile. **Metà self-signup attuata il 28 Ago 2026**: il flusso pubblico esiste, e le sue note stanno qui sotto.*

***Deroga dichiarata — il Superadmin (19 Ago 2026).*** *Questa ADR ha tolto di mezzo la consegna di password, e il `SuperadminSeeder` ne è l'unica eccezione: è l'account che deve poter entrare **quando non esiste ancora nessuno che possa invitarlo**, come già il Developer. Il prezzo è una password in variabile d'ambiente, e per contenerlo il seeder **senza `SUPERADMIN_EMAIL`/`SUPERADMIN_PASSWORD` non crea nulla** invece di ripiegare su un default noto — che è l'errore che il Developer si porta dietro da S0. Nasce insieme al proprio Account e al proprio Ente perché il TenantScope è fail-closed (🔗 ADR-018): un utente con `tenant_id` NULL entrerebbe e troverebbe l'app vuota. Le credenziali si leggono da `config/easylab.php` e mai da `env()` nel seeder: su Cloud `optimize` gira in build e con la config cachata ogni `env()` fuori da `config/` torna null — è così che su staging il Superadmin non veniva creato.*

***Note di attuazione della metà provisioning (18 Ago 2026):***
- *Il link d'invito è una **URL firmata temporanea** (7 giorni) su una pagina dedicata, **non** il flusso di reset password di Fortify: quello esiste già stilizzato, ma il broker `users` scade in **60 minuti** e Fortify valida i token con quello — un invito morto in un'ora non è un invito. È il precedente del QR (🔗 ADR-003), con `signed` davanti a tutto perché manomettere id o scadenza invalidi l'URL **prima** del route-model binding.*
- *Lo stato «invitato» **non è una colonna**: è `email_verified_at IS NULL` con una password random di 64 caratteri mai comunicata. Il click sul link e la scelta della password **sono** la verifica della casella. ⚠️ **`users.is_active` non è mai nata** (l'ERD la documentava come se esistesse): un terzo stato sarebbe stata la terza sorgente di verità su «questo utente può entrare?». L'euristica regge finché `Features::updateProfileInformation()` resta spenta — è l'unico percorso che potrebbe azzerare `email_verified_at` a un utente vero, e va rivisto quando la si accenderà.*
- *L'invito parte **fuori dalla transazione**: una mail spedita per una transazione poi rollbackata manda qualcuno su un link che non porta a nulla. **Reinviare = ripetere lo stesso gesto** su chi non ha ancora attivato: nessun comando in più.*
- *⚠️ **Sincrono fino al 21 Ago 2026, poi in coda** — e la riga di prima diceva l'opposto, con l'argomento che «in console un worker può non esserci, e una notifica accodata direbbe *inviato* a un invito fermo in Redis». **Quell'argomento resta vero**, ed è il motivo per cui il ribaltamento è arrivato con l'infrastruttura e non prima: una **managed queue** su Cloud (`QUEUE_CONNECTION=cloud`), che il chiamante non deve indovinare.*

  *Ciò che l'ha ribaltato è un fatto che il ragionamento originale non aveva: da S6 il provisioning si fa **da una pagina web**, e l'**HTTP timeout dell'ambiente è di 20 secondi**. La transazione committa prima dell'invio, quindi un handshake SMTP lento produce **un cliente creato e una risposta persa** — e l'operatore non sa se ripetere il gesto. In coda, l'SMTP esce dal percorso della richiesta, e i tentativi diventano tre invece di uno.*

  *Il prezzo è dichiarato e pagato in tre punti. **(a)** Chi accoda non vede più il fallimento, quindi nessun chiamante dice più «inviato»: dicono «**in consegna**», che è vero in entrambi i mondi — e il campo dell'esito si chiama `invitoAccodato` apposta, perché rinominarlo ha costretto ogni chiamante a riscrivere la propria frase invece di ereditarne una diventata falsa. **(b)** `InvitoUtente::failed()` scrive **destinatario**, ente e motivo su `activity_log` e sul log applicativo: senza il destinatario la riga direbbe che un invito è morto senza dire a chi vada rimandato. ⚠️ **Gap dichiarato**: finché la vista Audit di S6 non esiste, `activity_log` è nella stessa condizione di `failed_jobs` — una tabella senza lettore — e il canale davvero consultabile è il log dell'ambiente. **(c)** `$tries` e `$backoff` stanno **nella classe**, non fra i flag del worker nel pannello di Cloud: un'impostazione che vive solo lì non sta in nessun file e non passa da una revisione.*

  *⚠️ **Due modi di sbagliare, entrambi muti.** Il primo: `QUEUE_CONNECTION` fra le variabili **custom** di Cloud vince su quella iniettata dalla managed queue, che resta a non ricevere niente (Setup §2.1.1) — si verifica eseguendo `config('queue.default')` sull'ambiente, che deve dire `cloud`. Il secondo: `QUEUE_CONNECTION=redis` **in locale senza un worker acceso**, dove il push riesce, la pagina dice «in consegna» e la mail resta in coda per sempre. Era la configurazione di sviluppo fino a quel giorno, e il `.env` è ora su `sync`.*

  *⚠️ **Non spostare l'invito su una coda dedicata** (`onQueue('interattivi')` o simili) senza creare prima la managed queue corrispondente: ogni managed queue processa **una sola** coda — la nostra è `default` — e un job accodato altrove non verrebbe preso da nessuno. La convivenza col digest (🔗 ADR-011), che accoda una notifica per destinatario, è nota e accettata: oggi lo scheduler su staging è spento, e il giorno che si accende un invito fatto durante il giro notturno aspetta in fila.*
- *Nessun auto-login dopo il set: l'Admin è un ruolo 2FA-required, e la catena invito → password → login → 2FA è il flusso giusto.*
- *⚠️ Difetto latente emerso attuando: `email_verified_at` **non è nel `Fillable`** di `User`, quindi il `firstOrCreate` del provisioning lo scartava in silenzio — ogni Admin creato restava non verificato contro l'intenzione del codice. Ora si scrive con `forceFill`, come `tenant_id`.*

***Note di attuazione della metà self-signup (28 Ago 2026):***
- *🔴 **L'account nasce solo se il pagamento è riuscito** — la condizione d'ingresso posta da questa ADR — e vive in una classe sola, `App\Support\Registrazione\CompletaRegistrazione`. I chiamanti sono **due** e arrivano da mondi diversi: il ritorno del browser da Stripe e il webhook `checkout.session.completed`. Il secondo esiste perché il primo **non è garantito** — chi chiude la scheda dopo aver pagato non torna mai, e senza il webhook avrebbe pagato per niente — e la ragione dell'unica implementazione è che due strade per un solo gesto, al primo cambiamento, diventano due gesti diversi, e uno è quello che nessuno guarda. La transazione «account + primo Ente + primo Admin» **non è stata riscritta**: è `ProvisionaEnte`, chiamata con `esigiAccountNuovo: true` — senza, un'email già amministratrice di un altro account (compreso quello di piattaforma) farebbe atterrare l'Ente appena pagato sul contratto di un terzo.*
- *⚠️ **La domanda sul piano è più larga DOPO l'incasso che prima**, e non è una svista. Al checkout vale `PianiRegistrabili::accetta()`, che esclude gli archiviati; al completamento basta `Piani::esiste()`, che li comprende. Prima dell'incasso il fail-closed non costa niente a nessuno — si torna indietro e si sceglie un altro piano; dopo, rifiutare significherebbe **aver incassato senza consegnare**, e per un gesto compiuto da noi (archiviare un piano da `/piattaforma/piani`, 🔗 ADR-035) mentre il cliente era sulla pagina di Stripe.*
- ***Un controller e non un componente Livewire, per il rate limiting.** `throttle` è un middleware **di rotta** e ogni update Livewire passa da `/livewire/update`: un form Livewire avrebbe la protezione sul solo GET iniziale, cioè non l'avrebbe — è la forma già pagata con `two-factor.enforce`, che ADR-018 documenta come non persistente sugli update. Il limiter `registrazione` ha **due chiavi, IP ed email**: la sola IP si aggira con un proxy, la sola email cambiando indirizzo. Sopra c'è un **honeypot** che, se pieno, risponde con un **successo apparente**: un rifiuto esplicito insegnerebbe al bot come passare.*
- ***Anti-enumerazione**: un indirizzo che appartiene già a un utente produce **la stessa identica risposta** di uno nuovo e nessuna riga a database; cambia solo cosa arriva nella casella (`AccountGiaEsistente`), che è raggiungibile dal solo proprietario. Le due risposte escono da **una sola funzione**, e il fatto che sia una sola è la difesa: due `return` scritti a mano divergerebbero al primo ritocco del messaggio, e la divergenza sarebbe di nuovo un modo di sapere chi è cliente di Easy Lab.*
- *La **verifica della casella è obbligatoria e precede il pagamento**, ed è presidiata due volte — in `RegistrazionePubblica::versoStripe()` e di nuovo in `CompletaRegistrazione` — perché il webhook non passa dalla prima. Tutti i passi intermedi sono **URL firmati**: la firma copre id e scadenza e cade *prima* del route-model binding, quindi da lì non si enumerano le registrazioni pendenti (è il precedente dell'invito, qui sopra, e del QR — 🔗 ADR-003).*
- *🔴 **Un'appropriazione di account, trovata dai cacciatori il 28 Ago 2026.** Il riuso della riga pendente sovrascriveva `password_hash` **senza azzerare la verifica della casella**: Mario si registra e clicca il link, la sua email risulta verificata; un terzo che conosce solo l'indirizzo ripete il modulo con la **propria** password, la riga viene riusata, la verifica di Mario resta valida. Mario paga, e l'account nasce con l'hash dell'attaccante, in ruolo Admin. Entra lui; Mario no. La verifica era presidiata in un punto solo, **già superato**. Ora `rigaPendentePer()` riusa **solo righe non verificate**, e la seconda registrazione ottiene una riga propria che nessuno ha verificato.*

  *⚠️ **E la riga nuova non è un dettaglio d'implementazione.** La correzione «alternativa» — azzerare la verifica sulla riga esistente — sarebbe stata una leva di dispetto: chiunque conosca l'indirizzo potrebbe revocare la verifica a qualcuno che è **già sulla pagina di Stripe**, e un pagamento incassato resterebbe senza account. L'azzeramento c'è comunque, come difesa in profondità, ed è **dichiarato ridondante** nel commento: oggi non accende nessuna mutazione, ma il giorno in cui qualcuno allargasse il riuso il peggio possibile tornerebbe a essere «la vittima deve verificare di nuovo» invece di «un terzo ha sostituito la password».*
- *🔴 **Il blocco è stato consegnato una prima volta senza un solo test**, e il commit che lo consegnava dichiarava la suite verde senza che una sola di quelle asserzioni lo toccasse: `tests/Feature/Registrazione/` era una **cartella vuota**, la finta del checkout non era referenziata da nessuno, e un guardrail citava per nome un file che non esisteva. Misurato dal cacciatore: si poteva cancellare la riga «l'account nasce solo se il pagamento è riuscito» e la suite restava verde. È la stessa lezione di CLAUDE.md sull'asserzione che non può fallire, salita di scala dall'asserzione all'intero blocco.*
- *⚠️ **Un pagamento incassato e poi rifiutato** lasciava traccia nel solo `laravel.log`, cioè su un disco che il progetto stesso descrive come effimero e azzerato a ogni deploy — e a 30 giorni la potatura portava via anche la riga pendente: incasso avvenuto, nessun account, e **niente da cui accorgersene**. Ora `registraIlRifiuto()` scrive nel registro di audit, ed è per questo che `SoggettiAudit` ha una voce `Registrazione`: etichettata sul **`nome_ente` e mai sull'email**, perché quel registro si legge con `tenants.view_all` e l'indirizzo di chi ha solo *tentato* di registrarsi è il dato personale di una persona che cliente non è ancora.*
- *⛔ **Nessuna riga di audit per l'avvio e per la verifica**, e non è una dimenticanza: non avrebbero nessun **causer** (chi compila il modulo non esiste nel dominio) e per essere utili dovrebbero portare l'email di chi potrebbe non diventare mai cliente, in una tabella che una retention non ce l'ha (T6). La traccia dell'avvio **è la riga `registrazioni`**, che si pota da sé a 30 giorni.*
- *L'interruttore `easylab.registrazione.aperta` **spegne solo l'ingresso**, e con un **404 e non un 403**: un 403 dichiarerebbe che la pagina c'è. I passi successivi restano aperti apposta — chi ha già pagato deve poter completare, e chiudergli la porta significherebbe aver incassato senza consegnare.*
- *⚠️ **Niente è stato provato su Stripe vero**: `PortaleCheckout` è un'interfaccia e in suite risponde `tests/Fakes/PortaleCheckoutFinto`; `PortaleCheckoutStripe` non ha mai parlato con l'API.*
- *L'esenzione di `Registrazione` dall'isolamento per tenant è una decisione a sé e ha la sua voce: 🔗 **ADR-036**.*

**Decisione.** Convivono due flussi di ingresso:
- **Provisioning gestito** da EasyLab/Admin per i clienti "Free / chiavi in mano" (creazione Ente + utente admin, invito via email, set password).
- **Auto-registrazione pubblica (self-signup)** per il tier SaaS self-service (registrazione + attivazione abbonamento autonoma).

**Conseguenze.** Auth scaffold con registrazione pubblica abilitata ma protetta (verifica email + verifica pagamento prima dell'attivazione del tenant). Servono flussi anti-abuso/spam sul signup pubblico. Il provisioning richiede un flusso di invito utente.

---

**ADR-013 — Blocco per insoluto: lockout totale**

*Stato: Accettata. **Integrata da ADR-032** (18 Ago 2026): l'insoluto è dell'**Account** — `is_locked`/`locked_*` nascono su `accounts` e il lockout blocca **tutti** gli Enti dell'account. La salvaguardia GDPR qui sotto vale invariata, per tutti gli Enti coinvolti. **Attuata il 18 Ago 2026 nella parte manuale + enforcement, e il 21 Ago 2026 nell'innesco automatico** (blocco Cashier).*

***⚠️ Nota del 28 Ago 2026 — «totale» non è più vero alla lettera, e la deroga è voluta (🔗 ADR-035, decisione di Marco del 27 Ago: dal Billing Portal il cliente disdice da solo).*** *`/abbonamento` e `/abbonamento/portale` vivono **fuori** dal gruppo protetto, accanto a `/bloccato` e per collocazione e non per un'esclusione `routeIs`: un account bloccato per insoluto deve poter **pagare**, o la leva di questa ADR resta senza scatto di rilascio. Il prezzo di quella porta aperta è dichiarato, ed è stato pagato in quattro punti — tre dei quali erano difetti veri, trovati dai cacciatori e non da chi aveva scritto il codice.*
- *⛔ **Lì non c'è nessun `can:` di rotta**, e nemmeno potrebbe esserci: con `teams = false` un permesso `billing.*` nudo concederebbe su **ogni** account della piattaforma, e non c'è route-model binding su cui appendere `can:manage,account`. L'autorizzazione (`manage` sull'Account, 🔗 ADR-032) si **riscrive** dentro il componente **e** dentro il controller: un POST arriva senza passare dalla pagina che lo offre, quindi chi non è membro non vede il bottone ma può costruire la richiesta. Il bottone non è la guardia, e il test negativo copre il POST e non solo la GET.*
- *🔴 **In impersonazione il portale non si apre.** `Gate::authorize('manage', …)` risponde sull'utente della guard, e lab404 **sostituisce** quell'utente: dentro un'impersonazione la guardia rispondeva sull'impersonato, diceva di sì, e la sessione di portale si apriva sul customer del **cliente**. Riprodotto il 28 Ago 2026 con un 302 verso una sessione intestata a «Cliente Moroso» — e con la disdetta abilitata nel portale quella sessione permette di **disdire l'abbonamento del cliente** e di cambiargli il metodo di pagamento. Il bypass in impersonazione previsto qui sopra è la salvaguardia GDPR — assistere ed esportare — non il conto del cliente presso un terzo: il gesto di assistenza commerciale si compie dalla dashboard di Stripe, dove già vivono le chiavi e i log del fornitore.*
- *🔴 **La pagina usa il `guest-layout`, e non è una scelta estetica.** Il layout dell'applicazione monta tre componenti Livewire (switcher, campanella, selettore tema), e su questa pagina sarebbero una **fuga dal lockout**: Livewire scrive nel `memo` dello snapshot il **path** della richiesta che l'ha generato e da lì ricostruisce i middleware persistenti, quindi uno snapshot preso su `/abbonamento` — che sta nel gruppo `auth` nudo — ricostruisce `['web','auth']` e `EnforceAccountLockout` **non gira**. Misurato il 28 Ago 2026: `segnaTutteLette` su `/livewire/update` rispondeva **200** con lo snapshot preso da `/abbonamento` e **302 verso `/bloccato`** con quello preso da `/dashboard`. L'esenzione è «un bloccato può **pagare**», non «un bloccato può usare tutto ciò che il layout monta»; con questo layout resta un solo componente in pagina, che non ha azioni, e un guardrail strutturale li conta. Costo dichiarato: niente top bar, quindi la pagina si porta da sé il link di ritorno — lo stesso patto di `/bloccato`.*
- *La pagina **non mostra `locked_reason` né `stripe_lock_reason`** — restano annotazioni operative interne, come già su `/bloccato` — e **non mostra prezzi**: le cifre vere stanno nel portale, e stamparle qui significherebbe affermare al cliente quanto paga senza saperlo. Il bottone «Regolarizza il pagamento» su `/bloccato`, che ingoiava ogni errore in silenzio perché il flash non era reso da nessuna parte, ora lo mostra: sul percorso in cui un moroso cerca di pagare, un fallimento invisibile è la peggior forma di guasto.*

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
- *⚠️ **La lista degli eventi va difesa nel controller, non nel comando** (21 Ago 2026, trovato verificando su staging). `config('cashier.webhook.events')` dice a `cashier:webhook` quali eventi **registrare su Stripe**; non filtra nulla in ingresso. Su staging l'endpoint era stato creato **a mano** dalla dashboard, in ascolto su **241 tipi** invece di tre: la restrizione era carta straccia, e sarebbero arrivati anche gli eventi che gli handler ereditati da Cashier gestiscono per conto loro — `customer.updated` e `payment_method.automatically_updated` fanno un round-trip verso Stripe **dentro** la richiesta, e `customer.deleted` azzera `stripe_id`, staccando l'account da ogni futuro controllo sull'insoluto. Ora `handleWebhook()` scarta i tipi fuori elenco con un 200 e nessun effetto. La lezione è dove stava la guardia: in un comando che qualcuno può non lanciare, invece che nel codice che riceve. Un elenco vuoto non filtra — un webhook che ignora tutto in silenzio sarebbe di nuovo un lockout che non scatta mai.*
- *🧪 **Due difetti trovati dal giro end-to-end su staging (21 Ago 2026), che nessun test aveva visto** — perché i test erano scritti sul flusso immaginato, dove un cliente disdice e basta:*
  - *⚠️ **`customer.subscription.created` non sbloccava.** Chi disdice e torna a pagare produce un `created`, non un `updated`: l'account restava **bloccato e sul piano `free` mentre pagava**, che è il peggior esito possibile per questo blocco. Ora `created` e `updated` passano dalla stessa mappa stato→gesto: è la stessa domanda, e due strade separate divergerebbero al primo cambiamento.*
  - *⚠️ **`easylab:abbona` rifiutava di riabbonare un cliente tornato.** La guardia era `subscriptions()->exists()`, che vede anche una subscription `canceled` — la quale non fattura nulla, quindi non c'è niente da proteggere. Ora esclude le sole `canceled`: resta il rifiuto su `past_due`/`incomplete` (il caso per cui la guardia esiste, e che `subscribed()` lasciava passare), e il cliente che torna passa.*
  *Entrambi provati per mutazione. La lezione è la stessa del filtro eventi: il giro vero interroga il sistema su domande che chi ha scritto il codice non si è posto.*
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
- **Set "bloccato" protetto.** Un sottoinsieme di permessi è **non modificabile dalla UI** perché vincolato da privacy/legge/sicurezza o strutturale. La UI li mostra in sola lettura.

  🔴 **«Bloccato» è una proprietà del PERMESSO, non della coppia ruolo-permesso** *(esplicitato il 22 Ago 2026, attuando l'editor)*. L'elenco qui sotto nomina i ruoli che oggi *hanno* ciascuna voce, e quella prosa si è prestata alla lettura sbagliata — «bloccato per quel ruolo». Non è così: una voce 🔒 non è **né revocabile né concedibile, a nessun ruolo**. La firma scritta in S1 lo diceva già (`Rbac::isLocked(string $permission)` prende un permesso, non una coppia); l'argomento decisivo però non è qui, è nel middleware del secondo fattore, che si gata **per nome di ruolo** (`two_factor_required_roles`): sotto la lettura per coppia, `roles.manage` sarebbe concedibile al `Tenant`, e si otterrebbe un editor della matrice dei permessi raggiungibile **senza 2FA obbligatorio**. Conseguenza da accettare: le 7 voci non saranno mai concedibili a un ruolo che oggi non le ha — il giorno in cui ne nascesse uno nuovo servirebbe un commit, non un click.

  Elenco bloccato (V1, **7 voci** dal 15 Ago 2026):
  - ~~`garanzie.ricambio.view` / `garanzie.ricambio.manage`~~ → 🔴 **USCITE dal set il 15 Ago 2026** (🔗 ADR-029), che le rende un'impostazione **per-Ente** a tre stati. Il set passa da 9 a **7** voci, e il Tenant ha ora entrambi i permessi. *Questa riga ha continuato a dire «mai concedibili al Tenant» fino al 22 Ago 2026, cioè per una settimana la decisione che governa l'editor dei permessi diceva il contrario della matrice che l'editor avrebbe modificato.* (Storia precedente, che si conserva perché è la stessa forma d'errore due volte: corretta l'8 Ago 2026 da ADR-027, quando diceva «a `Tenant` o `Tecnico`» — un divieto al Tecnico mai deciso, e poi congelato da un test.)
  - `utenti.impersonate` → solo `Developer`/`Superadmin`.
  - `system.logs.view` → solo `Developer`.
  - `billing.manage_global`, `billing.lockout`, `tenants.view_all`, `tenants.provision` → solo `Developer`/`Superadmin`.
  - `roles.manage` → solo `Developer`/`Superadmin` (chi gestisce la matrice non può auto-delegarla).
- **La UI tocca solo il "cosa" (permesso), mai il "su quali righe" (scope).** Lo scope (isolamento `tenant_id`, sotto-albero Responsabile, unione Tecnico) resta nel codice (Global Scope/Policy) e **non** è configurabile (🔗 ADR-001/006/007).

**Modello dati.** Nessuna tabella nuova: si usano le tabelle spatie. Il set bloccato è una **costante applicativa** (config/codice), non un dato editabile. Ogni modifica alla matrice va loggata in `activity_log` (chi/cosa/quando).

> ✅ **Attuazione, 22–23 Ago 2026 — S6, cinque blocchi** (`App\Support\Rbac\MatriceRuoli` per la regola, `/piattaforma/ruoli` per la pagina). Cinque cose che questa decisione non diceva e che l'attuazione ha dovuto stabilire:
>
> **(a) La riga del `Developer` è inerte in entrambe le direzioni.** Il set bloccato non la copre: `Developer => ['all' => true]` è la chiave di riserva della piattaforma e con l'editor diventerebbe svuotabile permesso per permesso. Non esiste un `Gate::before` da super-admin che la rimpiazzi. Precedente di forma: `User::canBeImpersonated()` — una definizione sola, mai due elenchi.
>
> **(b) Un permesso fuori catalogo non si riassegna.** `permissions` sopravvive alle proprie definizioni — il seeder usa `firstOrCreate` e non cancella — quindi senza guardia l'editor potrebbe **riassegnare** una riga orfana: è l'incidente `letture_contaore.*`, rimasto su quattro ruoli fino all'8 Ago 2026, ma con l'editor a legittimarlo invece che a isolarlo. Stessa guardia sui ruoli fuori catalogo, che non avrebbero né scope di riga né 2FA obbligatorio.
>
> **(c) 🔴 Il riseeding NON fa deriva: distrugge.** `RolesAndPermissionsSeeder` usa `syncPermissions()`, che **detacha tutto e riattacca** dalla config. Dal rilascio dell'editor in poi, l'istruzione di CLAUDE.md «le modifiche a `config/rbac.php` vanno riseminate» — eseguita correttamente — **cancella l'intera matrice di runtime**. Il seeder resta il reset ai default (un reset che non resetta è peggio), ma stampa il diff di ciò che sta per distruggere. ⚠️ E **non** chiede conferma: un `confirm()` lì dentro rompe le **91 classi di test** che seminano — su un DB appena creato i ruoli non esistono, quindi il diff non è vuoto, è massimo.
>
> **(d) La traccia è una riga per cella**, sul canale `audit` e con il **ruolo** come soggetto (il permesso sta nelle proprietà): il gesto è «al ruolo X è stato tolto Y», ed è sul ruolo che si vorrà filtrare. Senza `event`, quindi la riga è un **atto** e la si raggiunge dalla sentinella del registro. Un delta di riga intero nelle proprietà si renderebbe come JSON grezzo, che è il difetto che il dettaglio del registro esiste per evitare.
>
> **(e) La divergenza config↔DB si rende in pagina, e «diff non vuoto» non è la condizione giusta.** Dal rilascio, `config/rbac.php` smette di essere la verità e diventa il default: le celle in cui le due sorgenti non concordano portano in pagina il marcatore «personalizzato» **coi due valori affiancati**, con un interruttore che le isola — a zero query, perché la config è PHP puro e la matrice è già in memoria. È il confronto ruolo-per-ruolo che CLAUDE.md chiedeva a mano prima di riseminare. ⚠️ E il seeder distingue il **reset** dal **bootstrap**: su un database appena creato i ruoli non esistono, quindi il diff è *massimo*, e stampare o tracciare «quando il diff non è vuoto» significherebbe scrivere una riga di audit a ogni `RefreshDatabase` della suite. La condizione è «**c'era qualcosa da distruggere**»: contano solo i ruoli che esistevano prima dell'esecuzione. La riga di audit del reset nasce **senza causer** — è un gesto del comando — e chiude l'unico punto cieco della feature: il recupero d'emergenza era il solo gesto invisibile nel registro.

**Conseguenze.**
- Flessibilità operativa senza deploy; default sensati out-of-the-box.
- Le guard del set bloccato vanno testate. ⚠️ **Il test negativo che stava qui — «il Superadmin non può concedere `garanzie.ricambio.view` al Tenant via UI» — è falso dal 15 Ago 2026**: quel gesto oggi è lecito. I negativi giusti sono «non può concedere `roles.manage` all'Admin» (che è ciò che distingue la lettura per colonna da quella per coppia: un'implementazione che blocca le sole revoche resta verde su tutto il resto) e «non può revocare `tenants.view_all` al Superadmin», che se cadesse chiuderebbe fuori dalla piattaforma chi la governa.
- Aggiungere un nuovo permesso resta un'operazione di codice (catalogo §4 + seeder); la UI gestisce l'assegnazione, non la creazione di permessi.
- Nuovo permesso `roles.manage` da aggiungere al catalogo e alla matrice.

> ⚠️ **Rischio dichiarato e accettato — l'impersonazione entra via GET senza CSRF** *(21 Ago 2026, S6 blocco F)*
>
> `lab404/laravel-impersonate` espone `GET /impersonate/take/{id}`. Una rotta GET non porta token CSRF, quindi una pagina esterna può far **navigare** lì un Superadmin già connesso — un `<img src>` o un redirect bastano — e la sessione entra in impersonazione senza che l'interessato abbia cliccato nulla di riconoscibile.
>
> **Perché si accetta, per ora.** L'attaccante **non legge la risposta** (same-origin), quindi non estrae dati: ottiene che la vittima si trovi dentro una sessione altrui. Il gesto è **loggato** e il **banner è visibile in cima a ogni pagina** — e da questo blocco dice anche **chi** sta impersonando, che è ciò che rende l'anomalia riconoscibile a colpo d'occhio invece che ricostruibile a posteriori. La superficie è ristretta a due soli ruoli (`utenti.impersonate`).
>
> **Perché non si chiude qui.** Chiuderla vuol dire o cambiare il metodo della rotta del pacchetto (e riscrivere il proprio controller), o metterle davanti un middleware di conferma: entrambe toccano un pacchetto di terze parti e vanno fatte con la revisione delle autorizzazioni **già in elenco nel security pass di S7**, non di sfuggita dentro un blocco di UI.
>
> ⚠️ **Il rifiuto del pacchetto non è un 403.** `ImpersonateController::take()` non aborta quando `canBeImpersonated()` è falso: cade fuori dall'`if` e fa `redirect()->back()`. Con `take_redirect_to => '/'` e nessun referer, **successo e rifiuto sono risposte identiche**. Chi scrive un test negativo qui deve asserire sullo **stato della sessione** (`isImpersonating()` e l'utente autenticato), non sul codice di stato: un `assertForbidden()` fallirebbe, e la via di minor resistenza per farlo passare sarebbe allargare `canBeImpersonated()` — cioè rompere la guardia per accontentare il test.

---

> ⚠️ **Attuazione in UI — due interruttori, e uno solo si tocca** *(21 Ago 2026, S6 blocco G)*
>
> La cabina di regia mostra i due lockout come **due badge distinti** e li filtra **separatamente**. Fonderli in un badge unico o in un filtro «bloccato» rifarebbe in interfaccia il difetto che la separazione delle due colonne esiste per impedire: chi cerca gli insoluti non vuole trovare chi è chiuso per contenzioso, e riaprire un contenzioso non deve far rientrare chi non ha pagato.
>
> **Il blocco Stripe è in sola lettura.** `sbloccaPerStripe()` non è esposto in nessuna superficie, e non è una dimenticanza: il suo inverso è un **evento di pagamento**. Un umano che dichiarasse «ha pagato» verrebbe smentito dal webhook successivo, e nel frattempo il cliente sarebbe rientrato senza pagare. Un guardrail cerca quella chiamata sui **token** di tutto `app/`, con un'allowlist nominata sul solo `StripeWebhookController` — e verifica che il file in allowlist la contenga davvero, così la voce non resta un permesso aperto su qualcosa che non esiste più.
>
> **`locked_reason` si legge nella cabina**, mai su `/bloccato`. La pagina che il cliente vede resta muta di proposito; il motivo serve a chi riapre il caso mesi dopo, ed è per questo che è **obbligatorio** al momento del blocco. Un lockout senza motivo diventa un cliente dimenticato.

---

**ADR-017 — Error tracker interno: le eccezioni si catturano in casa e si salvano a database, dietro il gate del solo Developer**

*Stato: Accettata (24 Ago 2026) — **scritta alla fine dell'attuazione, non prima**, e la roadmap la citava già da mesi (riga 113) come destinazione della casella «Sentry/Flare» saltata. Registra decisioni che si sono chiuse fra il quarto e l'ottavo blocco di lavoro: scriverla in apertura sarebbe stato mettere agli atti ciò che non si sapeva ancora. Attuata in S6, 23–24 Ago 2026: `/piattaforma/errori`, due tabelle, `model:prune` già schedulato, alert email.*

**Contesto.** Quando qualcosa si rompe in produzione **non c'è nessun posto in cui guardare dall'applicazione**. L'unica traccia è `laravel.log`, e su Laravel Cloud (🔗 ADR-025) quel file vive su un disco **effimero**, **per-replica**, azzerato a ogni deploy e a ogni risveglio da scale-to-zero. Un errore visto da un cliente può non lasciare nulla di consultabile — e su staging non c'è nemmeno una shell da cui andarlo a cercare.

**Decisione.**

- **Build, non buy.** Niente Sentry né Flare. Non è una questione di prezzo (Sentry ha un piano gratuito): un servizio esterno riceverebbe **i nostri errori**, che per costruzione contengono messaggi, input di richiesta e identificativi di utenti dei clienti. Diventerebbe un **sub-responsabile ex art. 28**, con DPA da negoziare, riga nel registro dei trattamenti, elenco sub-processor da aggiornare e dati fuori dal perimetro UE che il resto dell'architettura difende. Per un progetto che ha già un DPA aperto e non firmato (Backblaze, 🔗 Privacy §3) aggiungerne un secondo per un servizio *interno di diagnostica* è il verso sbagliato. Il costo di costruirlo è un blocco di lavoro; il costo di adottarlo è permanente e contrattuale.
- **A database, non su file.** Le eccezioni si scrivono in due tabelle (`errori`, `occorrenze_errore`) e non in un file di log strutturato. Il database gestito è **l'unico store condiviso e persistente** dell'ambiente: sopravvive al deploy, è lo stesso per tutte le repliche, non sparisce quando l'app scala a zero, e si interroga da una pagina invece che da una shell che su staging non c'è. Un file per-replica direbbe *metà* verità a chi lo apre — che è peggio di nessuna, perché sembra completa.
- **Una riga per punto d'origine, non per avvenimento.** `errori` raggruppa per `impronta` = `sha1(classe + primo frame applicativo + frame chiamante)`, con percorsi **relativi** alla radice del progetto. Due frame e non uno: con il solo punto d'origine, un `throw` dentro un helper chiamato da cento posti diventerebbe **una** issue per cento cause diverse. Percorsi relativi perché su Cloud ogni release vive in una directory diversa, e con quelli assoluti ogni deploy azzererebbe il raggruppamento.
- 🔴 **Il tracker è il SECONDO canale, mai il primo.** `laravel.log` continua a ricevere tutto: la cattura è agganciata a `$exceptions->report()`, restituisce `void` e **non sopprime** la catena (`Handler::reportThrowable()` si ferma solo su un `false` esplicito). Il verso opposto — un tracker che *sostituisce* il log — lascerebbe **zero tracce ovunque** proprio nel caso peggiore, cioè quando è il database a essere irraggiungibile. Per la stessa ragione la cattura non lancia mai: `try/catch (\Throwable)` nudo, e nel catch `error_log()` e non `Log::`.
- **La lista degli errori ignorati si eredita, non si copia.** I callback di `report()` girano *dopo* `shouldntReport()`, quindi validazione, autenticazione, autorizzazione, 404 e affini non arrivano mai al tracker senza che noi si scriva un elenco destinato a divergere. ⚠️ Conseguenza che sorprende: `HttpException` copre **tutta** la famiglia, quindi un `abort(500)` deliberato **non** viene tracciato.
- 🔴 **La sanificazione avviene in scrittura, mai in lettura** (🔗 Privacy §3): denylist sulle chiavi di input (`App\Support\ChiaviSensibili`, che estende quella del registro di audit con `code` e `recovery_code` — i campi della schermata 2FA), stack trace **ricostruito dai frame senza gli argomenti**, percorso salvato come **schema di rotta** e non risolto (questo progetto ha `reset-password/{token}` e `q/{token}`). Un filtro applicato alla pagina lascerebbe il dato in chiaro nella riga, cioè dietro il gate che nessuno può ispezionare.
- **Le occorrenze sono un campione, non un registro**: al più 20 per issue e non più di una al minuto, con il budget che **si azzera alla riapertura**. Senza l'azzeramento, dopo un tentativo di correzione la issue sarebbe già al tetto e non catturerebbe mai più la prova che serve a rispondere a «l'ho corretto, perché succede ancora?».
- **Gate: `system.logs.view`, del solo Developer**, permesso già esistente e **nel set bloccato** di 🔗 ADR-016 — l'editor della matrice non può darlo né toglierlo a nessuno.
- **Alert email su issue nuova o riapertura automatica**, in coda, con tetto giornaliero globale, destinatario da `config('easylab.errori.alert_email')` e ripiego sull'email del Developer **letta dalla config e mai dal database**. 🔴 **Senza dettaglio dentro**: classe, `file:riga`, conteggio e link, e nient'altro.
- **Retention applicata, non dichiarata**: occorrenze 90 giorni, issue chiuse 90, aperte 180, `ignorato` mai potato; e **il `messaggio` si oscura a 180 giorni in qualunque stato**. Tutto dal `model:prune` **già schedulato**, nessun comando nuovo.

**Conseguenze.**

- 🔴 **Un lettore solo, e ciò che ne discende.** `system.logs.view` è del **solo Developer**: il Superadmin — che ha ogni altro permesso di piattaforma — la pagina non la può nemmeno aprire. È il **primo caso in questo progetto in cui le due partizioni non coincidono**, ed è deliberato: gli errori portano dati di richiesta di clienti reali, e chi fa assistenza commerciale non ne ha bisogno.
  - *Chi controlla il controllore:* le tre azioni sulle issue (risolvi/ignora/riapri) lasciano una riga nel **registro di audit**, che si legge con `tenants.view_all` — cioè dal Superadmin. Ne discende un vincolo che nessuno indovinerebbe e che un test negativo congela: **l'etichetta del soggetto è la `classe`, mai il `messaggio`**, perché il messaggio è interpolato e filtrerebbe attraverso un gate che agli errori non dà accesso.
  - ⚠️ **E non è una supervisione permanente**: `activity_log` non ha rotazione attiva (T6 è aperta col legale) mentre `errori` sì, quindi dopo 90–180 giorni la riga di audit sopravvive al proprio soggetto e l'etichetta degrada a «Errore · #123». Le `properties` continuano a portare la `classe`, quindi la riga resta leggibile — ma va detto invece di lasciar credere il contrario.
  - **Se il Developer non c'è, nessuno guarda.** Il canale che resta è lo stream dei log di Cloud, che è una **credenziale d'infrastruttura**, non un permesso.
- ⚠️ **In uso quotidiano il tracker è l'email, e l'email esce dal gate.** Nessuno apre `/piattaforma/errori` a caso: ci si arriva perché è arrivata una notifica, e quella notifica va a **una casella** — che non ha né permesso né registro di audit. Da qui il vincolo sul contenuto (niente stack trace, niente input, niente identità di chi ha subito l'errore) e l'obbligo che `ERRORI_ALERT_EMAIL` sia una **casella controllata**, elencata fra gli autorizzati di T8 come lo sarebbe una persona.
- ⚠️ **Spegnere il tracker richiede un REDEPLOY, e va saputo prima di averne bisogno.** `ERRORI_ABILITATO=false` è l'interruttore d'emergenza, ma in produzione la config è **cotta in build** (`php artisan optimize`): con la config in cache Laravel non rilegge più `.env`, quindi cambiare la variabile dal pannello non ha effetto finché non riparte una build. È un interruttore **lento**, e chi lo cerca lo cercherà nel momento sbagliato per scoprirlo. *(La stessa meccanica ha già morso questo progetto due volte: il Superadmin che su staging non veniva creato, e il Developer nato con la password di default — 🔗 ADR-012.)*
- 🔴 **Il `messaggio` si oscura dopo 180 giorni dall'ultima occorrenza, in QUALUNQUE stato** *(deciso il 24 Ago 2026, ultimo blocco)*. Il messaggio è **interpolato** («Utente 42 non trovato»): è l'unica colonna di `errori` che possa portare un dato riferito a una persona, e su una issue `ignorato` — che non si pota mai, perché potarla la farebbe **rinascere** al primo avvenimento successivo con l'alert al seguito — vivrebbe senza scadenza. Il rimedio non cancella la riga: **la riga sopravvive, il messaggio si svuota**. Restano classe, file, riga, impronta e contatori, cioè cosa si rompe, dove e quante volte — che basta a riconoscere l'errore e non riguarda nessuno.
  - **Non è ristretto a `ignorato`, e il motivo è misurato**: `ultima_occorrenza_at` si rinfresca a *ogni* avvenimento, quindi una issue **aperta che continua a ripetersi non viene mai potata comunque**, senza che nessuno tocchi niente. Un filtro per stato avrebbe coperto la casella rara lasciando aperta la frequente.
  - **Il posto è la potatura che già gira** (`Errore::pruneAll()`, dentro il `model:prune` schedulato), non un comando nuovo: un `errori:oscura-messaggi` «da schedulare al deploy» sarebbe stata la forma esatta del difetto **T6** — una misura scritta nel registro dei trattamenti e mai avvenuta.
  - **Si distingue «oscurato» da «vuoto» con una sentinella** (`Errore::MESSAGGIO_OSCURATO`) e non con `null` né con una colonna nuova. Il vuoto esiste davvero — la cattura scrive `''` quando l'eccezione non porta messaggio — quindi svuotare a `''` renderebbe indistinguibile «non c'era niente» da «gliel'abbiamo tolto». Una colonna `messaggio_oscurato_at` direbbe anche *quando*, ma costerebbe una migration per un dato che nessuna schermata userebbe, e sarebbe visibile solo a chi sa di doverla guardare: la sentinella si legge **ovunque il messaggio si legga già** — nella scheda, in una `psql`, in un dump.
  - ⚠️ **Resta una lacuna dichiarata**: non esiste alcuna azione di **cancellazione** di una issue. Per una richiesta ex art. 17 la via è l'intervento a database.
- ⚠️ **Fuori perimetro, e vale la pena scriverlo perché sono le cose che qualcuno cercherà**: visore di `laravel.log` (disco effimero: mentirebbe per omissione), cruscotto operativo (la dashboard di Cloud lo fa già), errori JavaScript (un endpoint pubblico è una superficie a sé), performance/breadcrumbs/source map, ricerca full-text sui messaggi (testo libero con dati personali), export, alert su Slack o webhook, e **aprire la pagina al Superadmin** — che sarebbe un commit su `config/rbac.php` più un riseeding, non un click.

---

**ADR-018 — Nessun ruolo bypassa il Global Scope: Superadmin/Developer tenant-bound, accesso cross-tenant solo via impersonazione**

*Stato: Accettata (29 Giu 2026) — supera il punto di ADR-001 secondo cui Superadmin/Developer bypassano lo scope. **Attuata la vista aggregata il 21 Ago 2026** (S6): la cabina di regia `/piattaforma` esiste e non è un'eccezione a questa decisione, è la sua applicazione — **una porta sola e nominata**, `App\Support\Tenancy\VistaPiattaforma`, che toglie `TenantScope` e `DepartmentScope` **per nome** (mai nudi: il nudo porta via anche il soft delete) e chiede `tenants.view_all`. Nessun builder di piattaforma nasce fuori di lì, e un meta-test vieta di aggiungerne di nudi. Il Superadmin resta tenant-bound in **ogni altra** schermata, e l'accesso ai dati di un cliente resta l'impersonazione. ⚠️ L'ERD §3 ha continuato a dire «bypass del Global Scope» fino a quel giorno, cioè per due mesi: corretto lì.*

**Contesto.** ADR-001 prevedeva che Superadmin (EasyLab) e Developer **bypassassero** il Global Scope, vedendo i dati di *tutti* i tenant in un'unica vista (motivazione: dashboard globali/MRR). All'atto pratico questo significa che il fornitore vede di default i dati dei clienti — debole sul piano GDPR (minimizzazione/necessità) e fonte di confusione UX (Enti di clienti diversi mescolati nella stessa schermata). Chiarimento del modello di business: EasyLab **non possiede** gli Enti dei clienti; ogni Ente è un tenant a sé col proprio Admin.

**Decisione.**
- **Nessun ruolo bypassa il Global Scope.** Le schermate operative del **Superadmin** e del **Developer** si comportano **esattamente come quelle di un Admin**: vedono solo i dati del proprio tenant (`tenant_id`). Anche Superadmin e Developer hanno un **proprio Ente**.
- **Accesso cross-tenant = solo impersonazione.** Per vedere/operare i dati di un altro utente, Superadmin/Developer **impersonano** (lab404). Il Developer resta l'unico account **non impersonabile** (🔗 [[impersonation-only-developer-protected]]) e con tutti i permessi.
- **Scoping fail-closed.** Un utente **autenticato senza `tenant_id`** non vede **nulla** (prima vedeva tutto). Contesto senza utente (console/seeder/job) resta non scopato. Questo chiude anche il buco del **Tecnico** (`tenant_id` null): vedrà nulla finché S4 non implementa l'accesso per unione portafoglio∪assegnazione (🔗 ADR-007).
- **Dashboard globali/MRR (S6).** Le viste aggregate di piattaforma (clienti totali, MRR, ecc.) usano query **esplicitamente non-scopate** (`withoutGlobalScopes`) gate da permessi di piattaforma — **non** un bypass di sessione. Idem per il selettore utenti dell'impersonazione.

**Alternative scartate.**
- *Mantenere il bypass di sessione (ADR-001 originale):* più semplice ma espone i dati clienti al fornitore di default e mescola i tenant in UI.
***Nota di attuazione (21 Ago 2026) — la vista aggregata prevista esiste, e ha una porta sola.***
- *`App\Support\Tenancy\VistaPiattaforma` è la forma concreta dell'eccezione che questa ADR prevede: `accounts()`/`enti()`/`strumenti()`, gate su `tenants.view_all`, scope tolti **per nome**. Una classe e non un bypass per schermata, perché l'elenco nominativo della Policy di Code Review si era già derivato due volte — e con N builder sparsi la guardia è N volte da ricordare.*
- *⚠️ **La porta è solo per contesti HTTP autenticati.** `Gate::authorize()` nega sempre senza utente, quindi in console, nei job e nello scheduler **lancia**. È la simmetria giusta e non un limite: là `TenantScope` non si applica già, quindi la query nuda è la forma corretta. `NotificaScadenze` resta fuori e porta il commento che lo dice — farla passare di qui manderebbe lo scheduler notturno in `AuthorizationException`, e il sintomo sarebbe un digest che non arriva.*
- *⚠️ **I builder che consegna sono scrivibili**: `Builder::update()` non emette eventi, quindi gli hook di `BelongsToTenant` non girano e un update di massa toccherebbe ogni cliente dietro un permesso che si chiama `.view_all`. Un meta-test vieta di concatenare scritture alla porta.*
- *⚠️ **Come si difende davvero una vista di piattaforma** (corretto il 21 Ago 2026, dopo che il primo docblock diceva il contrario). Il `can:` di rotta **non ferma solo chi digita l'URL**: `Illuminate\Auth\Middleware\Authorize` è fra i middleware **persistenti** di Livewire, quindi viene riapplicato anche sugli update — il pacchetto rilegge `memo.path`, rimatcha la rotta e rigira `auth`, `account.lockout` e il `can:`. `porta()` dentro `VistaPiattaforma` gata invece **le letture**, ma vive dentro `render()`, che gira **dopo** l'azione e con `skipRender()` non gira affatto. Conseguenza pratica per i blocchi successivi: per un'azione che scrive, la guardia che regge è quella di **rotta**, e l'`authorize()` per-oggetto serve perché il permesso dice «puoi stare in questa pagina», non «puoi toccare questo account». La prima stesura archiviava il `can:` come ridondante: era l'opposto.*
- *⚠️ `Livewire::test()` **non prova** quella strada — il pacchetto disabilita i middleware per le richieste finte, e lo dichiara nel proprio sorgente. Serve un POST reale a `/livewire/update`, come già faceva `LockoutEnforcementTest` per ADR-013. **`two-factor.enforce` non è persistente**: il 2FA non parla sugli update Livewire di nessuna pagina del progetto. È preesistente, ed è la prima volta che viene scritto.*
- *L'**impersonazione chiude la porta da sé**: lab404 sostituisce l'utente della guard, quindi il Gate legge i permessi dell'impersonato. Un test lo congela — senza, sarebbe la prima cosa che qualcuno «aggiusterebbe» trovando un 403 inatteso. Resta non documentato altrove un caso di confine: un Superadmin che impersona un altro Superadmin **tiene la porta aperta**, ed è accettabile perché entrambi sono proprietari di piattaforma.*

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
- **"Tracciato" non significa ancora "visibile".** La vista Audit è in S6: fino ad allora i dati si accumulano e si leggono solo dal database. Va detto a chi si aspetta di vederli subito. ✅ *In corso dal 22 Ago 2026: la porta e la pagina gatata esistono, la tabella arriva nei blocchi successivi.*
- 🔴 **Ogni evento di autenticazione era registrato DUE volte, da S1** *(trovato il 22 Ago 2026 guardando la vista Audit su **staging**, non in locale)*. Laravel **scopre da sé** i listener in `app/Listeners`, registrando ogni metodo che comincia per `handle` e ha un evento come parametro tipizzato: con la mappa esplicita di `AuditLogSubscriber::subscribe()` **più** la scoperta automatica, il subscriber risultava iscritto due volte. Login, logout, login falliti, 2FA e impersonazioni hanno prodotto **righe doppie** per mesi.
  - ⚠️ **Nessun test poteva accorgersene**, e la forma dell'errore è quella che vale la pena ricordare: tutte le asserzioni cercavano **una** riga — `first()`, `->exists()`, `latest()` — e una riga c'era. Un test che chiede «esiste?» non risponde mai a «quante?». Ora ce ne sono quattro che contano.
  - Rimedio: i metodi non si chiamano più `handleXxx` ma `suXxx`, così la scoperta non li vede e resta la sola mappa esplicita. Fra le due strade — togliere la mappa e affidarsi alla scoperta, o tenerla e sottrarsi alla scoperta — si è scelta la seconda: un elenco di otto eventi si legge e si rivede, mentre la scoperta è implicita e si romperebbe al primo metodo rinominato senza che nulla lo dica. Un meta-test vieta di reintrodurre il prefisso.
  - ⚠️ **Lo storico resta doppio**: l'audit non si riscrive. Chi conterà eventi di autenticazione su righe precedenti al 22 Ago 2026 deve saperlo.
- ⚠️ **Il registro attribuiva a chi non aveva agito, e nessuno poteva accorgersene** *(trovato e corretto il 22 Ago 2026, mentre si costruiva la vista che lo avrebbe mostrato)*. `CauserResolver` legge `auth()->guard()->user()`, e lab404 **sostituisce** quell'utente: ogni riga scritta durante un'impersonazione — comprese **tutte** quelle del trait, cioè la maggioranza del volume — nominava il **cliente** per un gesto di EasyLab. Non è un difetto di presentazione: è un dato falso in un registro di sicurezza, e su una tabella che questa stessa ADR dichiara non riscrivibile il danno è **cumulativo**.
  - Rimedio: **un hook solo**, `LogActivityAction::beforeLogging()` in `AppServiceProvider`, che aggiunge `properties.impersonato_da`. Gira su ogni attività prima del salvataggio, quindi copre le diciotto scritture esplicite **e** il trait senza toccare un solo call site — e senza diventare una regola da ricordare per ogni scrittura futura.
  - Scartata l'alternativa in sola lettura (ricostruire le finestre dalle coppie «Impersonation avviata/terminata», che avrebbe coperto anche lo storico): una sessione chiusa per **timeout** non scrive mai la riga di chiusura, quindi la finestra resterebbe aperta per sempre e il registro comincerebbe ad attribuire a EasyLab gesti che non sono suoi. In un registro di sicurezza un'attribuzione euristica è **peggio** di una mancante.
  - Tre limiti dichiarati, tutti con un test che li congela: non copre le scritture **fuori da una richiesta** (code, console, webhook — non c'è sessione da interrogare, e `InvitoUtente` è già in coda); **non timbra** la riga «Impersonation avviata», che si auto-attribuirebbe (`causer_id === impersonato_da`) e inquinerebbe il filtro «azioni compiute in impersonazione» con ogni apertura mai avvenuta; e **lo storico precedente non è recuperabile** — la vista dichiara la data da cui l'attribuzione è affidabile, `AuditLog::ATTRIBUZIONE_AFFIDABILE_DA`.
  - Il callback è avvolto in `rescue()`: è l'unico codice del progetto che sta sul **percorso di scrittura di tutto**, dentro la transazione del chiamante. Se un giorno `session()` lanciasse non si perderebbe una riga di audit — si annullerebbe la scrittura di business. L'eccezione viene riportata, non ingoiata.
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

*Stato: Accettata (9 Ago 2026) — **supera il vincolo di privacy di ADR-004** (e con esso la voce corrispondente del set 🔒 di ADR-016). **Attuata il 15 Ago 2026** (S4 blocco 5) e **completata il 21 Ago 2026** (S6 blocco G): l'impostazione si cambia ora **per ogni sede della piattaforma** dalla cabina di regia, chiudendo la consegna che l'attuazione aveva lasciato aperta — il Superadmin è tenant-bound (🔗 ADR-018) e dal form dell'anagrafica ne vedeva un Ente solo, quindi per tutti gli altri si passava dalla console. Il gate è **`roles.manage`** e non `unita_organizzativa.update`, che ce l'ha anche l'Admin dell'Ente: è una clausola del rapporto commerciale, non un'impostazione di anagrafica. ⚠️ *La prima consegna della `select` era muta — mostrava sempre «Nascoste» perché la colonna non era nel `select` della query, e il default vero è «modifica»: avrebbe detto che un cliente non vede le garanzie mentre le vedeva e le modificava. Trovato dal confronto fra due agenti, non da un test: tutti i test asserivano sul database.* Attuandola sono emerse due cose che la decisione non poteva prevedere: il vincolo di scrittura **non è esprimibile con un permesso** (spatie concede prima che l'impostazione sia letta) ed è finito nella **prima Policy del progetto**; e un permesso nudo rimasto in una vista — la Panoramica — scavalcava l'impostazione, trovato da un test e non rileggendo il codice.*

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
- **La UI del portafoglio è arrivata il 30 Ago 2026 con 🔗 ADR-038**, separata dall'editor dei permessi: `/piattaforma/tecnici`, gata su `tenants.view_all`, crea i tecnici EasyLab e assegna loro le sedi. Il pivot, lo scope e l'audit restano quelli nati qui; la pagina rende finalmente amministrabile la relazione senza cambiarne la semantica.
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

***Note di attuazione — cabina di regia S6 (21 Ago 2026):***
- *Il **listino** vive in `config/easylab.php` accanto a `max_enti` (`prezzo_mensile_cent`, centesimi interi): l'MRR è **derivato**, non una colonna. È il listino e non l'incassato — lo stesso prezzo vive su Stripe, i due possono divergere e **nulla in questo repository può accorgersene**, perché il price id sta nell'ambiente e l'importo in config. La sola guardia possibile sarebbe un comando che legge il Price e confronta importo e valuta; oggi non esiste, ed è la ragione per cui la dashboard dirà «a listino» in pagina.*
- *`accounts.di_piattaforma` distingue **EasyLab dai suoi clienti**: senza, la cabina conterebbe se stessa. Il backfill della migration risale dalla **struttura** (ruolo Superadmin → Ente → account) e non dal nome, perché il confronto per ragione sociale — provato sul database di sviluppo — non trovava nulla: lì l'account si chiama «EasyLab (piattaforma)» mentre la config dice «EasyLab». Mancato per un suffisso, in silenzio, proprio dove serviva.*
- *Il filtro sta **nella porta** (`VistaPiattaforma::accounts()`), non nei chiamanti: delegarlo a ogni KPI significherebbe ricordarlo N volte, che è la ragione per cui la porta esiste. Chi ha bisogno di vedere anche la piattaforma usa `accountsInclusaPiattaforma()`, che lo dichiara nel nome.*

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

*Stato: Accettata (21 Ago 2026), **ATTUATA il 26-27 Ago 2026** col restyling (branch `restyling/design-system`, 🔗 `docs/Roadmap/Restyling UI — Roadmap Operativa.md`). Il teal è uscito dal progetto: `resources/css/app.css` porta gli undici gradini del blu, con `primary-400` e `primary-600` ancorati agli hex reali del logo. Verificato **a schermo** — che è la sola verifica che questa decisione ammette: `/login` rende il bottone primario `rgb(6,88,156)`, cioè lo stesso blu della «Easy» del marchio. Nessuna decisione precedente è stata superata: ADR-005 (semaforo) e ADR-014 (obsolescenza) restano validi alla lettera, e i loro colori non sono cambiati di un grado.*

**Contesto.** Il Design System nasce allo Sprint 0 (task S0.4) quando il logo non esisteva ancora, e sceglie un primary **teal** `#0D9488` per ragioni di tono — «affidabile, lab/medicale». Il 20 Ago 2026 arrivano i file di marchio definitivi: `Logo-EasyLab.svg` è **blu**, costruito su due soli colori, `#06589C` e `#2997D4`, legati da un gradiente sulla curva della «y». Da quel momento l'applicazione e il proprio marchio sono di due colori diversi, e il documento che dovrebbe essere il contratto dei colori descrive un colore che il brand non usa.

**Decisione.** La scala `primary` si deriva dal marchio, con `primary-400` e `primary-600` **ancorati agli hex reali del file** e non interpolati. Il teal esce dal progetto. Tutto il resto della palette — semaforo, neutri, accenti — resta invariato: cambia l'identità, non il significato degli stati.

**Perché non solo una sostituzione di hex.**
- **`info-500` non può restare un secondo blu.** Nasceva `#2563EB` perché col brand teal un blu informativo non somigliava a niente. Col brand blu i due sarebbero **simili senza essere uguali**, che è il caso peggiore: chi guarda non sa se la differenza voglia dire qualcosa. Il token resta — `x-ui.badge` espone la variante `info` — ma diventa un alias di `primary-600`. *Si toglie un colore, non se ne aggiunge uno.*
- **`primary-400` non è un colore da testo**: su bianco fa 3.24:1. È il colore del marchio, e serve a bordi, riempimenti dei grafici e testo su fondo scuro. Il gradiente d'identità non va mai dietro a un paragrafo.
- **I grafici usano un gradino diverso dal badge** (§2.5 del DS): un riempimento di superficie deve staccarsi dal fondo di almeno 3:1 e il gradino chiaro non ci arriva. Stessa famiglia, gradino scelto per il lavoro.

**Conseguenze e trappole.**
- ⚠️ ~~**Nessun test si accorgerà mai di questa decisione**~~ — **non è più vero dal 26 Ago 2026.** Restava
  vero finché nessuno costruiva la rete; il restyling ne ha costruite tre (🔗 DS §8.5). Oggi
  `PaletteGuardrailTest` verifica che ogni tonalità **e ogni token semantico** usati siano definiti,
  `TemaScuroGuardrailTest` che nessun token esista in un tema solo, e
  `SuperficiTokenizzateGuardrailTest` che **nessuna vista** dell'intero repository torni a una classe
  di scala. ⚠️ **Ciò che resta vero è la metà che conta**: nessuna di quelle reti guarda un *valore*.
  Verificano che il token esista e sia definito nei due temi, non che sia il colore giusto. **La
  verifica del valore resta visiva e manuale**, ed è la stessa forma dell'errore già pagato due volte
  dal progetto: *ciò che non vive nello schema non viene allineato da un comando.*
- ⚠️ **Fino all'attuazione, `app.css` e il Design System dicono due cose diverse, e lo dicono apposta.** Lo scarto è dichiarato in testa al DS. Chi lo incontra non ha trovato un bug; chi lo «corregge» di sorpresa fa un restyling non verificato.
- **Il PDF dello storico non è toccato**: ha colori scritti a mano (dompdf non vede Tailwind), ma sono neutri più `#15803d` e `#b91c1c` — nessun primary. Verificato il 21 Ago 2026. Le viste di autenticazione usano `primary-50/600/700`, cioè token, e si ridipingono da sole.
- **Due difetti trovati misurando, non guardando**, corretti nel DS con la stessa decisione: il **placeholder era a `neutral-400`** (2.56:1 su bianco, sotto AA) e va a `neutral-500`; la scala neutra era **rada**, e `app.blade.php` usava già un `text-neutral-500` inesistente nel tema, che ricadeva in silenzio sulla scala di default di Tailwind. Un token assente non dà errore: dà un colore diverso.
- **Il §6 del DS mostrava un `tailwind.config.js`** rimasto dallo Sprint 1 e mai aggiornato al passaggio a Tailwind v4 CSS-first. Chi lo avesse seguito alla lettera avrebbe creato un file che Tailwind ignora, senza nessun errore.
- ✅ ~~`components/brand-logo.blade.php` punta ancora al logo vecchio~~ — **fatto il 22 Ago 2026**. Il marchio è in `public/brand/` in **due varianti**, e la scelta è di leggibilità: `easylab-logo.svg` col claim, `easylab-logo-compatto.svg` senza. Il compatto è il default perché quasi ovunque il logo sta in poche decine di pixel — nella sidebar 28 — e lì «GESTIONE STRUMENTAZIONE E MANUTENZIONE» sarebbe alto due pixel: rumore, non informazione. Il completo va sulla pagina di accesso, la prima superficie che vede chi non conosce ancora il prodotto. I due SVG del marchio precedente sono stati **rimossi**.
  - `app.blade.php` non disegna più l'icona a becher accanto al testo «Easy Lab»: il logo porta già il nome, e affiancarglielo lo direbbe due volte. *(L'icona a becher resta dov'è legittima: è quella della voce di menù «Strumenti».)*
  - ⚠️ **`<img>` e non SVG inline, e qui è lecito.** L'avvertenza sulle variabili CSS vale per un logo che debba **ricolorarsi**; questo non deve — i suoi due blu e il gradiente sulla «y» sono il marchio, non un token, e restano quelli su qualunque fondo. Vive su superfici bianche (sidebar, card di autenticazione); su un fondo scuro servirebbe comunque un secondo file, non una variabile.
  - ⚠️ **Il claim è testo trasformato in tracciati**, quindi invisibile a uno screen reader: la pagina di accesso tiene un `h1` in `sr-only`, o resterebbe senza intestazione. L'`alt` porta il solo nome — ripetere il claim lo farebbe annunciare a ogni pagina.
  - 🛡️ *Due test lo tengono onesto, e nascono da un fatto: un `<img src>` verso un file assente **non rompe niente** — la pagina risponde 200 e mostra un rettangolo vuoto. È lo stato in cui il progetto è stato per due mesi, e nessun test poteva accorgersene. Ora i percorsi si estraggono dal sorgente del componente e si verifica che i file esistano.*

---

**ADR-034 — Il tema chiaro e scuro: due strati di token, e la scelta dell'utente sopra quella del sistema**

*Stato: Accettata (26 Ago 2026) — decisa nel restyling che attua ADR-033, e attuata insieme a esso. Nessuna decisione precedente viene superata: ADR-005 (semaforo) e ADR-014 (obsolescenza) restano validi alla lettera, e §4 del Design System — colore **+ forma + etichetta** — diventa qui più importante, non meno.*

**Contesto.** Fino al 26 Ago 2026 l'applicazione ha un tema solo, e il Design System ne descrive uno solo. Il campione visivo `docs/Design/design-system.html`, però, ne dimostra **due**: una scala di token identica nei due temi e uno strato semantico che cambia, con i gradini scuri **scelti per il fondo scuro e non ottenuti invertendo il chiaro**. Quel tema scuro è dichiarato nel campione stesso come «una proposta: il DS del prodotto non ne definisce uno e l'app non lo espone». Questa ADR lo adotta.

**Decisione — 1: due strati di token, e solo il secondo cambia col tema.**

- **Le scale** (`primary`, `neutral`, semaforo, accenti, grafici) sono la palette e **non cambiano mai**: sono le stesse in chiaro e in scuro.
- **I token semantici** (`--surface`, `--ink`, `--border`, `--brand`, `--ok-dot`…) dicono *a quale gradino della scala attinge ogni ruolo*, e sono **gli unici** che il tema riscrive.
- Le viste usano **solo** i semantici: `bg-surface`, non `bg-white`; `text-ink`, non `text-neutral-800`.

⚠️ **Il motivo per cui non si usa la variante `dark:` di Tailwind.** La strada ovvia — `bg-white dark:bg-neutral-900` su ogni superficie — sono **~1.190 varianti** da scrivere e da tenere allineate a mano su 50 file. E la prima che si dimentica **non dà errore**: dà testo nero su fondo nero, cioè lo stesso identico sintomo — pagina 200, markup giusto, colore assente — di tutte le altre trappole di colore già pagate da questo progetto. La scelta è la stessa già fatta per `tabella-a-card` in S4: *la duplicazione non si gestisce, non si crea*.

**Decisione — 2: `data-theme` sull'`<html>`, a tre stati.** Assente = **segue il sistema operativo**; `"light"` e `"dark"` = scelta esplicita dell'utente, che vince sul sistema. Il chiaro è il `:root` di base: non esiste un blocco `[data-theme="light"]` con i propri valori, esiste solo un `:not([data-theme="light"])` che impedisce alla media query di sovrascrivere una scelta esplicita di chiaro.

**Decisione — 3: il DB è la verità, `localStorage` è la cache che evita il lampo.** La preferenza vive in `users.tema` (`sistema|chiaro|scuro`).

- **Utente autenticato**: il layout rende `data-theme` **dal server**. Zero lampo, zero JavaScript nel percorso critico.
- **Ospite** (accesso, reset, invito, `/bloccato`): uno script **inline e sincrono** nel `<head>` legge `localStorage`; se non trova nulla non scrive l'attributo, e decide il sistema.
- **Al cambio**: Livewire scrive il DB, Alpine scrive attributo e `localStorage` nello stesso gesto.

*Il verso è quello giusto: chi entra da un dispositivo nuovo ritrova la propria scelta, e un tablet condiviso in laboratorio non impone a tutti quella dell'ultimo che l'ha toccato.* **Il costo, dichiarato:** uno schermo già aperto altrove non si aggiorna da solo — lo fa al login successivo. È accettato: la preferenza di tema non è un dato di dominio, e un giro di sincronizzazione costerebbe più di quanto vale.

**Conseguenze e trappole.**

- ⚠️ **La superficie di verifica raddoppia.** Ogni pagina va guardata due volte, e non è un adempimento: molti difetti del tema scuro **esistono solo lì** — un bordo che sparisce, un'ombra che diventa un buco nero, un velo di modale che non stacca più. Nessuno di questi rompe un test.
- 🔴 **Un token definito solo nel chiaro non dà errore: resta chiaro sul fondo scuro.** È la stessa forma delle trappole di ADR-033 («un token assente non dà errore, dà un colore diverso»), e per questo la decisione arriva **con la propria rete**: `TemaScuroGuardrailTest` verifica che i due blocchi dichiarino lo stesso insieme di nomi, e `SuperficiTokenizzateGuardrailTest` che nessuna vista usi più una tonalità di scala per una superficie o per il testo.
- ⚠️ **`color-scheme` va dichiarato**, o scrollbar, `<select>`, date picker e campi nativi restano chiari sul fondo scuro. È l'unica riga che parla al browser invece che alla pagina.
- ⚠️ **Lo script degli ospiti deve essere inline e sincrono.** Un `defer`, un file esterno o un `DOMContentLoaded` producono il lampo bianco — che è il difetto del campione stesso, il cui toggle gira a fine documento. Si copia l'architettura, non quel dettaglio.
- ⚠️ **`primary-400` non è un colore da testo su fondo chiaro** (3.24:1) **ma lo diventa su fondo scuro**, ed è infatti il `--brand` del tema scuro. Non è una contraddizione con ADR-033: è la stessa regola letta dal lato giusto — il contrasto è una relazione fra due colori, non una proprietà di uno.
- 🔴 **In tema scuro i colori dei grafici scendono a ΔE 6,9 fra verde e arancione**, sotto la soglia di sicurezza (DS §2.5), contro 9,9 in chiaro. È **legittimo solo perché** ogni voce porta anche glifo ed etichetta: §4 del Design System, che in chiaro è una buona pratica, in scuro è **ciò che rende leggibile il grafico**. Toglierla romperebbe l'accessibilità, non l'estetica.
- **Il marchio non si ricolora**: è un `<img>`, e un `<img>` non eredita le variabili CSS della pagina. Sul fondo scuro serve un **secondo file**, in cui il blu profondo **si alza** a `primary-200` invece di invertirsi. Lo diceva già ADR-033 fra le proprie conseguenze.
- **Tre superfici restano su token di scala, di proposito**, e le reti le esentano per nome: il **banner di impersonation** (DS §5.8) — è un allarme persistente e deve avere lo stesso identico aspetto nei due temi, o smette di essere lo stesso segnale; il **toast**; e la **stampa**, che `@media print` riporta al chiaro qualunque cosa dica `data-theme`.
- **Il PDF e le email non hanno un tema** e restano chiari: dompdf non vede Tailwind (ADR-031), e nessun client di posta ha un tema affidabile.
- ⚠️ **Il numero delle sezioni del Design System non cambia**: il tema scuro entra come **§8**, in coda. §1–§7 sono citati per numero dai docblock del codice.

---

**ADR-035 — Il listino dei piani si governa dalla dashboard, ed è la dashboard a scriverlo su Stripe**

*Stato: **Attuata (27–28 Ago 2026)**, decisa da Marco il 27 Ago. **Supera** la nota di attuazione di ADR-012/032 «nessun select dei piani nel provisioning, ed è una decisione»: quella nasceva dall'assenza di un listino governabile, non da un principio. Non tocca ADR-002 (solo il rapporto A: incassa EasyLab, niente Stripe Connect in V1).*

***Note di attuazione (27–28 Ago 2026):***
- *Il listino è passato **al database**, come la decisione prevedeva: tabelle `piani` e `prezzi_piano`, modelli `Piano` e `PrezzoPiano`, le regole tutte in `App\Support\Listino\GovernoListino`, la lettura in `App\Support\Listino\CatalogoPiani`, la porta verso Stripe dietro l'interfaccia `App\Support\Listino\Stripe\PortaListinoStripe`, e la schermata `/piattaforma/piani` (`App\Livewire\Piattaforma\Listino`). Il permesso è `billing.manage_global`, che era già a catalogo: **nessun permesso nuovo, `config/rbac.php` non toccato, nessun riseeding**, esattamente come la ADR aveva previsto. `App\Support\Piani` resta **l'unica porta** al listino e ha cambiato solo le viscere — l'API pubblica no.*
- *🔴 **Il bootstrap è una MIGRATION e non un seeder, e la differenza è la feature.** La ADR chiedeva di decidere «se il seeding del listino esiste, e cosa fa»: la risposta è che **non esiste**. `2026_08_27_140200_backfill_listino_dal_catalogo` legge `easylab.piani.catalogo` una volta sola, ed è idempotente (`updateOrInsert` sul codice). Non c'è nessun `PianiSeeder` che qualcuno possa rilanciare per riflesso dopo aver toccato la config, quindi la trappola di `config/rbac.php` — dove il gesto corretto è quello distruttivo — qui non si documenta: **si rende inesprimibile**. Effetto collaterale gradito: `RefreshDatabase` migra, quindi i due piani esistono in ogni test senza toccare `tests/Pest.php`.*
- *L'unica chiave di config rimasta **viva** è `easylab.piani.predefinito`, che non è il listino ma «con che piano nasce un account e a quale torna chi disdice». La sua coerenza col database la tiene un test (`ListinoBootstrapTest`) e non una guardia a runtime: `Piani::predefinito()` è chiamato da un **webhook**, e lì un'eccezione farebbe ritentare Stripe per giorni.*
- *⛔ **Nessuna cache condivisa sul listino, ed è la trappola più costosa evitata.** `CatalogoPiani` è un memo **per-richiesta** (singleton nel container), non un `Cache::rememberForever`: Redis è condiviso fra `easylab` e `easylab_test` (CLAUDE.md), e l'incidente già pagato sulla chiave `spatie.permission.cache` si rifarebbe qui **su una somma di denaro** — gli id e i prezzi di un database letti dall'altro. Il memo si dimentica dopo ogni scrittura nella stessa richiesta, o il click che cambia il prezzo renderebbe una pagina col vecchio e la persona cliccherebbe di nuovo, fabbricando a mano il doppio invio contro cui esiste l'idempotenza.*
- ***La conciliazione — la trappola che la ADR nominava per prima — è sciolta scrivendo chi vince.** Il database locale è la verità per il **dominio** (etichetta, tetto di Enti, ordine, offribilità), Stripe è la verità per il **denaro** (esistenza del Product, `unit_amount`, `currency`, `active`). Nessuna riparazione automatica in nessuna delle due direzioni: `GovernoListino::concilia()` produce dei `DivergenzaListino`, la pagina li mostra **coi due valori affiancati** — la stessa forma del marcatore «personalizzato» di `/piattaforma/ruoli` — e la divergenza si risolve con un gesto esplicito, «Sincronizza» oppure «aggancia il price esistente».*
- ***Le due risposte commerciali che la ADR pretendeva prima del form.* (a)** Chi è già abbonato **resta al suo prezzo**: `cambiaPrezzo()` non migra nessuno, la sincronizzazione crea un Price nuovo e archivia il vecchio, e `prezzi_piano` conserva lo **storico** perché è la sola strada con cui `Piani::perPrice()` riconosce ancora il piano di chi fattura sul price vecchio. **(b)** Abbassare `max_enti` **è permesso e non cestina niente**: chi scende sotto il proprio numero di sedi le tiene tutte (grandfathering, ADR-032; `slotEntiResidui()` diventa negativo ed è uno stato legittimo) e semplicemente non ne apre altre. La conseguenza si mostra **prima del click**, col conteggio degli account che finirebbero sopra il limite: è una decisione da far prendere a un umano, non una guardia.*
- *Due attributi sono **immutabili dopo la creazione**, e non è pedanteria. **`codice`**: `accounts.piano` lo conserva come stringa senza FK e senza CHECK, quindi rinominarlo renderebbe «fuori catalogo» — cioè **0 €** nell'MRR — tutti i clienti su quel piano. **`gratuito`**: ribaltarlo su un piano già venduto marcherebbe come paganti dei clienti senza subscription, o il contrario. Chi vuole l'altro comportamento crea un piano nuovo e migra a mano. Il piano **predefinito** non si archivia: archiviarlo lascerebbe il webhook di disdetta a scrivere un piano non offribile.*
- *⚠️ **Archiviare un piano non è toglierlo dal catalogo.** `Piani::codici()` include gli archiviati **apposta**: se filtrasse su `attivo`, ogni account rimasto su un piano ritirato varrebbe 0 € nell'MRR. `attivo` governa la sola **offribilità** (`Piani::offribili()`). È la riga più facile da sbagliare di tutta la feature.*
- *⚠️ **`gratuito ⇒ prezzo 0`, e non l'inverso.** Un piano a pagamento a 0 € è legittimo — è una promozione, ha una subscription vera. L'invariante inversa («chi costa 0 è gratuito») vietava quel caso.*

***Le quattro trappole di Stripe emerse ATTUANDO (28 Ago 2026).*** *Le hanno trovate i cacciatori di difetti, non chi aveva scritto il codice; tre sono chiuse, e la quarta no — vedi l'ultima.*
- *🔴 **«Aggancia un price» non registrava il prodotto**, e quindi armava il gesto successivo: la colonna continuava a dire «da sincronizzare» su un piano ormai corretto, e il click ovvio — «Sincronizza» — trovava `stripe_product_id` vuoto e **creava un secondo Product**, lasciando il price giusto appeso al primo. Cioè esattamente la duplicazione che quella via d'uscita esisteva per evitare. Ora `agganciaPrezzo()` scrive anche il prodotto e il timbro di sincronizzazione — ma **solo se il prodotto c'è**: se Stripe non dice a quale Product appartenga il price, il piano resta «da sincronizzare», perché dire il contrario nasconderebbe l'unica cosa che ancora manca.*
- *🔴 **La chiave di idempotenza era funzione del solo (codice, importo, valuta)**, cioè proteggeva il caso sbagliato. Entro 24 ore Stripe non ricrea nulla: **replica la risposta di allora**. Il giro `4900 → 5900 → 4900` — una cifra digitata male e corretta cinque minuti dopo, che è il caso *normale* — ripescava il Price di partenza che **noi stessi avevamo appena archiviato** e lo rimetteva `corrente` col badge verde «sincronizzato»; da lì `Piani::stripePrice()` restituiva un price inattivo e la sottoscrizione successiva falliva con «This price is not active», in un punto lontanissimo da questa schermata. E non bastava ispezionare la risposta, che essendo la replica di allora dice ancora `active: true`. Ora la chiave contiene **il price da cui si sta passando**, e una seconda cintura (`verificaCheSiaUnPrezzoNuovo()`) rifiuta un price che conosciamo già.*
- *🔴 **L'aggancio verificava importo e valuta ma non che il price fosse ATTIVO**, né che appartenesse al prodotto del piano. È il caso più facile da incontrare davvero: chi cerca il piano nella dashboard di Stripe trova anche i residui delle prove precedenti, che hanno l'importo giusto e sono morti. Un price archiviato poteva così diventare il prezzo corrente del listino. Entrambe le guardie ora ci sono, con il messaggio che spiega dove il guasto sarebbe uscito.*
- *⚠️ 🔴 **E la quarta NON risulta chiusa nel codice, contro quel che dice il messaggio del commit `a8af4bf`.** Il `down()` di `2026_08_27_140200_backfill_listino_dal_catalogo` fa ancora `DB::table('prezzi_piano')->delete()` e `DB::table('piani')->delete()` **senza `where`**, e nessun test lo copre. Rollbackare quella sola migration porterebbe via anche i piani nati dalla dashboard, che in nessuna config esistono e che `up()` non sa ricreare. Annotato qui invece che corretto perché **il codice vince sul messaggio di commit**, e questo documento registra ciò che c'è: la correzione va fatta, e va fatta prima che qualcuno rollbacki su un database con dei piani veri.*
- *(Fuori dai quattro, ma della stessa famiglia: **gli errori di validazione del form non si vedevano** — i `name` degli input non combaciavano con le chiavi dell'error bag, quindi il blocco d'errore per campo era codice morto su tutti e otto i campi e l'unico messaggio restava dietro l'overlay della modale. Chi cliccava vedeva «non è successo niente», cioè il peggior modo di dire «no» su una schermata che tocca denaro.)*

***Limiti dichiarati.***
- *⚠️ **Niente è mai stato provato su Stripe vero.** `PortaListinoStripe` è un'interfaccia e in suite risponde `tests/Fakes/PortaListinoStripeFinta`; `PortaListinoStripeReale` non ha mai parlato con l'API. Vale alla lettera la riga scritta qui sopra prima di cominciare — «un form che salva è il 20% del lavoro» — e l'80% è ancora davanti.*
- *⚠️ **Le migration del listino non sono applicate al database di sviluppo**, ed è una scelta: una delle cinque è il **backfill che scrive dati**, corretto dopo la prima stesura e da rileggere prima di girare su righe vere. CLAUDE.md ricorda perché la suite non se ne accorge: i test girano su SQLite ricreato da zero.*
- *Il provisioning **non ha ancora un select dei piani**, com'era previsto: finché «piano a pagamento» non implica una subscription vera, il form della cabina resta su Free e lo dice in pagina. Il collegamento dalla cabina al listino invece c'è, gatato sullo stesso permesso: l'avviso sui piani fuori catalogo segnalava un problema senza dare la strada per ripararlo.*

**Contesto.** Al 27 Ago 2026 un piano vive in **due posti che nessuno tiene allineati**: il catalogo locale (`App\Support\Piani`, con prezzo di listino e tetto di Enti, letto dai KPI di piattaforma e da `Account::puoAggiungereEnte()`) e il prodotto su **Stripe**, che è ciò che il cliente paga davvero. Cambiare listino significa oggi toccare del codice e poi Stripe, o viceversa — e la cabina di regia non offre alcun modo di farlo, tanto che il form di provisioning **non ha un select dei piani**: un piano a pagamento scelto lì creerebbe un account marcato `saas` senza subscription, cioè un cliente che risulta pagante e non paga.

Che i due possano divergere non è teorico: la cabina **già oggi** gestisce i piani «fuori catalogo» (`accounts.piano` è una stringa senza CHECK) e vale 0 € nei KPI, perché dismettere un codice lascia righe orfane. Il difetto esiste, ed è governato invece che impedito.

**Decisione.** Il listino si crea e si modifica **da una schermata della piattaforma**, e quel gesto **crea o aggiorna anche il prodotto su Stripe**: una superficie sola, un gesto solo.

*Alternativa scartata — «il piano lo crea Stripe, la dashboard dice solo cosa concede».* Era la più semplice e la più sicura: Stripe resta l'unico posto dove si fissa un prezzo (con tasse, valute e fatture già risolte), e la dashboard governerebbe solo la parte di dominio — quanti Enti, quali funzioni. **Scelta la prima** per non avere due posti dove si crea un piano; il costo è dichiarato qui sotto ed è reale.

**Chi può.** Solo **Superadmin e Developer**. Non è una preferenza d'interfaccia: creare un piano è **fissare un prezzo**, un gesto commerciale che nessun cliente deve poter compiere sul proprio account.

- **Non serve un permesso nuovo.** `billing.manage_global` è già a catalogo, lo tengono solo Developer e Superadmin (l'Admin lo ha in `except`, e nessuna lista `only` lo nomina) ed è nel **set bloccato** (🔗 ADR-016): l'editor permessi di runtime non può regalarlo a un ruolo cliente nemmeno per sbaglio. Aggiungerne uno significherebbe un **ottavo** permesso bloccato e un **riseeding** per dire la stessa cosa — è l'errore già commesso e corretto con `system.errors.view`, che non è mai esistito.

**Conseguenze e trappole — da sciogliere prima del form, non dopo.**

- 🔴 **Stiamo scrivendo su un sistema di pagamenti vero.** È la stessa forma del blocco webhook di S5, dove la parte facile era il codice e i quattro difetti stavano tutti nel giro sull'ambiente reale. Un form che salva è il 20% del lavoro.
- 🔴 **Il catalogo oggi vive in `config/easylab.php`, cioè nel codice versionato**, e `App\Support\Piani` lo legge da lì (`codici`, `maxEnti`, `prezzoMensileCent`, `stripePrice`, `perPrice`). Una schermata che *crea* un piano non può scrivere in un file di config: **il listino deve passare al DB**, e `Piani` diventa la facciata sopra il DB invece che sopra la config. È **la stessa forma del patto di `config/rbac.php`** — la config è il **bootstrap**, la verità dopo il primo seeding è il DB (🔗 ADR-016 §7) — con la stessa trappola in agguato: da quel giorno riseminare il listino dalla config **cancellerebbe le personalizzazioni fatte a runtime**. Va deciso *prima* se il seeding del listino esiste, e cosa fa.
- ⚠️ **La conciliazione fra i due cataloghi va progettata, non rimandata.** Un prodotto archiviato su Stripe e ancora attivo qui, o il contrario, è lo stato *normale* dopo il primo errore di rete a metà salvataggio. Serve una risposta scritta a «chi vince» e una schermata che mostri le divergenze — la stessa forma del marcatore «personalizzato» di `/piattaforma/ruoli`, che esiste perché DB e config **divergono** e qualcuno deve poterlo vedere.
- ⚠️ **I clienti già abbonati a un piano che cambia prezzo.** Su Stripe un prezzo non si modifica: se ne crea uno nuovo e si decide che fare delle subscription in essere (restano al vecchio, migrano, migrano al rinnovo). È una scelta **commerciale** prima che tecnica, e senza risposta il form non si può scrivere.
- ⚠️ **Idempotenza sui tentativi ripetuti.** Un doppio clic o un retry non deve lasciare due prodotti gemelli su Stripe.
- ⚠️ **Il tetto di Enti del piano non è cosmetico**: `Account::puoAggiungereEnte()` ci si appoggia, quindi abbassarlo su un piano già venduto mette dei clienti **sopra il proprio limite**. Va deciso se è permesso, e cosa succede a chi ci si trova.
- **Il provisioning cambia di conseguenza**: con un listino governabile il form della cabina potrà offrire un select, ma **solo** dopo che «piano a pagamento» implica una subscription vera. Finché non c'è, resta Free e la pagina lo dice.

**Nota collegata (stessa giornata, 🔗 ADR-013).** Dal **Billing Portal** il cliente **disdice da solo**. Non è una preferenza di configurazione: è la clausola che rende il portale una leva invece di una vetrina, e si appoggia sul percorso già verificato su staging il 21 Ago 2026 — disdetta su Stripe → webhook → account bloccato e piano decaduto a `free`, con il `locked_at` manuale intatto.

---

**ADR-036 — La sala d'attesa del self-signup è un modello di piattaforma: `Registrazione` è esente dall'isolamento per tenant, e al posto della rete tolta ci sono due promesse**

*Stato: Accettata e attuata (28 Ago 2026), insieme al flusso pubblico di 🔗 ADR-012. **Non supera** ADR-006 né ADR-018, che restano validi alla lettera: aggiunge all'elenco delle esenzioni una voce di **specie nuova**. 🔗 ADR-032 (l'Account intestatario, che è ciò che deve nascere).*

**Contesto.** Ogni modello di business usa `BelongsToTenant`, e un meta-test lo verifica (CLAUDE.md); le esenzioni si dichiarano per nome in `TenantScopeGuardrailTest::NON_TENANT_MODELS`, con la loro motivazione. Fino a oggi erano tutte della **stessa specie**: «il modello vive sopra i tenant» — `User`, `Account`, `Errore`, `OccorrenzaErrore` e, da ADR-035, `Piano` e `PrezzoPiano`. Un'eccezione a un pattern è una cosa; una **seconda specie** di eccezione è un'altra, e va scritta dove qualcuno la cerchi.

`Registrazione` non è di quella specie. Non vive sopra i tenant: il tenant **non esiste ancora**, ed è precisamente ciò che quella riga serve a far nascere. La decisione di prodotto dice che l'account nasce solo a pagamento riuscito, quindi fino a `completata_at` non c'è né un `Account`, né un Ente, né un `User` — c'è solo la riga. Non esiste nessun `tenant_id` da timbrare.

E il guasto non sarebbe teorico. Chi compila `/registrati` non è autenticato, quindi `CurrentTenant::shouldScope()` è falso, il hook `creating` non timbrerebbe niente e lo scope non filtrerebbe. Ma il **ritorno da Stripe** (`registrazione.completata`) è l'unica rotta del percorso che **non** è `guest`: un visitatore già loggato con un altro account ci arriva **autenticato**, e lì lo scope filtrerebbe sul tenant di chi passava di lì — fail-closed, cioè 404 — sul completamento di un pagamento **già incassato**. È il punto in cui il fail-closed *è* il danno.

**Decisione.** `Registrazione` non usa `BelongsToTenant` e non ha `tenant_id`. L'esenzione è dichiarata per nome, con la sua ragione, in `TenantScopeGuardrailTest::NON_TENANT_MODELS`. Il confine, qui, non è il tenant: è che **quella tabella non ha nessuna superficie di lettura**.

E poiché l'esenzione **toglie una rete**, al suo posto se ne mettono due, rese meccaniche da `AccessoRegistrazioniGuardrailTest` invece di restare scritte in un commento:

1. `registrazioni` non ha **nessun componente Livewire, nessuna vista, nessuna schermata** che la elenchi. L'elenco dei file che possono nominare il tipo è **chiuso** — dice ciò che è stato *considerato*, non ciò che si è già pensato di vietare — e ogni file nuovo va aggiunto a mano da chi ha risposto alla domanda dell'esenzione.
2. L'unico accesso a una singola riga passa da un **URL firmato**, che copre id e scadenza e cade *prima* del route-model binding: da lì non si enumera.

**Alternative scartate.**
- *Tenere `BelongsToTenant` e timbrare `tenant_id` più tardi.* Non risolve niente: la riga si scrive **prima** che un tenant esista, quindi il timbro sarebbe comunque nullo, e resterebbe intero il guasto del ritorno da Stripe percorso da un utente autenticato.
- *Chiudere con una porta in `VistaPiattaforma`, come si fa per le letture cross-tenant.* Sarebbe il «bypass finto» che `BypassNudiGuardrailTest` rifiuta per nome: non c'è nessuno scope da togliere, quindi la porta direbbe di proteggere e non proteggerebbe.
- *Non persistere affatto, tenendo la registrazione pendente nella sessione del browser.* Toglierebbe dal database il dato personale di chi cliente non è ancora — che è il suo unico pregio — ma ucciderebbe la rete di «ha pagato e ha chiuso la scheda»: il webhook `checkout.session.completed` ritrova la riga per `stripe_session_id`, e una sessione di browser non è raggiungibile da un webhook. Il prezzo sarebbe un incasso senza account, cioè il danno che tutto il resto di questo percorso esiste per evitare.

**Conseguenze e trappole.**
- 🔴 **`registrazioni.password_hash` è un segreto in transito**, e vive lì solo perché la password si sceglie **prima** di pagare e l'utente nasce **dopo**: fra i due momenti c'è un giro su un dominio di terzi. Appena `users.password` esiste, quella colonna viene azzerata **nella stessa transazione** — conservarla sarebbe una seconda copia di una credenziale in una tabella che nessuna Policy protegge.
- **Retention**: il modello è `Prunable` e le righe **mai completate** si potano a 30 giorni (`App\Support\Retention`, `Registrazione::GIORNI_PENDENTE`), con una **whitelist** e non una blacklist — si nomina la condizione che *deve* valere per cancellare, così una colonna di stato aggiunta domani non fa cadere righe nuove nella potatura per difetto.
- Niente `AuditsDomainWrites`, con l'esenzione dichiarata in `AuditCoverageGuardrailTest`: non c'è nessun **causer** da scrivere, e il trait porterebbe `email` e `nome_referente` di chi potrebbe non diventare mai cliente dentro `activity_log`, cioè in una tabella senza retention (T6) leggibile dal registro di audit. L'unica scrittura di audit che ha per soggetto una `Registrazione` è il pagamento incassato che un rifiuto non ha fatto diventare un account (🔗 ADR-012), etichettato sul `nome_ente` e mai sull'email.
- ⚠️ **Il giorno in cui qualcuno costruisse la schermata «registrazioni in corso»** — una tabella con email, nome e ragione sociale di persone che clienti non sono — `AccessoRegistrazioniGuardrailTest` diventa **rosso** e obbliga a rispondere alla domanda che questa ADR ha rimandato: chi la può vedere, e con quale scope. Senza quel guardrail quella tabella nascerebbe senza scope, senza Policy e senza `can:`, e nessun test se ne accorgerebbe. È la ragione per cui l'esenzione ha una voce propria invece di una riga in un commento.

---

**ADR-037 — Il «Parco clienti»: il Superadmin LEGGE la strumentazione di tutti gli Enti, e continua a SCRIVERE solo impersonando**

*Stato: Accettata (28 Ago 2026), **attuata il 29** — **rovescia in parte 🔗 ADR-018** nella metà «lettura», e ne conferma alla lettera la metà «scrittura». Non tocca 🔗 ADR-001 né 🔗 ADR-006: il `TenantScope` resta il confine di ogni schermata operativa, e nessun ruolo lo bypassa **di sessione**. Fondamenta attuate lo stesso giorno (`App\Support\Piattaforma\ParcoClienti`, `Perimetro`, `ParcoBypassGuardrailTest`); le tre schede — strumenti, scadenzario, ricambi — arrivano coi loro blocchi.*

**Contesto.** ADR-018 è la decisione centrale di questo progetto: tredici modelli scopati, tre reti automatiche, sessanta file che la citano. Dice due cose insieme, ed è utile separarle adesso perché è la prima volta che se ne tocca una sola: (a) **nessun ruolo bypassa il Global Scope**, quindi il Superadmin è tenant-bound come chiunque altro; (b) **l'accesso cross-tenant avviene solo via impersonazione**. La stessa ADR prevedeva però la propria eccezione, e la definiva per forma: le viste aggregate di piattaforma usano query esplicitamente non-scopate, gate da permessi. La cabina di regia (S6) è quella forma, e ha una porta sola — `VistaPiattaforma`.

Il modello di business è però cambiato sotto la decisione. ADR-018 nasce dal chiarimento «EasyLab **non possiede** gli Enti dei clienti», e resta vero. Ma Marco gestisce la strumentazione di **molti** Enti dentro questa piattaforma, ed è lui a fare la manutenzione: la domanda «quali macchine di quali clienti scadono questa settimana» è la sua domanda di lavoro quotidiana, non una curiosità sui dati altrui. Oggi la risposta si ottiene impersonando dodici clienti uno dopo l'altro e tenendo a mente dodici elenchi. Il dato è **già raggiungibile**: cambia il numero di click, non il perimetro di ciò che si può vedere.

C'è un secondo fatto, meno ovvio, che pesa nella direzione opposta a quella che sembra. Dodici impersonazioni per leggere dodici scadenzari lasciano nel registro di audit dodici sessioni di impersonazione — cioè il gesto più forte che la piattaforma conosce, usato per **guardare**. Il registro perde così la capacità di distinguere «sono entrato nei dati del cliente X» da «stavo controllando le scadenze»: se l'impersonazione è l'unico modo di leggere, diventa rumore di fondo, e il giorno in cui serve leggerla per davvero non dice più niente.

**Decisione.** Nasce una superficie di **sola lettura** cross-cliente, il *Parco clienti* (`/piattaforma/parco`), gate da `tenants.view_all` — lo stesso permesso della cabina, che significa letteralmente «vedi oltre il tuo Ente», è già del solo Developer/Superadmin ed è nel set 🔒 bloccato di ADR-016. **Nessun permesso nuovo, nessun riseeding.**

Il confine si sposta, e va detto esattamente dove:

- **cosa cambia**: il Superadmin può **vedere** righe di dominio (sedi, strumenti, interventi, garanzie, ricambi) di clienti che non sono il proprio, senza impersonare;
- **cosa NON cambia**: ogni **scrittura** continua a passare dall'impersonazione. È per cliente, ha un inizio e una fine, e lascia una riga di audit che porta dentro **chi** agiva e **per conto di chi** (🔗 ADR-027). Una scrittura cross-cliente perderebbe quel contesto **proprio dove serve di più**: la riga direbbe «qualcuno ha cambiato una scadenza» invece di «EasyLab l'ha cambiata sul cliente X mentre era EasyLab».

Perché quella metà è quella che conta: la lettura è **reversibile** — si guarda una cosa che si sarebbe potuta guardare comunque, e non resta niente di diverso nel database — mentre la scrittura è l'atto che modifica i dati di un terzo, ed è l'unico su cui, un domani, qualcuno dovrà rispondere. Il registro di audit serve a dire *chi ha fatto cosa*, non *chi ha guardato*; togliendo l'impersonazione dalla lettura la si restituisce alla scrittura, dove è la sola traccia che esista.

**La forma è una porta sola,** `App\Support\Piattaforma\ParcoClienti`, sorella di `VistaPiattaforma` e non un'invenzione nuova: costante `PERMESSO`, `Gate::authorize()` dentro la classe, scope tolti **per nome** (mai `withoutGlobalScopes()` nudo, che porterebbe via anche il soft delete), filtro fuori. Ciò che la porta aggiunge rispetto alla sorella è il **perimetro** — tutti i clienti, oppure per piano, oppure gli account scelti — che arriva dal browser e viene rivalidato **intersecandolo** con `VistaPiattaforma::accounts()`.

**Alternative scartate.**

- *Una griglia **modificabile** cross-cliente, così si corregge dove si vede.* È la richiesta che nascerà dal primo giorno d'uso, e va rifiutata adesso che è teorica, perché fra sei mesi sarà una comodità già presa. Due ragioni, e la seconda è peggiore della prima. **(a)** Le righe di audit perderebbero il contesto: una modifica scritta da `/piattaforma/parco` non ha un impersonato, quindi il registro registrerebbe l'unico attore che conosce — EasyLab — su dati di un cliente che non compare da nessuna parte. **(b)** Un filtro che silenziosamente vale «tutti» trasforma una correzione in un'**operazione di massa**. Non è un'ipotesi: questo progetto ha già avuto quella forma di difetto sull'export che ignorava i filtri e portava via l'intero elenco invece della pagina che l'utente stava guardando. Lì costava un CSV di troppo; su una griglia scrivibile costerebbe una colonna riscritta su ogni cliente della piattaforma, e la si scoprirebbe dai reclami.
- *Allargare i global scope per il Superadmin — cioè tornare ad ADR-001.* Risolverebbe tutto in tre righe, ed è la ragione per cui va scritto perché no. Renderebbe il privilegio **la strada normale**: ogni schermata operativa diventerebbe cross-tenant, e non ci sarebbe più un posto in cui il confine si vede. E il registro di audit smetterebbe di distinguere «ho guardato i dati del cliente X» da «stavo usando l'applicazione», perché sarebbero letteralmente la stessa azione. L'eccezione dichiarata e circoscritta costa una classe in più; il bypass di sessione costa la capacità di dire cos'è successo.
- *Un selettore di tenant che scopa la sessione su un Ente scelto.* Già scartato da ADR-018, e la ragione regge invariata: duplicherebbe la logica dell'impersonazione e aggiungerebbe un secondo percorso di accesso cross-tenant da proteggere. Il parco non è quel selettore — non cambia il contesto della sessione, aggiunge una vista che dichiara nel proprio nome di essere trasversale.
- *Tenere tutto com'è e vivere con dodici impersonazioni.* È l'alternativa onesta, ed è stata considerata: non costa niente da costruire. Si scarta per il secondo fatto del contesto — non perché sia scomoda, ma perché **consuma il gesto più forte della piattaforma per fare la cosa più debole**, e a forza di usarlo per leggere lo rende illeggibile.

**Conseguenze e trappole.** *È la parte che conta, perché questa decisione cambia la specie della difesa: fino a ieri l'isolamento era **impossibile da violare per costruzione** — il global scope filtrava e basta, e chi scriveva una query nuova era protetto senza saperlo. Da oggi, su questa superficie, l'isolamento è **corretto se il filtro è scritto bene**. Non è più una proprietà del framework, è una proprietà del codice, e va difesa con dei test invece che con un'architettura.*

- 🔴 **«Vuoto» e «tutti» sono a un carattere di distanza, e nessuno dei due dà errore.** Un `whereIn` dimenticato non lancia: mostra di più. Un `if ($scelti)` che tratta la selezione vuota come «nessun filtro» mostra tutti i clienti a chi ne aveva chiesto zero. Per questo `Perimetro` non ha un modo «nessuno» separato — l'insieme vuoto **è** `scelti([])`, che compila `0 = 1` — e ogni input che non si è capito (un modo inesistente, un piano fuori catalogo, un id malformato) cade **lì** e non su «tutti». Sei casi negativi lo congelano, uno per forma di input.
- 🔴 **Il perimetro arriva dal browser e va rivalidato server-side, ma la difesa non è una validazione: è la forma della query.** Gli id scelti si applicano **sopra** `VistaPiattaforma::accounts()`, cioè come **intersezione**. Un id forgiato non trova corrispondenza e sparisce; l'account di piattaforma resta fuori anche se qualcuno ne scegliesse l'id a mano; un account cestinato resta fuori anche se il suo id fosse rimasto in un segnalibro. Scritta come unione — «i clienti *oppure* questi id» — la stessa riga sarebbe un buco, e sembrerebbe identica.
- ⚠️ **Il gate dentro `ParcoClienti` è difesa in profondità, e la sua prova non è quella che sembra.** Ogni lettore attraversa comunque `VistaPiattaforma`, che il permesso lo chiede: togliendo il `Gate::authorize()` dalla porta del parco, **i negativi comportamentali resterebbero verdi**. Serve un test **strutturale** che legga il sorgente e pretenda che ogni metodo pubblico apra con la porta — c'è, ed è l'unico che diventa rosso. Va detto per iscritto o il prossimo lettore toglierà il gate «perché è già chiesto sotto», e il giorno in cui un lettore smettesse di passare da `VistaPiattaforma` non lo saprebbe nessuno.
- ⚠️ **I builder che la porta consegna sono scrivibili.** `Builder::update()` e `->delete()` passano dal query builder e non emettono eventi di modello: gli hook di `BelongsToTenant` non girano, e un update concatenato riscriverebbe righe di **ogni** cliente dietro un permesso che si chiama `.view_all` — cioè farebbe, in una riga, esattamente l'alternativa scartata (a). `ParcoBypassGuardrailTest` lo vieta cercando la forma diretta, quella per variabile e quella per metodo che restituisce il builder, che è la forma che si scrive *scrivendo bene*.
- ⚠️ **Una porta che si può aggirare non è una porta.** Lo stesso guardrail vieta allo strato UI di piattaforma di togliere global scope a mano, e alle schermate del parco di leggere direttamente da `VistaPiattaforma`: quei builder non sono filtrati per cliente di proposito — servono a **contare** tutti i clienti, non a **elencare** le righe di quelli scelti — quindi chiamarli da una scheda del parco significa mostrare «tutti» mentre il filtro in cima alla pagina dice altro. `VistaPiattaforma::PERMESSO` resta lecito: è una costante, non una query.
- ⚠️ **`GaranziaRicambioPrivacyScope` NON si toglie, e non è una dimenticanza.** Non è uno scope di tenancy: risponde a «questo utente ha titolo a vedere le garanzie dei pezzi montati?» (🔗 ADR-029), che resta una domanda sensata anche cross-cliente. Per il Superadmin è un no-op — la Policy vincola il solo ruolo `Tenant`, e il permesso ce l'ha — mentre per un ruolo che avesse `tenants.view_all` **senza** `garanzie.ricambio.view` resta fail-closed. E quel ruolo è a un click di distanza, perché dall'editor permessi la matrice si modifica a runtime. Toglierlo «per uniformità con gli altri scope» concederebbe in silenzio una categoria di righe a chi non l'ha mai avuta.
- ⚠️ **Gli scope si tolgono per nome, e l'elenco è per modello**: `Strumento` e `UnitaOrganizzativa` passano da `VistaPiattaforma` (`TenantScope` + `DepartmentScope`); `Intervento` toglie `TenantScope` + `DepartmentThroughStrumentoScope`; `Garanzia` `TenantScope` + `GaranziaDepartmentScope`; `Ricambio` il solo `TenantScope`. Se a uno di questi modelli si aggiungesse un global scope nuovo, si corregge **lì** — e `BypassNudiGuardrailTest` non se ne accorgerebbe, perché sorveglia la forma **nuda** e non l'elenco. È il limite dichiarato della rete, non un'omissione.
- **`RicambioUtilizzo` resta fuori**, insieme alla domanda che porta con sé (due scope in più, uno dei quali di privacy). Il giorno in cui servisse si aggiunge un metodo alla porta e si risponde allora, invece di consegnarlo adesso «per simmetria» — che è il modo in cui un'esenzione diventa una regola.
- **La cabina e il parco non si fondono.** `PerimetroClienti` (i KPI) conta clienti, sedi e macchine di *tutti*; `ParcoClienti` elenca le righe di *quelli scelti*. Sono due domande diverse sulla stessa tabella, e unificarle significherebbe che un filtro della vista cambia un numero della dashboard — o, peggio, che non lo cambia e i due si contraddicono a due centimetri di distanza.
- 🔗 **Ciò che questa ADR non decide**, e va deciso quando le tre schede esisteranno: se il parco debba mostrare il **semaforo** (ADR-005). Gli scope `conStato()`/`obsoleti()`/`ordinaPerStato()` compongono sottoquery che restano **scopate**, quindi su un builder cross-tenant classificherebbero come verdi le macchine degli altri Enti — e la partizione tornerebbe lo stesso, cioè il difetto sarebbe una cifra plausibile e non una mancante. `VistaPiattaformaTest` vieta già di concatenarli alla porta della cabina; chi vorrà il semaforo nel parco dovrà costruire **fonti non scopate**, non riusare quelle.

**Addendum del 29 Ago 2026 — il terzo modo del perimetro sono i PREFERITI, e non si scelgono più nella pagina che li usa.**

*Attuato lo stesso giorno del parco, su richiesta di Marco dopo averlo usato: «quelli che scelgo» vorrei che fossero «i preferiti», e vorrei sceglierli da un'altra vista.*

Il terzo modo era una `<select multiple>` legata a un `#[Url]`: l'insieme viveva **nella querystring**, quindi si ricomponeva a mano a ogni visita che non arrivasse da un link salvato. Ma i clienti che si guardano spesso sono **pochi e sempre gli stessi**: la scelta non era un filtro, era una preferenza, e stava nel posto sbagliato. Ora è un pivot per-utente — `clienti_preferiti` (ERD §4.3) — si segna con una **★ nell'elenco Clienti** della cabina, e le tre schede del parco la leggono.

Le conseguenze che contano non sono di comodità:

- 🔴 **Nessuna lista di id di clienti arriva più dal browser.** Le tre schede non hanno più una property pubblica che la porti (`accountIds`, `clientiScelti`: rimosse), quindi il `whereIn` del perimetro non ha una strada d'ingresso dalla querystring. La difesa dichiarata sopra — l'**intersezione** con `VistaPiattaforma::accounts()` — resta identica e resta necessaria: gli id ora vengono dal database, ma un preferito segnato quando l'account era vivo sopravvive al suo cestinamento. È diventata la **seconda** difesa invece che la sola, ed è la direzione giusta per una superficie che ADR-037 stessa definisce «sorvegliata da test invece che garantita dal framework».
- 🔴 **`Perimetro::daRichiesta()` NON sa costruire il modo preferiti**, ed è deliberato: gli id non arrivano dal browser, quindi l'unico costruttore è `Preferiti::perimetro()`, che è gata. Un componente che se ne dimenticasse cade su `nessuno()` — zero righe, non le righe di tutti. L'errore cade dalla parte giusta, che è la regola di tutto questo pacchetto.
- ⚠️ **La scrittura è nuova, e non era nel disegno originale.** ADR-037 diceva «sola lettura», e resta vero **del dominio**: `clienti_preferiti` non contiene dati di clienti, contiene una preferenza di chi guarda, e nessun cliente cambia di stato perché qualcuno l'ha segnato. Ma è un `INSERT` che parte da un id arrivato dal browser, quindi ha la sua porta: `App\Support\Piattaforma\Preferiti`, stesso permesso `tenants.view_all`, che **rilegge l'account dall'insieme legittimo** prima di scrivere — l'account di piattaforma e uno cestinato non diventano preferiti nemmeno mandando il loro id a mano. ⛔ La regola di revisione «una PR che aggiunga una scrittura al parco si respinge» **non si allarga oltre questo**: qui si scrive una riga *sull'osservatore*, mai una sull'osservato.
- ⚠️ **Un modo che la tendina non sa disegnare si normalizza, non si tollera.** `?modo=scelti` da un link salvato ricade su `preferiti` in tutte e tre le schede. Una `<select>` legata a un valore senza `<option>` evidenzia la **prima** voce — «Tutti i clienti» sopra una tabella che tutti i clienti non li mostra — e da lì non si esce, perché riselezionare il valore già mostrato non emette alcun evento. Il modo `SCELTI` resta nel dominio (è ciò che `nessuno()` usa), ma non ha più un controllo.
- **Il preferito è della persona, non del cliente.** Un flag su `accounts` sarebbe stato più semplice e avrebbe detto il falso al secondo Superadmin. Per la stessa ragione la colonna non è ordinabile: ordinare per un dato personale metterebbe la stessa riga in due posti per due colleghi.


---

**ADR-038 — Le persone si creano da interfaccia; un utente si cestina e si ripristina; il tecnico EasyLab lavora su un portafoglio dichiarato**

*Stato: Accettata il 29 Ago 2026 e **attuata nel codice il 30 Ago 2026**. Restano pendenti l'applicazione della migration additiva `users.deleted_at` al database di sviluppo e la verifica manuale delle due schermate; non sono parte dello stato di attuazione finché non vengono eseguite. **Rovescia una decisione di 🔗 ADR-012** (il rifiuto di uno stato «disattivato» sull'utente) sostituendola con qualcosa di diverso in natura, non con la stessa cosa sotto un altro nome. **Attua** la parte di 🔗 ADR-007/030 rimasta scoperta — la UI del portafoglio, promessa «per S6 con la pagina permessi» e mai costruita — e **restringe** un comportamento in essere di `SchedaStrumento::assegnabili()`. Non tocca 🔗 ADR-018: nessuna delle due schermate nuove legge oltre il proprio Ente senza passare da una porta già esistente.*

**Contesto.** Il 29 Ago 2026 Marco apre «Nuovo intervento» su una macchina di una sede appena creata e trova la tendina **Assegnatario vuota**, con il campo obbligatorio. Non è un difetto di quella schermata: **in Easy Lab non esiste alcun modo di creare una persona da interfaccia.** Gli utenti nascono da `easylab:provision-tenant`, dal self-signup pubblico e dai seeder, e in tutti e tre i casi nasce **un Admin e uno solo** — il ruolo è hard-coded nel provisioning. I permessi `utenti.view|create|update|delete` sono a catalogo dal primo giorno, l'Admin li ha tutti, e **nessuna riga di codice li consuma**: sono quattro dichiarazioni senza conseguenze.

Mancano tre cose che sono la stessa cosa vista da tre lati: il cliente non può aggiungere i suoi; EasyLab non può creare i propri tecnici — la figura che ADR-007/030 ha progettato apposta per lavorare su più clienti, il cui pivot `tecnico_cliente` esiste a database dal 16 Ago 2026 con **zero righe scritte da qualcosa che non sia un seeder o un test**; e nessuno può far uscire di scena una persona.

C'è poi un difetto **già attivo**, che questa decisione chiude di conseguenza: la tendina dell'assegnatario offre a **ogni** cliente **tutti** i tecnici di piattaforma. Con il tecnico unico della demo non si nota; con dieci tecnici veri ogni Admin cliente legge l'organigramma di EasyLab e può assegnare lavoro a chiunque, senza che EasyLab lo sappia.

**Decisione.**

1. **Due schermate, non una.** `/utenti` è dato dell'Ente e sta nella sidebar del cliente, gata su `utenti.view`. `/piattaforma/tecnici` è cosa di EasyLab e sta nella sub-nav di piattaforma, gata su **`tenants.view_all`** e non su `utenti.view` — vedi le conseguenze.
2. **L'Admin del cliente conferisce quattro ruoli**: Admin, Responsabile Reparto, Tenant, Tecnico interno. Superadmin e Developer **non sono conferibili da nessuna interfaccia**, e il rifiuto vive nel codice, non nell'assenza dalla tendina.
3. **La schermata di piattaforma crea una sola figura**: il tecnico EasyLab, cioè ruolo `Tecnico` e **nessun Ente**. La forma «interna» resta legittima (ADR-030) ma la crea il cliente dalla propria schermata, non EasyLab dalla sua.
4. **Il portafoglio diventa la regola della tendina.** Assegnabile su una macchina è chi ha `tenant_id` uguale a quello della macchina, **unito** ai tecnici EasyLab presenti in `tecnico_cliente` per **quella sede**. Non più «tutti i tecnici ovunque».
5. **Una persona si cestina** (`users.deleted_at`) e si ripristina. Non si cancella.

**Perché il soft delete e non `is_active`, che ADR-012 aveva rifiutato due volte.** Il rifiuto era motivato — «sarebbe la terza sorgente di verità su chi può entrare», accanto alla password e a `email_verified_at` — e resta valido **contro un flag**. Il soft delete non è quel flag: non aggiunge uno stato da consultare, **toglie la riga**. Login negato, sparizione dalle tendine, dai destinatari del digest e dai candidati all'impersonazione arrivano tutti dallo **stesso** global scope, senza un solo `if` nuovo da ricordare in dodici punti. È la differenza fra una regola che si applica e una regola che si deve applicare.

**Alternative scartate.**

- **(a) Cancellazione vera.** Le FK verso `users` sono metà `cascadeOnDelete` e metà `nullOnDelete`: sparirebbero appartenenze e portafoglio, e **ogni intervento storico perderebbe l'assegnatario**. Irreversibile, e distruggerebbe proprio il dato per cui lo storico esiste.
- **(b) Sola revoca del ruolo.** Nessuna migration, nessun ADR — e un ex dipendente che continua a fare login. Lo stato «esiste ma non serve a niente» è esattamente ciò che una schermata di gestione persone deve saper chiudere.
- **(c) Lasciare la tendina com'è.** Zero rischio di «il tecnico che mi serve non è in lista», e in cambio l'organigramma di EasyLab visibile a ogni cliente. Scartata anche perché oggi **le due metà divergono**: un tecnico fuori portafoglio è assegnabile ma le macchine di quel cliente non le vede — gli si può dare un lavoro che non può aprire.
- **(d) Gatare `/piattaforma/tecnici` su `utenti.view`.** Sembra il permesso giusto e non lo è: ce l'ha ogni Admin cliente. È il precedente già pagato con `audit.view` — «un gate cross-tenant su un permesso ridistribuibile è una falla ad attivazione differita», e dall'editor dei ruoli quella ridistribuzione è a un click.

**Conseguenze e trappole.**

- 🔴 **Il soft delete su `users` va scritto insieme a tutte le sue conseguenze, o si scoprono una alla volta in produzione.** Cinque relazioni di **attribuzione storica** — `Intervento::tecnico`, `Documento::caricato_da`, `Strumento::forced_by`, `SpostamentoStrumento::eseguito_da`, `Errore::risolto_da` — tornerebbero `null` per una persona cestinata, e la pagina direbbe «—» dove prima diceva un nome. Vanno lette `withTrashed()`. Lo stesso vale per le due letture del **registro di audit** (il filtro «chi» e gli impersonatori): il registro non può smettere di nominare chi se n'è andato, che è precisamente il momento in cui serve.
- 🔴 **`users.email` è unique senza condizione**, quindi una persona cestinata **blocca** la ricreazione della stessa email — e i due punti che cercano per email (`ProvisionaEnte`, `RegistrazionePubblica`) non la troverebbero più: `firstOrCreate` tenterebbe l'INSERT e sbatterebbe sull'unique, cioè un 500 raggiungibile **dalla superficie pubblica**. Entrambi leggono `withTrashed()`, e invitare un'email cestinata **propone il ripristino** invece di fallire.
- ⚠️ **Il portafoglio è un conferimento d'accesso, non una nota organizzativa.** Aggiungere un cliente al portafoglio di un tecnico gli apre **tutte** le macchine di quella sede (`AccessoTecnico`) oltre a farlo comparire nella tendina. La schermata deve dirlo dov'è il gesto, non in una guida.
- ⚠️ **Il cambio della tendina rompe due test in essere, e devono essere riscritti apposta**: la regola passa da «ogni tecnico ovunque» a «i tecnici in portafoglio». Un test che si aggira invece di essere riscritto è la forma in cui una decisione si perde.
- ⚠️ **Creare un Admin significa imporre il secondo fattore** (`two_factor_required_roles`), quindi quella persona al primo accesso finisce su `/settings/security` e non può andare altrove finché non lo configura. La schermata lo dice **prima** di conferire il ruolo, non dopo.
- ⚠️ **Il ruolo si assegna con l'API di spatie** (`assignRole`/`syncRoles`), mai scrivendo il pivot: solo quella invalida la cache dei permessi, che è **condivisa fra i processi**. Un guardrail lo rende rosso, e il difetto che previene dura fino a 24 ore.
- 🔴 **Le chiavi di rientro non si amministrano da `/utenti`.** Developer e Superadmin non sono né conferibili né modificabili/cestinabili da quella pagina, anche quando condividono l'Ente di piattaforma con chi guarda. La regola è simmetrica: ciò che l'interfaccia non può conferire non può nemmeno togliere.
- 🔴 **L'ultimo Admin non si declassa e non si cestina.** Sono due gesti diversi che porterebbero allo stesso stato senza chiave amministrativa; entrambi rileggono la persona nel confine dell'Ente e verificano l'invariante nell'azione che scrive.
- **Admin e `account_user` seguono gesti espliciti.** Un Admin invitato o promosso viene aggiunto fra i membri dell'Account del suo Ente. Una demozione non lo stacca automaticamente: l'appartenenza contrattuale non si deduce al contrario da un cambio di ruolo. Il cestino tenta invece il distacco tramite l'API dell'Account — che rifiuta l'ultimo membro — e il ripristino di un Admin lo riaggiunge.
- **Ogni azione mutante è riautorizzata e auditata.** Aprire una modale non è un'autorizzazione durevole: invito, reinvito, cambio ruolo, cestino, ripristino e sincronizzazione del portafoglio richiedono di nuovo il proprio gate e rileggono gli id ricevuti dal browser dentro il perimetro ammesso. Poiché `User` non usa `AuditsDomainWrites`, questi atti scrivono una riga esplicita nel canale `audit`.
- **Nessun permesso nuovo, quindi nessun riseeding** di `config/rbac.php` — che da S6 è un'arma carica. I quattro `utenti.*` esistono già e sono dell'Admin; ⚠️ ma **non sono nel set bloccato**, quindi l'editor dei ruoli può concederli a ruoli senza secondo fattore obbligatorio. Chi un giorno allargasse quel set troverebbe qui la ragione per farlo.
- **Due voci di privacy restano da scrivere**, e questa decisione le apre: la comunicazione ai clienti dei **nomi del personale EasyLab** (che oggi avviene già, senza essere registrata) e la **conservazione** di un utente cestinato, che resta in tabella a tempo indeterminato perché lo storico lo nomina.

**Verifica residua.** La suite e la prova manuale non si deducono dall'esistenza del codice. Prima di dichiarare chiuso il blocco vanno ancora: applicata con `php artisan migrate` la migration additiva sul database di sviluppo; percorse in browser `/utenti` e `/piattaforma/tecnici`; creato un tecnico, assegnata una sede al suo portafoglio e verificata la sua presenza nella tendina «Assegnatario» di quella sede. Queste tre verifiche sono **pendenti al 30 Ago 2026**.
