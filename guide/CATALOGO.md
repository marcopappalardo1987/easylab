# Catalogo delle guide

Inventario delle funzionalità di Easy Lab e, per ciascuna, la guida da produrre.
Le regole di produzione stanno in `STILE.md`; qui c'è **che cosa** fare e in che
ordine.

L'inventario è ricavato da `routes/web.php`, `app/Livewire/**` e
`config/rbac.php`, non dalla memoria. Quando si aggiunge una funzionalità, si
aggiunge una riga qui.

---

## 1. Dove andranno a finire

Voce di sidebar **«Guida»**, una pagina sola con:

- **indice** a sinistra, raggruppato per argomento (le lettere A–M di questo file);
- **filtro di ricerca testuale** che cerca su titolo, argomento, parole chiave e
  testo della guida;
- per ogni voce, **video e guida scritta nella stessa pagina** — il video in
  testa, sotto il testo con gli stessi passi, così chi non può alzare il volume
  legge e chi ha fretta guarda.

Ne segue che ogni guida è **due prodotti dallo stesso copione**: l'mp4 e un testo.
Le didascalie del manifest sono già i passi scritti, quindi la versione testuale
si genera da `manifest.json` e non si redige a parte — altrimenti le due
divergono al primo ritocco.

Per il filtro servono, per ogni guida: `slug`, `titolo`, `argomento`, `ruoli`,
`parole_chiave[]`, `durata`, `rotte[]`. Sono i campi delle tabelle qui sotto.

⚠️ **Le guide di piattaforma non vanno mostrate ai clienti.** La pagina filtra
per permesso, come ogni altra: chi non ha `tenants.view_all` non vede l'argomento M.

---

## 2. Legenda

| segno | significato |
|---|---|
| ✅ | fatta |
| ▫️ | da fare, nessun ostacolo |
| ⛔ | **bloccata**: serve il login a due fattori nel DB dimostrativo (vedi `STILE.md` §8) |

I ruoli sono quelli di `config/rbac.php`: Developer, Superadmin, Admin,
Responsabile Reparto, Tenant, Tecnico.

⚠️ `cambiare-sede` è girata con un utente **Tenant** e non col Responsabile delle
altre guide: a un Responsabile lo switcher **non compare affatto**. Vedi
[DIFETTI-TROVATI.md](DIFETTI-TROVATI.md) §1.

---

## A. Primi passi — 5 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ✅ | `primo-accesso` | l'invito via email, la password, il primo ingresso | `invito.mostra`, `invito.imposta` | tutti |
| ✅ | `orientarsi` | dashboard, menù di sinistra, campanella delle notifiche, profilo | `dashboard` | tutti |
| ✅ | `tema-e-notifiche` | tema chiaro/scuro/di sistema, quali avvisi ricevere | `settings.notifiche` | tutti |
| ⛔ | `due-fattori` | attivare la verifica in due passaggi, i codici di recupero | `settings.security` | Admin+ |
| ✅ | `cambiare-sede` | lo switcher fra le sedi di uno stesso account | (top bar) | Tenant ⚠️ |

## B. Anagrafica — 3 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `albero-anagrafica` | navigare Ente → dipartimento → laboratorio, briciole di pane | `anagrafica.index` | tutti |
| ▫️ | `creare-nodi` | aggiungere e rinominare dipartimenti e laboratori | `anagrafica.index` | Admin, Responsabile |
| ▫️ | `marchio-ente` | logo e colore con cui escono digest, avvisi e inviti | `anagrafica.marchio` | Admin |

## C. Strumenti — 10 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `elenco-strumenti` | ricerca, filtro per ubicazione, ordinamento, righe per pagina, «solo obsoleti» | `strumenti.index` | tutti |
| ▫️ | `scheda-strumento` | panoramica e le sei linguette | `strumenti.show` | tutti |
| ▫️ | `nuovo-strumento` | crearlo dal laboratorio giusto | `anagrafica.index` | Admin, Responsabile |
| ▫️ | `modificare-strumento` | i campi della scheda, il fornitore, la data di installazione | `strumenti.show` | Admin, Responsabile |
| ▫️ | `spostare-strumento` | spostamento fra laboratori e storico degli spostamenti | `strumenti.show` | Admin, Responsabile |
| ▫️ | `semaforo-forzato` | forzare lo stato e perché serve una motivazione | `strumenti.show` | Admin, Responsabile |
| ▫️ | `per-modello` | dato un modello, dove sono installate le sue unità e quante | `strumenti.modelli` | tutti |
| ▫️ | `etichetta-qr` | generare, stampare e applicare l'adesivo | `strumenti.qr` | Admin, Responsabile |
| ▫️ | `import-csv` | carica → anteprima con errori riga per riga → importa le valide | `strumenti.import` | Admin |
| ▫️ | `storico-pdf` | il PDF con tutta la vita della macchina | `strumenti.storico-pdf` | tutti |

## D. Interventi — 3 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ✅ | `intervento` | **il pilota**: ricerca → scheda → nuovo intervento | `strumenti.index`, `strumenti.show` | Responsabile, Tecnico |
| ▫️ | `completare-intervento` | «Fatto», riaprire, correggere, i cinque tipi | `strumenti.show` | Responsabile, Tecnico |
| ▫️ | `scadenzario` | tutto ciò che è aperto su tutte le macchine, in un elenco solo | `scadenzario.index` | tutti |

## E. Garanzie — 2 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `garanzia-macchina` | copertura della macchina, scadenza, soggetto | `strumenti.show` | Admin, Responsabile |
| ▫️ | `garanzia-ricambio` | copertura del pezzo montato, e chi la vede | `strumenti.show` | Admin, Responsabile, Tenant |

## F. Ricambi — 3 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `montare-ricambio` | annotare il pezzo usato durante un intervento | `strumenti.show` | Responsabile, Tecnico |
| ▫️ | `catalogo-ricambi` | il catalogo dei pezzi e le sue schede | `ricambi.index` | Admin, Responsabile |
| ▫️ | `dove-e-montato` | ricerca incrociata: dato un pezzo, su quali macchine sta | `ricambi.index` | tutti |

## G. Documenti — 2 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `allegare-documenti` | manuali, certificati e rapporti sulla scheda della macchina | `strumenti.show` | Admin, Responsabile, Tenant |
| ▫️ | `archivio-documenti` | l'archivio d'Ente, la ricerca, lo scarico e l'esportazione | `documenti.index`, `documenti.download`, `documenti.export-pdf` | tutti |

## H. Fornitori — 1 guida

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `fornitori` | anagrafica fornitori e come si lega alla macchina | `fornitori.index` | tutti (sola lettura per Tenant) |

## I. Persone e permessi — 2 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `persone-ente` | invitare una persona, assegnarle i reparti, toglierla | `utenti.index` | Admin |
| ▫️ | `chi-vede-cosa` | i sei ruoli spiegati, senza editor: che cosa cambia in pratica | — | Admin |

## J. In laboratorio, col telefono — 2 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `vista-campo` | «inquadra il QR» e «i miei interventi» | `campo.index` | Tecnico, Responsabile |
| ▫️ | `scansione-qr` | dall'adesivo alla scheda della macchina | `qr.strumento` | tutti con `qr.scan` |

## K. Abbonamento — 2 guide

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `abbonamento` | il piano, i limiti, il portale di fatturazione | `abbonamento.index`, `abbonamento.portale` | Admin |
| ▫️ | `account-bloccato` | che cosa si vede a insoluto e come si esce | `bloccato`, `bloccato.passa` | Admin |

## L. Registrazione — 1 guida

| | slug | contenuto | rotte | ruoli |
|---|---|---|---|---|
| ▫️ | `registrarsi` | dal Payment Link alla propria sede attiva | `registrazione.*`, `pagamento.ricevuto` | pubblico |

## M. Piattaforma (interna EasyLab) — 10 guide, **tutte bloccate**

Non compaiono nella Guida dei clienti.

| | slug | contenuto | rotte |
|---|---|---|---|
| ⛔ | `cabina-di-regia` | la home di piattaforma: clienti, preferiti, provisioning | `piattaforma.index` |
| ⛔ | `parco-clienti` | il parco macchine di tutti i clienti, in sola lettura | `piattaforma.parco` |
| ⛔ | `parco-scadenzario` | che cosa scade su tutto il parco | `piattaforma.parco.scadenzario` |
| ⛔ | `parco-ricambi` | i ricambi su tutto il parco | `piattaforma.parco.ricambi` |
| ⛔ | `impersonazione` | entrare come un cliente, e i limiti | `piattaforma.parco.impersona` |
| ⛔ | `tecnici-easylab` | i tecnici e i clienti su cui lavorano | `piattaforma.tecnici` |
| ⛔ | `editor-ruoli` | la matrice ruolo→permesso, le celle «personalizzato», le orfane | `piattaforma.ruoli` |
| ⛔ | `registro-audit` | chi ha fatto che cosa | `piattaforma.audit` |
| ⛔ | `errori` | l'error tracker interno | `piattaforma.errori`, `piattaforma.errori.mostra` |
| ⛔ | `listino` | i piani commerciali e i loro tetti | `piattaforma.piani` |

---

## 3. Conto

| stato | guide |
|---|---|
| ✅ fatte | **5** |
| ▫️ da fare, nessun ostacolo | **30** |
| ⛔ bloccate dal 2FA | **11** |
| **totale** | **46** |

A 1–2 ore l'una una volta rodata l'impalcatura, sono **circa 35–70 ore** di
lavoro. Sbloccare il 2FA (mezza giornata) libera un quarto del catalogo e
aggiunge la guida `due-fattori`, che al momento non esiste in nessuna forma.

---

## 4. Ordine consigliato

1. ~~**A**~~ — **fatto** il 6 Set 2026, quattro guide su cinque (`due-fattori`
   resta bloccata). Poi **B + C1/C2**, e la pagina Guida ha già senso di esistere.
2. **D + E + F + G** — il ciclo di vita della macchina, cioè il valore del
   prodotto. `intervento` è già qui.
3. **H + I + J + K + L** — il contorno: fornitori, persone, telefono, soldi.
4. **Sbloccare il 2FA**, poi **M** e `due-fattori`.

⚠️ Il resto di C (import CSV, QR, storico PDF) può slittare: sono passaggi che si
fanno una volta sola in fase di avvio, e chi li fa di solito è affiancato.
