<?php

use App\Http\Controllers\AccessoQr;
use App\Http\Controllers\AperturaPortaleStripe;
use App\Http\Controllers\EsportaElencoDocumenti;
use App\Http\Controllers\EsportaStoricoPdf;
use App\Http\Controllers\FugaDaLockout;
use App\Http\Controllers\ImpostaPasswordInvito;
use App\Http\Controllers\PaginaBloccato;
use App\Http\Controllers\RegistrazionePubblica;
use App\Http\Controllers\ScaricaDocumento;
use App\Livewire\Anagrafica\Albero;
use App\Livewire\Anagrafica\MarchioEnte;
use App\Livewire\Billing\PaginaAbbonamento;
use App\Livewire\Campo\Home as CampoHome;
use App\Livewire\Dashboard\Home as DashboardHome;
use App\Livewire\Documenti\ElencoDocumenti;
use App\Livewire\Fornitori\ElencoFornitori;
use App\Livewire\Interventi\Scadenzario;
use App\Livewire\Piattaforma\Cabina;
use App\Livewire\Piattaforma\EditorRuoli;
use App\Livewire\Piattaforma\Errori;
use App\Livewire\Piattaforma\Listino;
use App\Livewire\Piattaforma\ParcoGlobale;
use App\Livewire\Piattaforma\RegistroAudit;
use App\Livewire\Piattaforma\SchedaErrore;
use App\Livewire\Ricambi\RicercaRicambi;
use App\Livewire\Settings\PreferenzeNotifiche;
use App\Livewire\Settings\TwoFactorAuthentication;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Livewire\Strumenti\ImportStrumenti;
use App\Livewire\Strumenti\ModelliStrumenti;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Livewire\Strumenti\StampaQr;
use Illuminate\Support\Facades\Route;

// Login, logout, reset password, verifica email e 2FA sono registrati da Fortify
// (vedi App\Providers\FortifyServiceProvider). La root rimanda alla login;
// dopo l'accesso Fortify reindirizza a `home` = /dashboard.
Route::redirect('/', '/login');

// Area autenticata. Il middleware two-factor.enforce forza il 2FA sui ruoli
// privilegiati (si auto-esclude da settings.security per consentirne l'attivazione).
// `account.lockout` PRIMA di `two-factor.enforce`: un Admin bloccato e senza
// 2FA deve finire su /bloccato, non sul setup della sicurezza — la condizione
// più forte parla per prima (ADR-013).
Route::middleware(['auth', 'account.lockout', 'two-factor.enforce'])->group(function () {
    // La pagina di atterraggio di OGNI ruolo (S6): niente `can:`, perché è
    // l'unica che ogni utente autenticato deve poter aprire — ci mandano
    // Fortify dopo il login, `SwitcherEnte::passa()` a ogni cambio sede e
    // `FugaDaLockout`. Il permesso si chiede blocco per blocco dentro la vista,
    // come impone la Policy di Code Review per le viste che compongono più
    // aree. ⚠️ Regge finché il componente non ha AZIONI: una con `skipRender()`
    // non arriverebbe a `render()`, e qui non c'è un `can:` di rotta a
    // raccoglierla.
    Route::get('/dashboard', DashboardHome::class)->name('dashboard');
    Route::get('/anagrafica', Albero::class)
        ->middleware('can:unita_organizzativa.view')
        ->name('anagrafica.index');
    // Il marchio dell'Ente nelle email. `unita_organizzativa.update` e non un
    // permesso nuovo: è un'impostazione del nodo Ente, come la soglia di
    // obsolescenza, e chi rinomina l'Ente ne governa già l'identità.
    Route::get('/anagrafica/marchio', MarchioEnte::class)
        ->middleware('can:unita_organizzativa.update')
        ->name('anagrafica.marchio');
    Route::get('/strumenti', ElencoStrumenti::class)
        ->middleware('can:strumenti.view')
        ->name('strumenti.index');
    // Prima della rotta {strumento}: altrimenti "modelli"/"import" verrebbero
    // risolti come id dello strumento.
    Route::get('/strumenti/modelli', ModelliStrumenti::class)
        ->middleware('can:strumenti.view')
        ->name('strumenti.modelli');
    Route::get('/strumenti/import', ImportStrumenti::class)
        ->middleware('can:strumenti.create')
        ->name('strumenti.import');
    Route::get('/strumenti/{strumento}', SchedaStrumento::class)
        ->middleware('can:strumenti.view')
        ->name('strumenti.show');
    // Foglio da stampare e applicare sulla macchina (ADR-003).
    Route::get('/strumenti/{strumento}/qr', StampaQr::class)
        ->middleware('can:strumenti.qr_generate')
        ->name('strumenti.qr');
    // Archivio documentale d'Ente: l'elenco cross-macchina di ciò che il tab
    // Documenti mostra una macchina alla volta. Nessun dato nuovo — la stessa
    // query senza il vincolo sullo strumento — quindi `documenti.view` basta:
    // QUALI righe si vedono lo dicono i global scope, non il permesso.
    Route::get('/documenti', ElencoDocumenti::class)
        ->middleware('can:documenti.view')
        ->name('documenti.index');
    // Indice PDF dell'archivio, con gli stessi filtri della pagina.
    //
    // ⛔ DUE `can:`, e il secondo è quello che conta: `documenti.view` è «puoi
    // vedere l'elenco», `documenti.export_pdf` è «puoi portartene via un
    // foglio». Il **Tecnico ha il primo e non il secondo** — con la sola forma
    // naturale (`can:documenti.view`, copiata dalla riga qui sopra) si
    // porterebbe via in PDF l'intero archivio documentale del cliente. È la
    // stessa coppia di `strumenti.storico-pdf`, e il Tecnico è ciò che rende
    // la guardia falsificabile invece che decorativa.
    //
    // ⚠️ Registrata **prima** di `/documenti/{documento}`: hanno la stessa
    // forma a due segmenti. Il `whereNumber` sul download è già una seconda
    // rete, ma l'ordine resta la prima.
    Route::get('/documenti/export.pdf', EsportaElencoDocumenti::class)
        ->middleware(['can:documenti.view', 'can:documenti.export_pdf'])
        ->name('documenti.export-pdf');
    // Download mediato dall'applicazione (ADR-026): l'autorizzazione si
    // ricontrolla a ogni richiesta, e il binding scopato dà 404 fuori Ente.
    //
    // ⛔ `whereNumber` è difesa in profondità, non decorazione: `/documenti` e
    // `/documenti/qualcosa` hanno la stessa forma, e senza il vincolo un
    // segmento non numerico finirebbe qui a farsi risolvere come id, dando un
    // 404 dal messaggio incomprensibile invece della pagina giusta.
    Route::get('/documenti/{documento}', ScaricaDocumento::class)
        ->whereNumber('documento')
        ->middleware('can:documenti.view')
        ->name('documenti.download');
    Route::get('/fornitori', ElencoFornitori::class)
        ->middleware('can:fornitori.view')
        ->name('fornitori.index');
    // Ricerca incrociata «dove è montato questo pezzo» (ADR-008). Pagina propria
    // e non un tab della scheda: la domanda parte dal pezzo e attraversa tutte
    // le macchine, mentre il tab Ricambi vive dentro una macchina sola.
    Route::get('/ricambi', RicercaRicambi::class)
        ->middleware('can:ricambi.view')
        ->name('ricambi.index');
    // Vista di campo (ADR-003/007, Wireframe §3): il punto di partenza di chi
    // arriva col telefono. `interventi.view` e non il ruolo Tecnico — la pagina
    // mostra «i miei interventi», quindi per chi non ne ha è semplicemente
    // vuota, e legare una rotta a un nome di ruolo è ciò che il progetto evita
    // ovunque tranne dove è inevitabile.
    Route::get('/campo', CampoHome::class)
        ->middleware('can:interventi.view')
        ->name('campo.index');
    // Scadenzario aggregato: «cosa scade su tutto il parco». `/campo` risponde
    // a «cosa devo fare io» e il tab della scheda a «cosa è successo a questa
    // macchina» — questa è la terza domanda, che finora non aveva una pagina.
    // `interventi.view` per la stessa ragione di `/campo`: il perimetro lo
    // decidono i global scope, non il nome di un ruolo nella rotta.
    Route::get('/scadenzario', Scadenzario::class)
        ->middleware('can:interventi.view')
        ->name('scadenzario.index');
    // Storico macchina in PDF (ADR-031). Due middleware perché sono due
    // domande diverse: `strumenti.view` è «puoi vedere le macchine», e il
    // route-model binding scopato dice QUALE; `documenti.export_pdf` è «puoi
    // portartene via un foglio», che il Tecnico non ha.
    Route::get('/strumenti/{strumento}/storico.pdf', EsportaStoricoPdf::class)
        ->middleware(['can:strumenti.view', 'can:documenti.export_pdf'])
        ->name('strumenti.storico-pdf');
    // 🔴 La cabina di regia (S6): l'unica schermata che guarda oltre il proprio
    // Ente. Il nome è `piattaforma` e non `superadmin` perché il progetto evita
    // di legare le rotte a un nome di ruolo — il permesso dice chi entra, e la
    // matrice è modificabile a runtime (ADR-016). Stessa scelta di `/campo`.
    //
    // DENTRO il gruppo protetto, e non fuori: il Superadmin è un utente
    // tenant-bound con un account proprio (ADR-018 + SuperadminSeeder), quindi
    // se quell'account fosse in lockout deve vedere /bloccato come chiunque, e
    // il 2FA è obbligatorio per il suo ruolo.
    Route::get('/piattaforma', Cabina::class)
        ->middleware('can:tenants.view_all')
        ->name('piattaforma.index');

    // Il registro di audit, stessa porta e stesso permesso della cabina.
    // ⚠️ **Non** `can:audit.view`, che pure esiste a catalogo: quel permesso ce
    // l'ha anche l'Admin e non è nel set bloccato, quindi l'editor permessi di
    // S6 potrà ridistribuirlo — un gate cross-tenant su un permesso
    // ridistribuibile è una falla ad attivazione differita. `audit.view` resta
    // il permesso della futura vista per-cliente.
    Route::get('/piattaforma/audit', RegistroAudit::class)
        ->middleware('can:tenants.view_all')
        ->name('piattaforma.audit');

    // L'editor della matrice ruolo→permesso, e qui il permesso è **un altro**:
    // `can:roles.manage`, non `can:tenants.view_all`. È l'opposto della scelta
    // fatta due righe più su, per lo stesso criterio: si gata su un permesso del
    // **set bloccato**. Il registro ci arrivò per esclusione (`audit.view` è
    // ridistribuibile, quindi non regge), questo ci arriva per elezione —
    // `roles.manage` è bloccato, cioè non ridistribuibile da questa stessa
    // pagina, ed è il permesso che ADR-016 nomina per questa UI.
    //
    // ⚠️ E **non** in AND con `can:tenants.view_all`: non aggiungerebbe
    // protezione (chi passa il primo ha già il secondo) e darebbe un modo di
    // rompere la pagina. Il `can:` di rotta non è ridondante rispetto al
    // `Gate::authorize()` dentro `render()`: per un'azione che scrive — e qui ne
    // arriveranno 324 — la guardia che regge è quella di **rotta**, perché
    // `skipRender()` fa saltare del tutto il `render()` (ADR-018).
    Route::get('/piattaforma/ruoli', EditorRuoli::class)
        ->middleware('can:'.EditorRuoli::PERMESSO)
        ->name('piattaforma.ruoli');

    // Il listino dei piani (🔗 ADR-035, 27 Ago 2026). `billing.manage_global` e
    // non un permesso nuovo: creare un piano è **fissare un prezzo**, e quel
    // permesso è già del solo Developer/Superadmin ed è nel **set bloccato**,
    // quindi l'editor di runtime non può regalarlo a un ruolo cliente. Un
    // permesso dedicato sarebbe l'ottavo bloccato e imporrebbe un riseeding
    // per dire la stessa cosa.
    Route::get('/piattaforma/piani', Listino::class)
        ->middleware('can:'.Listino::PERMESSO)
        ->name('piattaforma.piani');

    // 🔴 Il parco di TUTTI i clienti, in SOLA LETTURA (🔗 ADR-037).
    //
    // ⛔ La riga che tiene in piedi ADR-018: qui si **guarda** oltre il proprio
    // Ente, non si scrive. Ogni modifica continua a passare
    // dall'impersonazione, che è per cliente e lascia una traccia con dentro
    // chi la stava facendo e per conto di chi — il contesto che una scrittura
    // cross-cliente perderebbe proprio dove serve di più.
    //
    // `tenants.view_all` e non un permesso nuovo: significa letteralmente «vedi
    // oltre il tuo Ente», è già del solo Developer/Superadmin ed è nel set
    // bloccato, quindi l'editor dei ruoli non può regalarlo a un cliente.
    Route::get('/piattaforma/parco', ParcoGlobale::class)
        ->middleware('can:'.ParcoGlobale::PERMESSO)
        ->name('piattaforma.parco');

    // 🔴 L'error tracker interno (S6), e qui il permesso è **un terzo ancora**:
    // `can:system.logs.view`. Il criterio non cambia — si gata su un permesso
    // del **set bloccato** — ma per la prima volta la partizione che ne esce
    // **non coincide** con quella di `tenants.view_all`: `system.logs.view` è
    // del **solo Developer**, e il Superadmin ne è escluso per eccezione
    // esplicita in `config/rbac.php` pur avendo ogni altro permesso di
    // piattaforma.
    //
    // ⚠️ È quindi la prima rotta del progetto che il Superadmin non può aprire,
    // ed è deliberato: da qui si legge ciò che si è rotto in **ogni** Ente, con
    // dentro messaggi, percorsi e input di richiesta. Aprirla anche a lui è un
    // commit su `config/rbac.php` più un riseeding, non un click — il permesso
    // è bloccato, quindi l'editor della matrice non lo redistribuisce.
    //
    // Dentro il gruppo protetto come le altre tre: il Developer è un utente
    // tenant-bound con un account proprio (ADR-018), e un account in lockout
    // deve vedere /bloccato anche da qui.
    Route::get('/piattaforma/errori', Errori::class)
        ->middleware('can:'.Errori::PERMESSO)
        ->name('piattaforma.errori');

    // La scheda di una issue, e **una rotta a sé** invece di un dettaglio
    // espanso dentro l'elenco. La ragione prima è meccanica: elenco e occorrenze
    // sono entrambi paginati e `WithPagination` ha un `page` solo — nello stesso
    // componente le due paginazioni collidono. È la stessa collisione per cui il
    // registro di audit non è un tab della cabina.
    //
    // ⚠️ Il `can:` si **riscrive**, e non si eredita da nessuna parte: la
    // sicurezza di questo progetto è per-URL, e un URL digitato a mano non passa
    // dall'elenco. Il permesso è lo stesso e si legge dalla stessa costante,
    // perché le due pagine mostrano lo stesso dato: gatarle diversamente
    // lascerebbe aperta la scheda a chi l'elenco rifiuta.
    //
    // Route-model binding sull'id: una issue potata dal blocco 7 mentre qualcuno
    // ha il link aperto dà **404**, che è il verso giusto — meglio di una scheda
    // vuota per una riga che non c'è più.
    Route::get('/piattaforma/errori/{errore}', SchedaErrore::class)
        ->middleware('can:'.Errori::PERMESSO)
        ->name('piattaforma.errori.mostra');

    Route::get('/settings/security', TwoFactorAuthentication::class)->name('settings.security');
    // Nessun `can:`: qui si governa la propria casella di posta, non un dato
    // dell'Ente (ADR-011). Un permesso significherebbe che qualcuno può
    // impedirti di opporti agli invii.
    Route::get('/settings/notifiche', PreferenzeNotifiche::class)->name('settings.notifiche');
});

/*
 * Accesso da QR (ADR-003). L'ordine dei middleware È la regola:
 *
 *   signed → l'URL non è stato costruito a mano da chi ha letto un token;
 *   auth   → **mai una scheda senza autenticazione**, nemmeno con firma valida.
 *            Chi arriva da sloggato viene mandato al login e poi riportato qui
 *            (`intended`), e funziona proprio perché la firma non scade;
 *   can    → e nemmeno senza il permesso di scansionare.
 *
 * La scheda vera non la serve questa rotta: si limita a tradurre token → id e a
 * rimandare a `strumenti.show`, che è già gatata e passa dai global scope. Così
 * esiste UNA sola pagina scheda con UNA sola catena di autorizzazione: una
 * seconda superficie sarebbe una seconda occasione di sbagliarla.
 *
 * Fuori dal gruppo `two-factor.enforce` per la stessa ragione dell'uscita da
 * impersonazione: chi scansiona in reparto col telefono non deve trovarsi
 * bloccato da un setup che riguarda i ruoli privilegiati.
 */
/*
 * Invito: l'utente creato dal provisioning imposta la propria password
 * (ADR-012). Fuori da ogni gruppo protetto perché l'invitato NON è
 * autenticato — non conosce la password che ha in pancia.
 *
 * `signed` davanti a tutto, come nel QR (ADR-003): la firma copre l'id e la
 * scadenza, quindi manometterli invalida l'URL prima del route-model binding.
 * `guest` perché chi è già dentro non ha niente da impostare: viene rimandato
 * alla dashboard invece di vedere un form fuori contesto. Il `throttle` sta
 * solo sul POST — la firma è già il gate, il limite serve contro il
 * martellamento della validazione.
 */
Route::middleware(['signed', 'guest'])->group(function () {
    Route::get('/invito/{user}', [ImpostaPasswordInvito::class, 'mostra'])
        ->whereNumber('user')->name('invito.mostra');
    Route::post('/invito/{user}', [ImpostaPasswordInvito::class, 'imposta'])
        ->whereNumber('user')->middleware('throttle:6,1')->name('invito.imposta');
});

/*
 | 🔴 Self-signup pubblico — `/registrati` (🔗 ADR-012, ADR-032).
 |
 | **Tre gruppi e non uno**, perché i tre tratti hanno tre gate diversi: il
 | modulo è aperto, i passi intermedi sono firmati, il ritorno da Stripe è
 | firmato ma **non** `guest`.
 |
 | ⛔ **Nessun `can:`, in nessuno dei tre.** Non è una svista ed è l'unica forma
 | possibile: chi si registra **non esiste** nel database, quindi non ha ruoli e
 | non può avere permessi. Il gate è `guest` + `signed` + `throttle`, esattamente
 | come `/invito/{user}` (ADR-012) e `/q/{token}` (ADR-003).
 |
 | ⛔ **E nessuna di queste rotte sta nel gruppo `auth`**, quindi nessuna passa
 | da `account.lockout` né da `two-factor.enforce` — che è la risposta alla
 | domanda «e l'ordine dei middleware col 2FA?». I due middleware sono
 | dichiarati sul gruppo autenticato, non globalmente, e qui non c'è nessuna
 | sessione da proteggere: l'unico caso in cui un utente autenticato tocca
 | queste rotte è il ritorno da Stripe, dove passare da `two-factor.enforce`
 | significherebbe **perdere il completamento di un pagamento già incassato**
 | per mandarlo al setup della sicurezza di un altro account.
 |
 | ⚠️ Il ruolo `Admin` che nascerà è `two_factor_required`: al primo login
 | `two-factor.enforce` porterà su `/settings/security`. È il comportamento
 | voluto, e l'email di benvenuto lo dice per non farlo sembrare un guasto.
 */
Route::middleware('guest')->group(function () {
    Route::get('/registrati', [RegistrazionePubblica::class, 'mostra'])
        ->name('registrazione.mostra');
    // ⛔ Il `throttle` sta sul POST e usa il limiter **nominato**
    // `registrazione` (App\Providers\AppServiceProvider): due chiavi, IP ed
    // email. Un `throttle:3,1` inline avrebbe la sola chiave IP, che si aggira
    // con un proxy.
    Route::post('/registrati', [RegistrazionePubblica::class, 'avvia'])
        ->middleware('throttle:registrazione')
        ->name('registrazione.avvia');
    Route::get('/registrati/controlla-email', [RegistrazionePubblica::class, 'controllaEmail'])
        ->name('registrazione.controlla-email');
});

/*
 | `signed` davanti a tutto, come nell'invito e nel QR: la firma copre id e
 | scadenza e cade **prima** del route-model binding, quindi da qui non si
 | enumerano le registrazioni pendenti — un id manomesso dà 403, non un 404 che
 | direbbe «questa riga non c'è, prova la prossima».
 |
 | `whereNumber` è difesa in profondità: senza, `/registrazione/qualcosa/...`
 | arriverebbe al binding a farsi risolvere come id.
 */
Route::middleware(['signed', 'guest'])->group(function () {
    Route::get('/registrazione/{registrazione}/verifica', [RegistrazionePubblica::class, 'verifica'])
        ->whereNumber('registrazione')->name('registrazione.verifica');
    Route::get('/registrazione/{registrazione}/pagamento', [RegistrazionePubblica::class, 'pagamento'])
        ->whereNumber('registrazione')->name('registrazione.pagamento');
    // Il POST apre una sessione su Stripe, cioè crea un oggetto su un servizio
    // esterno: il limite serve contro il martellamento, non contro l'accesso —
    // quello lo tiene già la firma.
    Route::post('/registrazione/{registrazione}/pagamento', [RegistrazionePubblica::class, 'versoStripe'])
        ->whereNumber('registrazione')->middleware('throttle:6,1')->name('registrazione.verso-stripe');
});

// ⚠️ **FUORI dal gruppo `guest`**, e non è una dimenticanza: Stripe rimanda qui
// il browser, e un visitatore già autenticato con un altro account verrebbe
// sbattuto sulla dashboard perdendo il completamento — mentre il pagamento è
// già avvenuto. La firma è il gate; l'azione è idempotente e non autentica
// nessuno. Il webhook resta la rete che chiude il caso «scheda chiusa».
Route::get('/registrazione/{registrazione}/completata', [RegistrazionePubblica::class, 'completata'])
    ->middleware(['signed', 'throttle:30,1'])
    ->whereNumber('registrazione')->name('registrazione.completata');

// Niente `account.lockout` qui, e non è un buco: questa rotta traduce solo
// token → id e REINDIRIZZA a `strumenti.show`, che sta nel gruppo protetto —
// il bloccato rimbalza lì (ADR-013).
Route::middleware(['signed', 'auth', 'can:qr.scan'])->group(function () {
    Route::get('/q/{token}', AccessoQr::class)->name('qr.strumento');
});

// Impersonation (lab404) — rotte gate-protette da canImpersonate, ancora SENZA UI.
// Fuori dal gruppo two-factor.enforce così la rotta di uscita resta sempre raggiungibile.
Route::middleware('auth')->group(function () {
    Route::impersonate();

    // Lockout (ADR-013): la pagina di stato e la fuga verso una sede sana
    // stanno FUORI dal gruppo protetto per COLLOCAZIONE, non per un'esclusione
    // `routeIs` nel middleware — quella non varrebbe sugli update Livewire,
    // questa non ha buchi. `{ente}` è un id nudo: il route-model binding
    // passerebbe dal TenantScope del bloccato (fail-closed → 404 sistematico).
    // ⛔ L'abbonamento sta FUORI dal gruppo protetto insieme a `/bloccato`, e
    // non è una svista: un account bloccato per insoluto deve poter **pagare**
    // — chiuderlo dentro `account.lockout` significherebbe sbarrare al cliente
    // l'unica porta da cui può sbloccarsi da solo. Conseguenza da tenere
    // presente: qui non c'è nessun `can:` di rotta, quindi l'autorizzazione
    // (`manage` sull'Account, ADR-032) va scritta DENTRO il componente e dentro
    // il controller, e provata lì.
    Route::get('/abbonamento', PaginaAbbonamento::class)->name('abbonamento.index');
    Route::post('/abbonamento/portale', AperturaPortaleStripe::class)
        ->middleware('throttle:10,1')
        ->name('abbonamento.portale');
    Route::get('/bloccato', PaginaBloccato::class)->name('bloccato');
    Route::post('/bloccato/passa/{ente}', FugaDaLockout::class)
        ->whereNumber('ente')->name('bloccato.passa');
});

/*
 | 🔬 Il banco dei componenti — `/design-system` (F1.5 del restyling, 🔗 ADR-034,
 | DS §8.5).
 |
 | **Perché esiste.** Guardare un componente nei due temi richiedeva di
 | autenticarsi sul database di sviluppo — che contiene dati di lavoro reali — e
 | di avere in pancia uno strumento obsoleto, uno forzato e una tabella piena:
 | cioè una verifica non deterministica su dati che nessuno controlla. Ogni
 | agente delle fasi F2 e F4 si è costruito un banco statico usa-e-getta, e
 | quattro l'hanno fatto in quattro modi diversi. Questa rotta è quella cosa
 | sola, permanente: monta ogni componente in ogni suo stato con oggetti
 | costruiti **in memoria**, e non tocca un dato.
 |
 | 🔴 **In produzione la rotta NON ESISTE — 404, non 403.** La differenza non è
 | estetica: un 403 dichiarerebbe che quella pagina c'è e che qualcuno la può
 | aprire, cioè un invito a bussare. Qui il `Route::view()` non viene proprio
 | registrato, quindi il router non ha nulla da negare.
 |
 | ⚠️ **È una lista di ammessi, non una lista di esclusi**, ed è la sola forma
 | che regge: `! environment('production')` avrebbe pubblicato il banco anche su
 | **staging**, che è un ambiente raggiungibile da internet, e ogni ambiente
 | inventato domani sarebbe dentro per default invece che fuori.
 |   · `local`   — l'unico posto in cui il banco serve a qualcuno: si apre, si guarda;
 |   · `testing` — perché `BancoTest` **renda davvero la pagina**. Senza, un
 |                 errore di sintassi in un Blade del banco non lo scoprirebbe
 |                 nessun test, e un banco che va in 500 si scopre nel momento
 |                 peggiore, cioè quando lo si apre per verificare altro.
 |
 | ⛔ **Nessun `auth`, nessun `can:`, e non è una dimenticanza.** La rotta è
 | aperta **perché non esiste in produzione**, non perché sia stata autorizzata:
 | il banco non legge né scrive un solo dato di dominio. Aggiungere qui un
 | permesso sposterebbe la protezione su una regola RBAC modificabile a runtime
 | (ADR-016) — cioè la renderebbe più debole, non più forte.
 */
if (app()->environment(['local', 'testing'])) {
    Route::view('/design-system', 'banco.index')->name('banco');
}
