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
| **Sub-responsabili** | DigitalOcean (hosting/Spaces), provider SMTP, **Stripe** (pagamenti) | Serve elenco sub-processor + relativi DPA. |

> **Azione:** predisporre **DPA** (EasyLab↔clienti) e raccogliere i DPA dei sub-responsabili. **Residenza dati in UE** (droplet + Spaces region UE) per minimizzare trasferimenti extra-UE.

---

## 2. Registro dei trattamenti (art. 30)

| # | Trattamento | Finalità | Categorie interessati | Categorie dati | Base giuridica | Conservazione |
|---|---|---|---|---|---|---|
| T1 | **Gestione account & auth** | accesso, sicurezza (2FA) | utenti piattaforma | nome, email, hash password, 2FA, log accessi | esecuzione contratto / legittimo interesse (sicurezza) | durata rapporto + retention tecnica |
| T2 | **Anagrafica strumenti & manutenzioni** | erogazione del servizio (semaforo, scadenze, interventi) | personale dei laboratori | dati strumenti, interventi, tecnico assegnato, note | esecuzione contratto | durata rapporto + storico |
| T3 | **Documenti & certificati** | archiviazione/allegati (tarature, report) | personale dei laboratori | file su Spaces (URL firmate) | esecuzione contratto | durata rapporto |
| T4 | **Notifiche email/in-app** | promemoria scadenze ("email del futuro") | utenti destinatari | email, contenuto notifica | esecuzione contratto / legittimo interesse | log invii a rotazione |
| T5 | **Fatturazione & abbonamenti** | incassi, obblighi fiscali | clienti paganti | P.IVA, Cod. Fiscale, PEC/SDI, dati pagamento (via Stripe) | obbligo legale / contratto | termini fiscali di legge |
| T6 | **Audit log** | sicurezza, tracciabilità (impersonation, forzature, accessi tecnici) | utenti piattaforma | chi/cosa/quando, IP | legittimo interesse / obbligo sicurezza | retention definita (APERTO) |
| T7 | **Accesso tecnici cross-tenant** | manutenzione su clienti in portafoglio/assegnati | personale laboratori terzi | accessi loggati a schede strumento | esecuzione contratto (ADR-007) | come audit log |

> **APERTO:** definire i **tempi di conservazione** puntuali per ciascun trattamento (T1, T4, T6) con il legale.

---

## 3. Misure di sicurezza (tecniche e organizzative)

Già previste dall'architettura (mappate agli ADR):
- **Isolamento multi-tenant** row-level + Global Scope, con **suite di test di isolamento** dedicata (🔗 ADR-001) — misura cardine contro fughe di dati tra clienti.
- **Controllo accessi** RBAC granulare (`Schema Ruoli e Permessi.md`) + set permessi **bloccato** per vincoli privacy (🔗 ADR-016).
- **Minimizzazione visibilità:** garanzie ricambio non visibili al Tenant (🔗 ADR-004); accesso tecnico limitato a portafoglio ∪ assegnazione (🔗 ADR-007). *Precisazione (🔗 ADR-020): al Tenant resta nascosto il **dato** (righe, nomi dei pezzi, scadenze), non il suo **effetto** sul semaforo del proprio strumento — che è informazione sul suo bene, non sui rapporti commerciali di EasyLab.*
- **Autenticazione forte:** 2FA obbligatoria per ruoli privilegiati (🔗 ADR-012).
- **Cifratura:** TLS in transito (SSL Let's Encrypt); storage documenti privato con **URL firmate a scadenza**; segreti fuori dal repo.
- **Audit/accountability:** activity log su modelli sensibili, impersonation e forzature tracciate.
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
- [ ] **DPA** EasyLab ↔ clienti (art. 28) + raccolta DPA sub-responsabili (DigitalOcean, SMTP, Stripe).
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
