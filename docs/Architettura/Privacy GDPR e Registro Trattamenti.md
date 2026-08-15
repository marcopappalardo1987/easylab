🔏 Privacy GDPR & Registro dei Trattamenti (bozza) — Easy Lab

*Bozza tecnico-organizzativa a supporto della compliance GDPR: ruoli privacy, registro dei trattamenti (art. 30), misure di sicurezza, diritti degli interessati e punti aperti. Copre il task S0.8 `[STRETCH]`. **Non è un parere legale:** è la base tecnica che un DPO/consulente legale deve validare e completare prima del go-live (S7).*

> **Stato:** bozza di Sprint 0 (task S0.8, STRETCH). Da far validare legalmente; alcuni punti sono **APERTI**.

---

## 1. Ruoli privacy

| Soggetto | Ruolo GDPR (proposto) | Note |
|---|---|---|
| **EasyLab** | **Titolare** per i dati dei propri account/clienti diretti e per i dati di fatturazione; **Responsabile** (data processor) per i dati che i clienti SaaS trattano tramite la piattaforma | Duplice ruolo tipico del SaaS. **APERTO:** confermare il confine con un legale. |
| **Cliente/Ente (Admin/Tenant)** | **Titolare** dei dati operativi del proprio laboratorio | Firma DPA (accordo art. 28) con EasyLab. |
| **Tecnici/Operatori** | Persone autorizzate al trattamento (art. 29) | Accesso tracciato (ADR-007). |
| **Sub-responsabili** | **Laravel Cloud** (applicazione, database, Redis), **Backblaze B2** (documenti), provider SMTP, **Stripe** (pagamenti) | Serve elenco sub-processor + relativi DPA (🔗 ADR-025). |

> **Azione:** predisporre **DPA** (EasyLab↔clienti) e raccogliere i DPA dei sub-responsabili. **Residenza dati in UE** — ambienti Laravel Cloud in regione UE e bucket B2 ad Amsterdam (`eu-central-003`) — per minimizzare trasferimenti extra-UE.

> ⚠️ **Backblaze è un sub-responsabile aggiunto per scelta** (🔗 ADR-025). L'object storage incluso in Laravel Cloud avrebbe evitato un fornitore e un DPA in più, ma costa circa 3× sullo storage: si è preferito il risparmio, accettando l'onere documentale. La conseguenza pratica è che **il DPA con Backblaze va firmato prima che il primo documento di un cliente entri nel bucket**, non a ridosso del go-live. Un account personale gratuito è adatto solo alle prove tecniche.

---

## 2. Registro dei trattamenti (art. 30)

| # | Trattamento | Finalità | Categorie interessati | Categorie dati | Base giuridica | Conservazione |
|---|---|---|---|---|---|---|
| T1 | **Gestione account & auth** | accesso, sicurezza (2FA) | utenti piattaforma | nome, email, hash password, 2FA, log accessi | esecuzione contratto / legittimo interesse (sicurezza) | durata rapporto + retention tecnica |
| T2 | **Anagrafica strumenti & manutenzioni** | erogazione del servizio (semaforo, scadenze, interventi) | personale dei laboratori | dati strumenti, interventi, tecnico assegnato, note | esecuzione contratto | durata rapporto + storico |
| T3 | **Documenti & certificati** | archiviazione/allegati (tarature, report) | personale dei laboratori | file su **Backblaze B2**, bucket privato in UE, accesso solo autenticato | esecuzione contratto | durata rapporto |
| T4 | **Notifiche email/in-app** | promemoria scadenze ("email del futuro") | utenti destinatari | email, contenuto notifica | esecuzione contratto / legittimo interesse | log invii a rotazione |
| T5 | **Fatturazione & abbonamenti** | incassi, obblighi fiscali | clienti paganti | P.IVA, Cod. Fiscale, PEC/SDI, dati pagamento (via Stripe) | obbligo legale / contratto | termini fiscali di legge |
| T6 | **Audit log** | sicurezza, tracciabilità (impersonation, forzature, accessi tecnici, **scritture di dominio** — 🔗 ADR-027) | utenti piattaforma, **in prevalenza dipendenti e tecnici** | chi/cosa/quando, IP | legittimo interesse / obbligo sicurezza | retention definita (**APERTO — più urgente**) |
| T7 | **Accesso tecnici cross-tenant** | manutenzione su clienti in portafoglio/assegnati | personale laboratori terzi | accessi loggati a schede strumento | esecuzione contratto (ADR-007) | come audit log |

> **APERTO:** definire i **tempi di conservazione** puntuali per ciascun trattamento (T1, T4, T6) con il legale.
>
> ⚠️ **T6 è diventato più pesante** (🔗 ADR-027, 8 Ago 2026): la tracciabilità passa da poche azioni sensibili a **ogni scrittura di dominio**. Sono dati personali riferiti soprattutto ai **dipendenti** (chi ha modificato cosa e quando), quindi il trattamento sfiora il controllo a distanza dell'attività lavorativa: va inquadrato con attenzione, la finalità dichiarata resta la sicurezza e la ricostruzione degli eventi, e i tempi di conservazione vanno fissati **prima** che il volume renda scomodo cambiare idea. Non è un adempimento rinviabile al go-live.

---

## 3. Misure di sicurezza (tecniche e organizzative)

Già previste dall'architettura (mappate agli ADR):
- **Isolamento multi-tenant** row-level + Global Scope, con **suite di test di isolamento** dedicata (🔗 ADR-001) — misura cardine contro fughe di dati tra clienti.
- **Controllo accessi** RBAC granulare (`Schema Ruoli e Permessi.md`) + set permessi **bloccato** per vincoli privacy (🔗 ADR-016).
- ⚠️ **DPA con Backblaze — DA FARE, confermato il 15 Ago 2026: non esiste ancora.** Il DPA (*Data Processing Agreement*, accordo sul trattamento dei dati, art. 28 GDPR) è il contratto con cui un fornitore che tratta dati **per conto tuo** si impegna su sicurezza, sub-fornitori, cancellazione e assistenza in caso di violazione. Senza, ospitare documenti di clienti su B2 è un trattamento privo di base contrattuale — e la responsabilità resta interamente su EasyLab. Backblaze ne pubblica uno standard, che si accetta dal pannello dell'account: è un adempimento di minuti, non una negoziazione. **Precondizione al primo documento di un cliente reale**; per lo sviluppo il bucket di prova va bene, e i test non lo toccano mai (`Storage::fake()`).
- ⚠️ **Documenti su Backblaze B2 (🔗 ADR-025/026), dal 15 Ago 2026**: i file allegati a strumenti e interventi risiedono su un bucket privato in regione UE. Il **DPA con Backblaze va verificato prima che vi finiscano documenti di clienti reali** — oggi il bucket configurato è `test-area1`, area di prova. B2 è un sub-responsabile e va elencato come tale. I download sono tracciati sul canale `audit` (chi, cosa, quando): è un dato personale sui dipendenti, e rientra nella retention T6 ancora da definire col legale.
- **Visibilità garanzie ricambio:** configurabile per Ente, **default «modifica»** (🔗 ADR-029, attuata il 15 Ago 2026). Non è più un caso di minimizzazione, e la voce è stata riscritta invece che aggiornata: si tratta di dati del **Tenant stesso** — pezzi montati sulle sue macchine — e negarglieli per difetto non minimizzava un trattamento, sottraeva a un interessato i propri dati. La minimizzazione resta l'argomento giusto per lo stato `nascosta`, che serve al full service e va motivato contrattualmente da chi lo imposta; accesso tecnico limitato a portafoglio ∪ assegnazione (🔗 ADR-007). *Precisazione (🔗 ADR-020): al Tenant resta nascosto il **dato** (righe, nomi dei pezzi, scadenze), non il suo **effetto** sul semaforo del proprio strumento — che è informazione sul suo bene, non sui rapporti commerciali di EasyLab.*
- **Autenticazione forte:** 2FA obbligatoria per ruoli privilegiati (🔗 ADR-012).
- **Cifratura:** TLS in transito (gestito da Laravel Cloud); **cifratura a riposo dei documenti** attiva sul bucket (SSE-B2, `AES256`, chiavi gestite da Backblaze — misura ex art. 32); storage su **bucket privato**, mai esposto direttamente — il download passa da una **rotta firmata dell'applicazione** che ricontrolla l'autorizzazione a ogni richiesta (🔗 ADR-026), quindi l'accesso è revocabile e tracciabile; segreti fuori dal repo.
  > La cifratura è **per bucket** e va riattivata su ogni bucket nuovo, produzione compresa: non si eredita. Essendo SSE-B2 (chiavi di Backblaze) è **trasparente al codice** e non impedisce snapshot, versioning e regole di lifecycle. Sarebbe invece SSE-C — chiavi nostre, inviate a ogni richiesta — a bloccare le funzioni che leggono i file lato server, oltre a complicare ogni `put`/`get`.
- **Audit/accountability:** activity log su impersonation, autenticazione, forzature del semaforo e — dal 8 Ago 2026 — **ogni scrittura di dominio** (🔗 ADR-027). È la misura che consente di concedere permessi operativi a chi fa il lavoro senza rinunciare alla ricostruzione degli eventi: **la traccia sostituisce il divieto**.
- **Backup & restore** testati (S7) + procedura di ripristino.
- **Residenza UE** di hosting e storage.

---

## 4. Diritti degli interessati & funzioni di prodotto

| Diritto (artt. 15–22) | Supporto in piattaforma | Sprint |
|---|---|---|
| Accesso / portabilità | **Export dati tenant** (anche in caso di lockout, lato Superadmin) | S7 (🔗 ADR-013) |
| Cancellazione / limitazione | **Soft-delete + retention**; informativa | S7 |
| Rettifica | CRUD anagrafiche | S2+ |
| Opposizione | gestione preferenze notifiche | S5 |

**Caso particolare — trasferimento strumento tra Enti (🔗 ADR-015):** lo storico interventi segue la macchina e diventa visibile al nuovo proprietario. Rischio noto: dati potenzialmente identificativi del precedente proprietario. Mitigazioni: previsione contrattuale/informativa esplicita; base giuridica mantenuta da EasyLab; **fallback** anonimizzazione dei riferimenti identificativi (pianificato V1.2). → Va riflesso nell'informativa.

---

## 5. Documenti da produrre (prima del go-live S7)

- [ ] **Informativa privacy** (clienti e utenti finali) — versione cliente + versione tecnico.
- [ ] **DPA** EasyLab ↔ clienti (art. 28) + raccolta DPA sub-responsabili (**Laravel Cloud**, **Backblaze B2**, SMTP, Stripe). ⚠️ Quello con Backblaze serve **prima dei primi documenti reali**, non prima del go-live.
- [ ] **Registro dei trattamenti** definitivo (da §2, con tempi di conservazione validati).
- [ ] **Elenco sub-processor** pubblicato/aggiornabile.
- [ ] **Procedura data breach** (notifica entro 72h) e **procedura richieste interessati**.
- [ ] Eventuale **DPIA** se valutata necessaria (volume/sensibilità dati).

---

## 6. Punti APERTI (per il legale/DPO)
1. Confine **Titolare vs Responsabile** EasyLab nei diversi trattamenti (§1).
2. **Tempi di conservazione** per T1/T4/T6 (§2).
3. Necessità di **DPIA**.
4. Testo informativa sul **trasferimento cross-tenant** dello storico (§4, ADR-015).
5. Eventuale nomina **DPO**.
