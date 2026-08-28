🏗️ Tech Stack Canvas: Easy Lab

**Documento di Architettura Tecnologica**

*Obiettivo: Sviluppo rapido, scalabile e mantenibile per rilascio V1 a fine Settembre.*

1. Core Framework (Backend)

Il motore dell'applicazione, scelto per la sua robustezza nella gestione di architetture SaaS e rapidità di sviluppo.

- **Framework:** Laravel 13
- **Linguaggio:** PHP 8.2 o superiore
- **Perché:** Ecosistema completo, sicurezza integrata, gestione nativa delle code (fondamentale per l'invio delle "email del futuro" e scadenze) e librerie SaaS già pronte.

2. Frontend & UI (L'Esperienza Utente)

Abbiamo scelto il **TALL Stack**. Questo permette di avere la reattività di una Single Page Application (SPA) senza la complessità di dover sviluppare e mantenere delle API separate.

- Tailwind CSS: Per uno styling rapido, responsivo (mobile-first per i tecnici con QR code) e coerente.
- Alpine.js: Per le interazioni UI leggere lato client (es. apertura modali, dropdown, toggle semafori).
- Laravel Livewire 4: Il cuore dinamico. Permette di creare tabelle filtrabili in tempo reale (es. ricerca del ricambio specifico su tutte le macchine) scrivendo solo codice PHP/Blade.
- Laravel Blade: Il motore di templating pulito e sicuro per le viste.

3. Gestione Dati e Database

La struttura gerarchica (Ente -> Dipartimento -> Sottolaboratorio -> Strumento) richiede un database relazionale solido.

- **Database:** PostgreSQL (consigliato per la gestione complessa di alberi gerarchici) o MySQL 8.0.
- **ORM:** Eloquent (Nativo di Laravel) per gestire le relazioni nidificate in modo elegante (es. $cliente->laboratori()->strumenti()).
- **Caching:** Redis (Fondamentale per memorizzare le sessioni utente, velocizzare il caricamento della dashboard principale e gestire le code delle email).

4. Ecosistema SaaS & Billing

Gli strumenti per gestire i pagamenti, gli abbonamenti e gli accessi in modo automatizzato.

- **Motore Pagamenti:** Stripe
- **Integrazione Laravel:** Laravel Cashier (Stripe) — **installato il 21 Ago 2026: `laravel/cashier v16.7.0` + `stripe/stripe-php v20.3.1`**. Il vincolo `^16.7` non è una preferenza: il supporto a Illuminate `^13` entra in Cashier 16.5.0, la 16.4 si ferma a `^12`. Installato **senza `--with-dependencies`** (stessa lezione del framework, sotto): 4 pacchetti aggiunti, zero update, `guzzlehttp/guzzle` fermo a 7.15.3 — che conta, perché ci sta sotto l'SDK AWS dei documenti B2.
- **Gestione Portale Clienti:** Stripe Hosted Billing Portal (Per far scaricare le fatture e gestire le carte ai clienti senza scrivere una riga di codice lato UI).
  - 🗓️ **Deciso il 27 Ago 2026**: dal portale il cliente **disdice da solo** (🔗 ADR-013, nota in coda ad **ADR-035**). Il percorso a valle è già verificato su staging il 21 Ago — disdetta → webhook → account bloccato e piano decaduto a `free`, col `locked_at` manuale intatto. **Non attuato**: il portale non è ancora collegato, e l'aspetto e le condizioni di disdetta si impostano nella dashboard Stripe.
  - ✅ **Attuato il 28 Ago 2026, e la clausola «Non attuato» qui sopra è superata.** Il portale si apre da **`/abbonamento`**, attraverso l'unica porta `App\Support\Billing\PortaleStripe` — una cucitura di una riga, che esiste per poter provare autorizzazione, throttle e `returnUrl` senza parlare con Stripe. Tre cose vanno lette qui, perché nessuna si deduce guardando la dipendenza:
    - ⛔ **le due rotte stanno FUORI dal gruppo protetto**, accanto a `/bloccato`: un account bloccato per insoluto deve poter **pagare**, e chiuderlo dentro `account.lockout` gli sbarrerebbe l'unica porta da cui si sblocca da solo. Conseguenza da tenere presente: lì non c'è nessun `can:` di rotta, quindi l'autorizzazione (`manage` sull'Account, 🔗 ADR-032) vive **dentro** il componente e dentro il controller. Nella caccia del 28 Ago è emerso che i componenti annidati della top bar ereditavano quel path esente e **aggiravano il lockout**: la pagina usa ora il layout ospite;
    - ⛔ **in impersonazione il portale non si apre.** La sessione sarebbe intestata al customer del **cliente**, e da lì si disdice il suo abbonamento e gli si cambia il metodo di pagamento. Era un difetto vero, trovato e chiuso lo stesso giorno;
    - ⚠️ **la configurazione del portale resta nella dashboard di Stripe, ed è per MODALITÀ**: quali sezioni sono attive (fatture, metodo di pagamento, disdetta) e quale non lo è (il cambio piano, che ADR-035 porta in casa) sono un oggetto in test e un altro in live, e configurarne uno non configura l'altro. Dal codice non si passa nessuna `configuration`, apposta: sarebbe un id di ambiente dentro il repository.
    - ⚠️ E il rientro **non è immediato**: paga il cliente, sblocca il **webhook**; un lockout disposto a mano non si revoca pagando (🔗 ADR-013), e la pagina lo dice invece di promettere il contrario.
  - ⚠️ **Il listino, invece, non resta su Stripe**: 🔗 **ADR-035** (27 Ago 2026) porta la creazione dei piani in una schermata della piattaforma, che scrive **anche** su Stripe. Il portale continua a leggere da Stripe; è il posto dove il piano *nasce* che cambia.
  - ✅ **ADR-035 attuato il 27–28 Ago 2026.** `/piattaforma/piani` è una schermata vera (🔗 Wireframe §7.1): il listino vive nelle tabelle **`piani`** e **`prezzi_piano`**, il governo in `App\Support\Listino\**`, e `App\Support\Piani` — che resta l'unica porta — legge dal database. `config('easylab.piani.catalogo')` **è rimasta ma è solo bootstrap**: la legge una volta la migration di backfill, e da lì in poi la verità è il DB. È lo stesso patto di `config/rbac.php` (ADR-016 §7) con una differenza voluta: qui **non esiste un seeder rilanciabile**, quindi non esiste nemmeno la trappola del riseeding distruttivo. L'unica chiave di config ancora viva è `easylab.piani.predefinito`, che non è il listino ma «con che piano nasce un account».
    - **Un Price su Stripe è immutabile**: cambiare cifra ne crea uno nuovo e archivia il vecchio, e **chi è già abbonato resta al suo** (decisione di prodotto del 27 Ago). È la ragione per cui `prezzi_piano` conserva lo storico: senza, `Piani::perPrice()` smetterebbe di riconoscere il piano di ogni cliente precedente e `accounts.piano` non si riallineerebbe più, in silenzio.
    - **Un piano non si cancella, si archivia.** `accounts.piano` lo referenzia per stringa senza FK: cancellarlo renderebbe «fuori catalogo» — cioè 0 € nell'MRR — ogni cliente rimastoci sopra.
    - La **conciliazione** con Stripe è un bottone e non una riga di `render()`: altrimenti la cabina morirebbe perché un fornitore esterno è giù, o perché le chiavi non sono configurate su quell'ambiente.
  - **Seconda superficie Stripe, dal 28 Ago: il Checkout ospitato**, per il self-signup pubblico di `/registrati` (🔗 ADR-012, Wireframe §7.2). Porta propria (`App\Support\Registrazione\PortaleCheckout`) e non la stessa del Billing Portal, perché qui a valle c'è logica nostra da provare contro esiti che Stripe non darebbe su richiesta — sessione aperta, pagamento non incassato, customer mancante. **L'account nasce solo se il pagamento è riuscito**, e per questo il modulo pubblico non offre piani gratuiti: un piano omaggiato non ha un checkout da superare, e sarebbe l'unica porta del progetto da cui chiunque si crea un Ente e un ruolo `Admin` senza che nessuno lo autorizzi. Il Free (ADR-002) resta, ma si **concede** — dalla cabina o da `easylab:provision-tenant`.
  - ⛔ **Limite dichiarato, al 28 Ago 2026: niente di tutto questo è stato provato su Stripe VERO.** `PortaleStripe` e `PortaleCheckout` sono cuciture con finti in suite; il percorso felice verso la rete si verifica su staging con le chiavi di test, come per `easylab:abbona`. E le **cinque migration** del giro — fra cui il **backfill del listino, che scrive dati** — non sono ancora applicate al database di sviluppo.

5. Pacchetti Core & Moduli Consigliati

Evitiamo di reinventare la ruota. Ecco le librerie standard dell'ecosistema Laravel perfette per i nostri requisiti:

- **Gestione Permessi & Ruoli:** spatie/laravel-permission (Lo standard assoluto per gestire Super Admin, Admin Cliente, Responsabile Reparto).
- **Impersonificazione:** lab404/laravel-impersonate (Pacchetto plug&play per far accedere il Super Admin come qualsiasi altro utente con 1 click).
- **QR Code Generator:** ~~simplesoftwareio/simple-qrcode~~ → **`bacon/bacon-qr-code`** (corretto il 15 Ago 2026, attuando ADR-003). Il wrapper indicato qui è fermo a `bacon ^2.0` mentre il progetto ha già `bacon v3.1.1`, **installata da Fortify** per il QR della verifica in due passaggi: usarlo avrebbe richiesto di retrocedere una dipendenza dell'autenticazione in cambio di una comodità di sintassi. Si usa direttamente la libreria sottostante, con lo stesso idioma di `TwoFactorAuthenticatable` (`SvgImageBackEnd` + `ImageRenderer` + `Writer`) — zero dipendenze nuove. Vedi `App\Support\QrStrumento`.
> **Aggiornamento del framework del 19 Ago 2026 — `laravel/framework` v13.16.1 → v13.26.1.** Fatto per un requisito preciso: le **managed queue** di Laravel Cloud richiedono almeno la 13.19, e con la 13.16 non sarebbero state attivabili (🔗 `Setup Repository e Ambienti.md` §3.3).
>
> ⚠️ **Nota di metodo, pagata sul posto.** Il primo tentativo è stato `composer update laravel/framework --with-dependencies`: ha proposto **32 pacchetti**, fra cui `guzzlehttp/guzzle` da **7 a 8** — un major, e Guzzle sta sotto l'SDK AWS che serve ai documenti su B2 (🔗 ADR-025/026). Un salto che i test **non avrebbero potuto smentire**, perché lo storage nei test è `Storage::fake()` e il client HTTP vero non viene mai esercitato. Rifatto senza `--with-dependencies`: **un solo pacchetto aggiornato**, stesso risultato, superficie di rischio ridotta a ciò che serviva. Suite **784 verdi** su SQLite e Postgres, `composer audit` pulito.

> **Manutenzione dipendenze del 17 Ago 2026.** `composer audit` segnalava **17 avvisi** su tre pacchetti — `guzzlehttp/guzzle` (9, di cui uno *high*: bypass di controlli host, CVE-2026-69246), `guzzlehttp/psr7` (2) e `league/commonmark` (6) — tutti **preesistenti**, non introdotti da dompdf. Risolti con un update mirato: 7 pacchetti, tutti minor/patch dentro lo stesso major, nessuna aggiunta né rimozione. Suite 661 verdi su entrambi i driver dopo l'aggiornamento, `composer audit` pulito. Vale come promemoria di metodo: l'audit va guardato **quando lo si vede**, perché era lì da settimane e nessuno lo aveva letto.

- **Esportazione PDF:** **`barryvdh/laravel-dompdf`** — scelto il 17 Ago 2026, 🔗 **ADR-031**: PHP puro, nessun Chromium da installare e aggiornare su Laravel Cloud (ADR-025). L'alternativa `spatie/laravel-pdf` rende come un browser, ma un foglio A4 non ha bisogno del CSS moderno che quella fedeltà serve — e un PDF non è responsive.
  - *Aggiornato il 28 Ago 2026: i consumatori sono **tre**, non uno* — lo storico di una macchina (`/strumenti/{id}/storico.pdf`), l'**indice dell'archivio d'Ente** (`/documenti/export.pdf`) e l'elenco clienti con l'MRR della cabina. ⛔ I primi due chiedono **`documenti.export_pdf` oltre** al permesso di lettura, e non è formalità: il Tecnico ha `documenti.view` e **non** ha `export_pdf`, quindi registrare l'indice con la sola forma naturale (`can:documenti.view`, copiata dalla riga accanto) gli avrebbe consegnato l'archivio del cliente in un foglio solo. È successo, in questo giro, ed è stato corretto prima del rilascio: ora la guardia è su rotta **e** dentro il controller, e ciascuna delle due è falsificabile da sola.
  - ⚠️ Le esportazioni della cabina escono anche in **CSV**, che dompdf non tocca: un foglio di calcolo esegue ciò che comincia per `=`, `+`, `-` o `@`, quindi passa da `App\Support\Piattaforma\CsvSicuro` — intestazioni comprese, che è la metà dimenticata nella prima stesura.
- **Email (layout e marchio) — 28 Ago 2026:** i template markdown di Laravel sono stati **pubblicati** in `resources/views/vendor/mail/**`, con un tema proprio (`html/themes/easylab.css`, agganciato da `config/mail.php` → `'theme' => 'easylab'`). Il motivo per cui non si è tenuto il layout del pacchetto sta in tre parole del suo piè di pagina — «All rights reserved» — in fondo a ogni email italiana: il piede ora è nostro e in italiano. Sopra ci si è innestato il **marchio per Ente** (🔗 ADR-033), logo e colore impostabili da `/anagrafica/marchio`. Quattro vincoli che vengono dai client di posta e non dal gusto:
  - il branding sta nel **corpo, mai nella busta**: nessun `From`/`Reply-To` per tenant, perché SPF/DKIM/DMARC stanno sul dominio Easy Lab (🔗 ADR-011) e un mittente sul dominio del cliente romperebbe l'allineamento DKIM mandando tutto in spam;
  - il logo è **PNG o JPG, mai SVG** — Gmail, Outlook desktop e Outlook.com non rendono SVG — e viaggia **incorporato via `cid:`**, non linkato: il bucket è privato e non ha URL pubblici;
  - il **piè di pagina dice sempre «Easy Lab»**, anche per un Ente brandizzato: un piede per-tenant renderebbe il messaggio indistinguibile da uno scritto dal cliente, cioè una superficie di phishing che parte dal nostro dominio;
  - le email **non hanno un tema** (🔗 ADR-034): niente `var()` — `CssToInlineStyles` non le risolve — niente `prefers-color-scheme`, e due `<meta>` che dichiarano il documento chiaro perché il client non lo inverta da sé.
- **Task Scheduling:** Cron Job nativi di Laravel (Per il controllo giornaliero delle scadenze garanzie e invio allerte rosse).
  - *Aggiornato il 28 Ago 2026:* lo stesso comando notturno (`easylab:notifica-scadenze`) porta ora anche la transizione a **obsoleta** (🔗 ADR-014), in **un'unica email** con l'elenco invece di una per macchina — decisione di prodotto del 27 Ago, insieme al fatto che **alzando la soglia le macchine «tornano nuove»** (il reset è conservativo: tocca solo ciò che rilegge positivamente).
  - 🔴 **E qui è emerso il difetto più insidioso del giro, che riguarda la piattaforma e non la funzione:** la unique di idempotenza di `avvisi_scadenza` **non conteneva `tenant_id`**. Al trasferimento di una macchina fra Enti (ADR-015) la riga del vecchio proprietario collideva con quella del nuovo e il comando notturno **abortiva** — nessun avviso per tutti gli Enti successivi, ogni giorno, **in silenzio**. Migration additiva, ed è la ragione per cui un job schedulato vuole guardie di unicità che portino sempre il tenant.

6. Infrastruttura e Rilascio (Deploy)

L'ambiente dove "vivrà" l'applicazione, ottimizzato per zero pensieri lato sistemistico.

- **Piattaforma applicativa:** **Laravel Cloud** — deploy da Git, Postgres e Redis/Valkey gestiti (🔗 ADR-025). *Sostituisce l'assunzione iniziale "Laravel Forge su droplet DigitalOcean", che era un default mai eseguito.*

  ⚠️ **Code e scheduler non sono «inclusi», e questa riga lo diceva fino al 21 Ago 2026.** Sono **risorse da creare per ambiente**, a consumo, e non si ereditano da un ambiente all'altro. Lo scheduler è un interruttore sull'App cluster; per le code ci sono tre strade — **managed queue** (compute dedicata, scale-to-zero, $0 a riposo: la nostra scelta), **background process** sull'App cluster (che però impedisce lo scale-to-zero e fa competere i job col traffico web), **worker cluster** (piano Growth). ⛔ Il runner dei **Comandi** non è nessuna delle tre: un `queue:work` lanciato da lì è un container effimero che non sopravvive al deploy. Dettagli e trappole in `Setup Repository e Ambienti.md` §2.1.1.
- **Storage documenti:** **Backblaze B2** (S3-compatible), bucket privato in **regione UE** — Amsterdam `eu-central-003` (🔗 ADR-025/009).
  - *Precisazione dall'attuazione:* il disco applicativo è **`documenti`** e non `s3`, ed è separato per una ragione sola — `throw => true`. Col default di Laravel un upload fallito torna `false` **in silenzio** (la riga compare in elenco e il file non c'è), e in lettura `response()` chiama `readStream()` a header già inviati, cioè produce un **200 troncato** invece di un errore. Cambiare il flag sui dischi condivisi sarebbe stato più semplice e più rischioso.
  - Dal 28 Ago 2026 su quello stesso disco vive anche il **logo email dell'Ente**: è un allegato privato di un tenant come gli altri, e non aveva senso aprirgli un secondo bucket con un secondo DPA.
- **Versionamento:** GitHub o GitLab (Repository privato per il codice sorgente).

> **Sub-responsabili GDPR.** Laravel Cloud e Backblaze trattano entrambi dati dei clienti e vanno nel registro dei trattamenti, con DPA firmati **prima** del go-live (🔗 `Privacy GDPR e Registro Trattamenti.md`). La scelta di B2 al posto dell'object storage incluso in Laravel Cloud aggiunge di proposito un fornitore, in cambio di uno storage circa 3× più economico: il ragionamento completo è in ADR-025.

**Note per lo Sviluppatore:**

- **Strategia Multi-tenant: Single-Database con Row-Level Scoping** (decisione architetturale — vedi `Decisioni Architetturali.md` ADR-001). Un unico database condiviso; l'isolamento è garantito a livello applicativo tramite Global Scope di Eloquent.
- Implementare un **Global Scope** di Eloquent per la logica Multi-tenant. Ogni volta che un utente "Cliente" fa una query, il Global Scope filtrerà automaticamente i dati mostrando SOLO quelli associati al suo tenant_id (o cliente_id), garantendo che non ci siano mai fughe di dati tra laboratori diversi.
- **Gerarchia a due livelli (rivenditori):** lo scoping è duplice. Ogni record "di business" porta sia un `tenant_id` (il laboratorio/ente finale) sia un `reseller_id` (l'Admin/rivenditore proprietario, NULL se cliente diretto EasyLab). Il Global Scope filtra in base al ruolo: il Tenant vede solo il proprio `tenant_id`; il Reseller vede tutti i `tenant_id` sotto il proprio `reseller_id`; il Superadmin EasyLab bypassa lo scope e vede tutto (necessario per le dashboard globali/MRR).
- **Test di isolamento obbligatorio:** prevedere fin da subito un test automatico che verifichi "il tenant A non può mai leggere dati del tenant B" — è il test più critico dell'intero SaaS.
- **Sicurezza QR Code (ADR-003):** le rotte degli strumenti raggiunte da QR devono usare **URL firmate** (`URL::signedRoute`) + middleware `signed` + Policy di autorizzazione. Il QR rimanda al login se l'utente non è autenticato e mostra la scheda solo previa verifica dei permessi tramite Global Scope. Mai una scheda accessibile senza autenticazione.
