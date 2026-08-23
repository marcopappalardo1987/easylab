<?php

namespace App\Livewire\Piattaforma;

use App\Models\User;
use App\Support\Rbac;
use App\Support\Rbac\MatriceRuoli;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Permission;

/**
 * 🔴 L'editor della matrice ruolo→permesso (S6 — ADR-016).
 *
 * La matrice intera resa in tabella, e una cella alla volta modificabile. È
 * nata in tre tappe — guscio gatato, griglia in sola lettura, e solo alla fine
 * i controlli — ed è la stessa sequenza di `Cabina` e `RegistroAudit`: una
 * pagina che non mostra nulla rende il gate **dimostrabile prima** che ci sia
 * qualcosa da proteggere, e una griglia in sola lettura permette di verificare a
 * occhio, su staging, che `MatriceRuoli::stato()` dica il vero **prima** che
 * esista un bottone che scrive. L'ordine opposto mette la guardia addosso a una
 * vista già scritta, e la prova diventa «non sembra rotto».
 *
 * ## Si salva per cella, e non c'è un bottone «salva»
 *
 * ⚠️ **È l'unica forma in cui la guardia del set bloccato è strutturale.** Un
 * «salva la riga» accetterebbe un array dal browser, e allora un permesso
 * bloccato potrebbe essere *omesso* dall'array invece che revocato — la revoca
 * per omissione, contro cui nessuna guardia scritta sulle celle presenti può
 * niente. La lezione è già scritta in `AmministraAccount`: «Un elenco derivato
 * dal dato che deve difendere non difende niente». In più toglie la
 * sovrapposizione fra due amministratori invece di gestirla: ogni scrittura è
 * una riga sola del pivot, e sulla stessa cella vince l'ultimo — con **entrambi
 * i gesti nel registro**, quindi la sequenza si ricostruisce.
 *
 * Nessun locking ottimistico, e va detto perché è il primo posto dove si
 * guarderebbe: `roles` ha i timestamp, ma un `attach`/`detach` su una
 * `belongsToMany` **non tocca** `roles.updated_at`. Un token di versione
 * andrebbe inventato, cioè si costruirebbe il meccanismo *e* la cosa che
 * protegge.
 *
 * ## L'orientamento della griglia, che è una decisione e non un caso
 *
 * **Sei ruoli in colonna, cinquantaquattro permessi in riga**, esattamente come
 * la tabella di `Schema Ruoli §5`. La ragione è una sola e basta: questa pagina
 * verrà affiancata a quel documento — è il posto dove si scopre che il
 * documento è vecchio (e oggi lo è) — e chi confronta i due non deve trasporre a
 * mente. Ne segue un attrito di vocabolario da conoscere prima di leggere il
 * codice: il piano e `config/rbac.php` chiamano il set bloccato «una **colonna**»
 * nel senso astratto di *un permesso attraverso tutti i ruoli*, e in questa resa
 * quel permesso è una **riga**. Il rovescio per il ruolo protetto: «la riga del
 * Developer» dei documenti è qui una colonna. Le due guardie restano quelle —
 * `Rbac::isLocked()` per permesso, `Rbac::isRuoloProtetto()` per ruolo — e non
 * si trasformano insieme all'orientamento.
 *
 * ⚠️ **Ciò che la griglia rende non è una guardia.** Le righe bloccate escono
 * col 🔒 e senza controlli, la colonna del Developer inerte: è **presentazione**.
 * La protezione vera vive in `MatriceRuoli`, che rifiuta prima di toccare il
 * database, e nessun test su questo HTML la prova — `Livewire::test()` non passa
 * dai middleware e un `@if` in Blade si toglie in un secondo.
 *
 * ## La riconciliazione col file di configurazione
 *
 * Dal momento in cui la matrice si modifica a runtime, `config/rbac.php` smette
 * di essere la verità e diventa **il default**. Le due sorgenti divergono per
 * costruzione, e la divergenza non è un guasto: è la feature. Ciò che è
 * pericoloso è che sia **invisibile**, perché `CLAUDE.md` ordina di riseminare
 * dopo ogni modifica alla config e `RolesAndPermissionsSeeder` fa
 * `syncPermissions()` — detacha tutto e riattacca dai default. Eseguito alla
 * lettera, quell'ordine corretto cancella la matrice di runtime.
 *
 * Le celle divergenti portano quindi il marcatore «personalizzato» **coi due
 * valori affiancati**, e un interruttore le isola. È il confronto ruolo per
 * ruolo che `CLAUDE.md` chiede a mano, fatto qui e a **zero query**:
 * `Rbac::permissionsForRole()` legge la config in PHP e la matrice a database è
 * già in memoria per la griglia. L'altra metà della stessa difesa vive nel
 * seeder, che prima di sincronizzare stampa ciò che sta per portare via e ne
 * lascia una riga nel registro.
 *
 * ## Il gate: `roles.manage`, cioè l'**opposto** della scelta del registro di
 * audit, per lo stesso ragionamento
 *
 * `RegistroAudit` rifiutò `audit.view` — che pure esiste a catalogo e sembra
 * fatto apposta — perché **non è nel set bloccato**, quindi questo editor potrà
 * ridistribuirlo: gatare una vista cross-tenant su un permesso ridistribuibile è
 * una falla ad attivazione differita. Qui vale lo stesso criterio con esito
 * rovesciato: `roles.manage` **è** nel set bloccato (`config/rbac.php`), quindi
 * non è ridistribuibile da questa stessa pagina. Il criterio, formulato una
 * volta per entrambe: **si gata su un permesso del set bloccato**. Il registro ci
 * arrivò per esclusione, qui ci si arriva per elezione.
 *
 * È anche il permesso che ADR-016 nomina per questa UI («Accesso: solo chi ha
 * `roles.manage` — esso stesso bloccato per evitare auto-delega») e quello che il
 * progetto **usa già come gate di una scrittura**, in
 * `FissaVisibilitaSede::fissaVisibilita()`.
 *
 * ⚠️ **Niente AND con `tenants.view_all`**, per la ragione già scritta nel
 * registro: non aggiunge protezione (chi passa il primo ha già il secondo) e
 * crea un modo di **rompere** la pagina. Oggi i due permessi appartengono agli
 * stessi due ruoli e sono **entrambi bloccati**, quindi non possono divergere per
 * mano di questo editor — la circolarità è il perno della feature: *il gate di
 * questa pagina è protetto dalla regola che questa pagina implementa*.
 *
 * ## Perché una rotta propria, e non un tab della cabina
 *
 * Delle due ragioni del registro, la prima qui **non** si applica: la matrice
 * non è paginata e non dichiara `#[Url]`, quindi non collide con il `page` unico
 * di `ElencaClienti`. La seconda sì, ed è più forte che là: la proprietà di
 * sicurezza è **per-URL**. Dentro `Cabina` le 324 scritture vivrebbero sotto una
 * rotta il cui `can:` dice `tenants.view_all`, mentre il permesso di questa
 * feature è `roles.manage` — e per un'azione che scrive «la guardia che regge è
 * quella di **rotta**» (ADR-018, nota corretta dopo essersi contraddetta). Un tab
 * lascerebbe come unica difesa il `Gate::authorize()` in azione, cioè una guardia
 * sola dove il progetto ne vuole due.
 *
 * ## Perché `Gate::authorize()` in testa a `render()`
 *
 * `RegistroAudit` non ne ha bisogno perché il suo `render()` passa da
 * `VistaPiattaforma::audit()`, e il permesso si chiede **dentro la porta**. Qui
 * porta non c'è e non deve esserci: `roles` e `permissions` sono tabelle
 * **globali**, senza tenancy, quindi non c'è alcuno scope da togliere e un
 * `VistaPiattaforma::ruoli()` sarebbe il «bypass finto» che il docblock della
 * porta rifiuta per nome. Il gate va quindi scritto qui, esplicitamente: senza,
 * il montaggio diretto del componente — `Livewire::test()`, che **disabilita i
 * middleware** — non incontrerebbe nessuna guardia.
 *
 * La rotta sta **dentro** il gruppo `['auth','account.lockout','two-factor.enforce']`
 * per la ragione già scritta per `/piattaforma`: il Superadmin è un utente
 * tenant-bound con un account proprio, e se quell'account fosse in lockout deve
 * vedere `/bloccato` come chiunque.
 *
 * 🔗 ADR-016 (RBAC e UI di gestione), ADR-018 (tenancy senza bypass, `can:` di
 * rotta sulle azioni), `App\Support\Rbac\MatriceRuoli` (la regola).
 *
 * ⚠️ **Le asimmetrie di RUOLO non sono rese, ed è una scelta da dichiarare.**
 *
 * Questa pagina si prende cura delle due asimmetrie del **catalogo dei
 * permessi** — l'orfano rimasto a database, il dichiarato-ma-non-seminato — e
 * lascia cadere in silenzio le due gemelle sui **ruoli**: un `Role` creato fuori
 * catalogo non compare da nessuna parte, e un ruolo del catalogo mancante a
 * database rende una colonna tutta ❌ senza dire che è vuota per assenza e non
 * per scelta. È la stessa confusione che la striscia degli orfani evita sui
 * permessi, accettata sui ruoli.
 *
 * Non è un rinvio comodo: `MatriceRuoli` nomina il ruolo fuori catalogo come
 * minaccia viva («`Role::create()` è a portata di chiunque abbia una console…
 * non avrebbe né scope di riga né 2FA obbligatorio»), e questa è l'unica
 * schermata da cui lo si vedrebbe.
 *
 * Ciò che **contiene** il rischio è il dominio, non questa pagina: `MatriceRuoli`
 * rifiuta di scrivere su un ruolo fuori catalogo, quindi da qui non lo si può
 * riempire. Resta scoperta la sola **visibilità** — chi lo crea da console lo
 * tiene nascosto — e chiuderla vorrebbe dire decidere cosa se ne fa chi lo
 * trova, che è una scelta di prodotto e non di questa schermata.
 *
 * *La prima stesura di questa nota motivava il rinvio con «la griglia di questo
 * blocco è in sola lettura»: era vero quando è stata scritta e ha smesso di
 * esserlo nel blocco successivo, cioè in questo stesso file. Un rinvio motivato
 * da uno stato transitorio invecchia in silenzio.*
 */
#[Layout('components.layouts.app')]
class EditorRuoli extends Component
{
    /**
     * Il permesso che apre la pagina, in un posto solo.
     *
     * Lo leggono la rotta (`can:`), la voce di `x-piattaforma.nav` e i test
     * strutturali: stessa forma di `VistaPiattaforma::PERMESSO`, e per la stessa
     * ragione — tre stringhe uguali scritte in tre file sono tre occasioni di
     * gatare la pagina su un permesso e la voce di menù su un altro.
     */
    public const PERMESSO = 'roles.manage';

    /**
     * La cella in attesa di conferma, `''` quando non ce n'è nessuna.
     *
     * ⚠️ Sono property **pubbliche**, cioè scrivibili dal browser, e non è una
     * svista: non difendono niente e non devono sembrare di farlo. Ciò che
     * regge è che `commuta()` ricalcola tutto da capo — il verso dalla matrice a
     * database, le due guardie dentro `MatriceRuoli` — quindi impostarle a mano
     * e chiamare `procedi()` non arriva più lontano di una chiamata diretta a
     * `commuta()`, che è già provata come rifiutata sui casi vietati. La
     * conferma è una **decisione da far prendere a un umano**, non una guardia:
     * confonderle produrrebbe la falsa sicurezza che questo lavoro combatte
     * ovunque.
     */
    public string $ruoloInConferma = '';

    public string $permessoInConferma = '';

    /**
     * L'interruttore «mostra solo le differenze» fra database e `config/rbac.php`.
     *
     * ⚠️ **Non è `#[Url]`**, e la scelta ha una ragione precisa: l'argomento per
     * cui questa pagina ha una rotta propria invece di un tab della cabina
     * poggia su «la matrice non è paginata e non dichiara `#[Url]`, quindi non
     * collide col `page` unico di `ElencaClienti`». Un `#[Url]` scritto qui per
     * comodità di condivisione toglierebbe metà di quell'argomento senza che
     * nessuno se ne accorga. E non serve: il filtro non seleziona *dati* da
     * mostrare a qualcun altro, è una lente su una pagina che si guarda una
     * volta prima di riseminare.
     *
     * Property pubblica, quindi scrivibile dal browser — e va bene: non
     * difende niente, non nasconde niente che non sia altrimenti visibile, e
     * ciò che si può scrivere è un booleano.
     */
    public bool $soloDifferenze = false;

    /**
     * Il click su una cella: chiede conferma quando serve, altrimenti scrive.
     *
     * ⚠️ **Non è `commuta()` con un `if` davanti**, ed è il motivo per cui sono
     * due metodi: `commuta()` deve restare chiamabile e rifiutabile per conto
     * proprio — una richiesta forgiata a mano non passa di qui, e la prova che
     * la cella bloccata resiste si scrive **su quella**.
     *
     * Il verso si legge dalla matrice a database e **non** dal browser, per la
     * stessa ragione per cui `MatriceRuoli::commuta()` non accetta un booleano:
     * l'HTML che l'utente sta guardando afferma uno stato che aveva letto prima.
     * Costa le due query fisse di `stato()`, cioè quanto le due query di `stato()` — la pagina ne fa tre —
     * l'alternativa sarebbe una seconda lettura del pivot scritta qui, e le
     * letture della matrice stanno in un posto solo.
     */
    public function chiedi(string $ruolo, string $permesso): void
    {
        Gate::authorize(self::PERMESSO);

        $concede = ! isset(MatriceRuoli::stato()[$ruolo][$permesso]);

        if (! self::vaConfermato($ruolo, $concede)) {
            $this->commuta($ruolo, $permesso);

            return;
        }

        $this->ruoloInConferma = $ruolo;
        $this->permessoInConferma = $permesso;
    }

    /**
     * Quali gesti si fermano a chiedere, e perché **la concessione è il lato che
     * conta di più**.
     *
     * L'istinto dice il contrario — «concedere è additivo e reversibile, revocare
     * toglie accesso a persone vive» — e vale solo finché si guardano i
     * permessi. Ma `EnsureTwoFactorIsEnabled` non gata il secondo fattore sui
     * permessi: lo gata **per nome di ruolo**
     * (`Rbac::twoFactorRequiredRoles()`). Concedere `utenti.delete`,
     * `semaforo.force` o `documenti.delete` a `Tenant`, `Tecnico` o
     * `Responsabile Reparto` allarga quindi il potere di ruoli **senza secondo
     * fattore obbligatorio**, con un click e per tutti i clienti insieme: è la
     * direzione in cui questa pagina fa danno davvero, e non si vede guardando
     * la matrice.
     *
     * Le revoche chiedono sempre. Non perché una singola revoca sia grave — è
     * anzi il gesto più facile da annullare — ma perché nessuna singola revoca
     * *sembra* grave: quattordici click e il `Tenant` non fa più niente, per
     * tutti gli Enti. Il numero di persone col ruolo è ciò che trasforma la
     * conferma in una decisione invece che in un ostacolo.
     *
     * Ne segue che l'unico gesto che scrive senza fermarsi è la concessione a un
     * ruolo che il 2FA lo ha già obbligatorio — `Admin` e `Superadmin`, dato che
     * la riga del `Developer` non è toccabile affatto.
     */
    private static function vaConfermato(string $ruolo, bool $concede): bool
    {
        return ! $concede || ! in_array($ruolo, Rbac::twoFactorRequiredRoles(), true);
    }

    /**
     * L'unica scrittura della pagina.
     *
     * `Gate::authorize()` **in testa all'azione** e non solo sulla rotta, benché
     * il `can:` di rotta regga anche sugli update di Livewire (è il test
     * `keeps the permission on a real Livewire update` a congelarlo). Le due
     * guardie non sono ridondanti: quella di rotta è larga e vive in
     * `routes/web.php`, cioè in un file che si modifica per ragioni che con
     * questa pagina non c'entrano; questa vive accanto al gesto e regge se un
     * domani la rotta cambiasse gruppo, o se l'azione guadagnasse
     * `skipRender()` — nel qual caso il `Gate::authorize()` di `render()` non
     * girerebbe affatto.
     *
     * Non decide **niente**: il verso, le due guardie e la riga di audit stanno
     * tutte in `MatriceRuoli`. Se una `ValidationException` sale, la modale
     * resta aperta di proposito e l'errore si legge in pagina — chiuderla
     * cancellerebbe il contesto proprio nel momento in cui serve.
     */
    public function commuta(string $ruolo, string $permesso): void
    {
        Gate::authorize(self::PERMESSO);

        MatriceRuoli::commuta($ruolo, $permesso);

        $this->annulla();
    }

    /** Il pulsante di conferma della modale. */
    public function procedi(): void
    {
        $this->commuta($this->ruoloInConferma, $this->permessoInConferma);
    }

    /** Chiude la modale senza scrivere. */
    public function annulla(): void
    {
        $this->ruoloInConferma = '';
        $this->permessoInConferma = '';
    }

    /**
     * Cosa deve dire la modale, o `null` se non c'è nessuna cella in attesa.
     *
     * ⚠️ **Le due property arrivano dal browser**, quindi si passano prima dalla
     * whitelist del catalogo, ed è la disciplina di `RegistroAudit`, dove un
     * `soggetto` fuori mappa non finisce in un `where`.
     *
     * *Ciò che questa whitelist NON fa, scritto perché è la prima cosa che si
     * penserebbe*: non evita un crash. La prima stesura di questo docblock
     * diceva che senza di lei un `ruoloInConferma` arbitrario avrebbe fatto
     * lanciare `User::role()` con `RoleDoesNotExist` durante il render —
     * **falso, verificato mutando**. `User::role()` si chiama solo sul ramo
     * della revoca, cioè quando `isset($matrice[$ruolo][$permesso])`, e la
     * matrice viene dal database: un ruolo che compare lì **esiste** per
     * costruzione. Il test che pretendeva di provarlo restava verde con la
     * whitelist tolta.
     *
     * Ciò che fa davvero è impedire alla pagina di **aprire una conferma per un
     * gesto che il dominio poi rifiuta**. Il caso vivo è l'orfano — un permesso
     * uscito dal catalogo e rimasto attaccato a un ruolo, come i
     * `letture_contaore.*` fino all'8 Ago 2026: senza whitelist la modale
     * direbbe «Revocare «letture_contaore.view» a «Tecnico»? 3 utenti hanno il
     * ruolo», e il pulsante di conferma produrrebbe un errore di validazione. Una
     * conferma che promette un gesto impossibile è peggio di nessuna conferma.
     *
     * L'elenco delle condizioni è quindi **lo stesso dei tre marcatori della
     * griglia** — riga bloccata, colonna protetta, riga non seminata — più le due
     * appartenenze al catalogo, e legge le stesse definizioni
     * (`Rbac::isLocked()`, `Rbac::isRuoloProtetto()`): non è una seconda copia
     * della regola, è la stessa regola chiesta da un altro punto. ⚠️ E **non è
     * una guardia**: la difesa vera è in `MatriceRuoli`, che rifiuta prima di
     * toccare il database. Qui si decide soltanto se una modale ha senso.
     *
     * @param  array<string, array<string, true>>  $matrice
     * @param  array<string, true>  $seminati
     * @return array{ruolo: string, permesso: string, concede: bool, senzaDueFattori: bool, utenti: int}|null
     */
    private function conferma(array $matrice, array $seminati): ?array
    {
        $ruolo = $this->ruoloInConferma;
        $permesso = $this->permessoInConferma;

        $offribile = in_array($ruolo, Rbac::roleNames(), true)
            && in_array($permesso, Rbac::permissions(), true)
            && ! Rbac::isLocked($permesso)
            && ! Rbac::isRuoloProtetto($ruolo)
            && isset($seminati[$permesso]);

        if (! $offribile) {
            return null;
        }

        $concede = ! isset($matrice[$ruolo][$permesso]);

        return [
            'ruolo' => $ruolo,
            'permesso' => $permesso,
            'concede' => $concede,
            'senzaDueFattori' => ! in_array($ruolo, Rbac::twoFactorRequiredRoles(), true),
            // ⚠️ `User::role()` **direttamente**, e non da `VistaPiattaforma`.
            // Il riflesso dopo il registro di audit è passare ogni lettura
            // cross-tenant dalla porta, e qui sarebbe sbagliato due volte:
            // `User` non ha global scope (è dichiarato in
            // `TenantScopeGuardrailTest::NON_TENANT_MODELS`), quindi non c'è
            // niente da togliere, e il docblock della porta rifiuta per nome
            // proprio un `utenti()` — «un bypass finto, che legittimerebbe
            // l'idea che serva sempre». Il permesso, che è ciò che la porta
            // aggiunge davvero, l'ha già chiesto `render()`.
            //
            // Si conta solo sulle revoche: su una concessione il numero non
            // direbbe niente che l'utente debba decidere.
            'utenti' => $concede ? 0 : User::role($ruolo)->count(),
        ];
    }

    public function render(): View
    {
        // La guardia gira **a ogni render**, prima di qualunque lettura: è ciò
        // che rende il 403 provabile senza middleware. Il `can:` di rotta resta
        // la guardia larga (ed è quella che vale sugli update Livewire, dove
        // un'azione con `skipRender()` non arriverebbe mai qui).
        Gate::authorize(self::PERMESSO);

        // ⚠️ **I nomi che esistono davvero a database**, e non è una ridondanza
        // della matrice: `MatriceRuoli::stato()` dice cosa ha *ogni ruolo*,
        // quindi non vede né il permesso che a database non c'è affatto (il
        // «non seminato») né l'orfano che nessun ruolo tiene più. Le due
        // asimmetrie del catalogo si leggono solo da qui.
        //
        // ⚠️ Il commento diceva «la terza — e ultima — query della pagina», ed
        // era falso due volte: è la **prima** delle tre del componente, e la
        // pagina intera ne fa **quattro** a caldo (il layout aggiunge il
        // conteggio delle notifiche non lette) e **otto** a freddo, perché il
        // primo montaggio riscalda la cache dei permessi di spatie. Il numero
        // che conta è congelato da `keeps the page cost flat` in
        // `GrigliaRuoliTest`, non da questa frase.
        //
        // Si legge dal **database** e non da `Permission::all()` del registrar
        // per la stessa ragione per cui `MatriceRuoli` inverte la cella dal
        // pivot: la cache dei permessi può essere vecchia, e una pagina che
        // esiste per dire *cosa c'è scritto adesso* non può leggere da una
        // copia.
        $aDatabase = Permission::query()->pluck('name')->all();

        $matrice = MatriceRuoli::stato();
        $seminati = array_fill_keys($aDatabase, true);

        // ⚠️ **Zero query in più**, ed è la ragione per cui la riconciliazione
        // vive qui e non in un comando: `Rbac::permissionsForRole()` è PHP puro
        // (legge `config/rbac.php`) e la matrice a database è già in memoria per
        // rendere la griglia. Il confronto ruolo-per-ruolo che `CLAUDE.md`
        // chiede **a mano** prima di riseminare costa quindi, in pagina, un
        // doppio ciclo su 324 celle e niente altro.
        $personalizzate = self::personalizzazioni($matrice, $seminati);
        $gruppi = self::gruppi();

        if ($this->soloDifferenze) {
            $gruppi = self::soloDivergenti($gruppi, $personalizzate);
        }

        return view('livewire.piattaforma.editor-ruoli', [
            // L'ordine di `config/rbac.php`, che è **anche** quello della
            // tabella di `Schema Ruoli §5`: la vista si affianca al documento e
            // chi confronta le due cose non deve trasporre a mente. Ordinare
            // altrimenti (per nome, per numero di permessi) sarebbe una scelta
            // di presentazione che rompe quel confronto.
            'ruoli' => Rbac::roleNames(),
            'gruppi' => $gruppi,
            'matrice' => $matrice,
            // Insieme e non lista: il consumo è `isset($seminati[$permesso])`
            // per ognuna delle 54 righe.
            'seminati' => $seminati,
            'orfani' => self::orfani($aDatabase),
            // I permessi che il catalogo dichiara e il database non ha. La
            // griglia li marca già riga per riga («da seminare»); qui servono
            // per dire **una volta sola, e col comando scritto**, cosa si fa per
            // farli esistere — un marcatore che non porta a un gesto lascia
            // l'operatore a metà strada.
            'nonSeminati' => array_values(array_diff(Rbac::permissions(), $aDatabase)),
            'personalizzate' => $personalizzate,
            // Il conteggio si fa qui e non nel Blade: è la cifra che decide se
            // il pannello di riconciliazione dice «coincidono» o «divergono», e
            // un `array_sum(array_map(...))` dentro una vista è la stessa
            // logica scritta dove non si può provare.
            'quantePersonalizzate' => array_sum(array_map('count', $personalizzate)),
            // ⚠️ **I due insiemi si derivano qui e si consumano nel Blade**, e
            // non è pignoleria di stile: il ruolo protetto serve
            // all'intestazione *e* a ognuna delle 324 celle, e ricalcolarlo in
            // due punti significherebbe due copie di una regola che
            // `Rbac::isRuoloProtetto()` esiste per tenere unica — la disciplina
            // di `canBeImpersonated()`, «una definizione, mai una seconda
            // copia». Insiemi e non liste, perché il consumo è `isset()` per
            // cella.
            'inerti' => array_fill_keys(array_filter(Rbac::roleNames(), Rbac::isRuoloProtetto(...)), true),
            // I ruoli **senza** secondo fattore obbligatorio: è la direzione in
            // cui una concessione fa danno (vedi `vaConfermato()`), e la pagina
            // deve dirlo dov'è visibile — non solo dentro la modale, che si apre
            // quando la decisione è già stata presa.
            'senzaDueFattori' => array_fill_keys(
                array_values(array_diff(Rbac::roleNames(), Rbac::twoFactorRequiredRoles())),
                true
            ),
            'conferma' => $this->conferma($matrice, $seminati),
        ]);
    }

    /**
     * Le 54 righe del catalogo, raggruppate per **prefisso**.
     *
     * ⚠️ Il raggruppamento si **deriva** dal nome del permesso (la parte prima
     * del primo `.`) e non da una mappa scritta a mano. La mappa sarebbe più
     * bella da leggere — «Anagrafica & asset» invece di «unita_organizzativa» e
     * «strumenti» separati — e sarebbe il **settimo elenco parallelo** che
     * questo progetto deve tenere allineato al catalogo: su elenchi paralleli ha
     * già perso due volte (i `letture_contaore.*` rimasti a database dopo essere
     * usciti dalla config, `fornitori.view` dichiarato dal documento e non
     * creato dal bootstrap). Un permesso nuovo aggiunto a `config/rbac.php`
     * compare qui da sé, nel suo gruppo, senza che nessuno se ne ricordi.
     *
     * **Costo accettato e dichiarato**, in due forme. I gruppi da una riga sola
     * diventano **sei** — `spostamenti`, `semaforo`, `qr`, `audit`, `system`,
     * `roles` — invece di stare sotto le famiglie del documento; e
     * `garanzie.macchina.*` / `garanzie.ricambio.*` finiscono **insieme** sotto
     * `garanzie`, perché il prefisso si ferma al primo punto, mentre il
     * documento le tiene distinte. Diciassette gruppi in tutto.
     *
     * L'ordine — dei gruppi e dentro i gruppi — è quello del catalogo, cioè
     * ancora quello del documento.
     *
     * @return array<string, list<string>>
     */
    private static function gruppi(): array
    {
        $gruppi = [];

        foreach (Rbac::permissions() as $permesso) {
            $gruppi[Str::before($permesso, '.')][] = $permesso;
        }

        return $gruppi;
    }

    /**
     * 🔴 Le celle in cui il **database** e `config/rbac.php` non dicono la stessa cosa.
     *
     * È il «confrontare ruolo per ruolo DB e config» che `CLAUDE.md` chiede di
     * fare **a mano** prima di riseminare, reso un colpo d'occhio. Serve perché
     * dal momento in cui la matrice si modifica a runtime le due sorgenti
     * divergono per costruzione, e `RolesAndPermissionsSeeder` —  che
     * `CLAUDE.md` *ordina* di lanciare dopo ogni modifica a `config/rbac.php` —
     * fa `syncPermissions()`, cioè **detacha tutto e riattacca dalla config**:
     * ogni cella marcata qui è una cella che quel comando porterà via.
     *
     * Il verso è quello del **gesto che ha prodotto la differenza**, non quello
     * del confronto: `concesso` è una cella accesa a database che la config
     * vuole spenta (qualcuno l'ha concessa da questa pagina), `revocato` il
     * contrario. Chiamarli «in più»/«in meno» costringerebbe a ricordarsi da che
     * parte si guarda.
     *
     * ⚠️ **Due famiglie di celle restano fuori, e per la stessa ragione**: dire
     * «personalizzato» dove nessuno ha personalizzato niente manda a fare il
     * gesto sbagliato.
     * - i permessi **orfani** — a database e non più in catalogo — non sono
     *   confrontabili con la config, che non li dichiara: hanno una striscia a
     *   parte, e il gesto che li riguarda è una rimozione a mano, non un click;
     * - i permessi **non seminati** — in catalogo e non a database — sono spenti
     *   su *tutte e sei* le colonne, e senza questa esclusione una riga che
     *   manca al bootstrap comparirebbe come sei personalizzazioni deliberate.
     *   La riga lo dice già di suo («da seminare»), e il gesto che la ripara è
     *   proprio il seeding — cioè l'opposto di «attenzione, il seeding ti porta
     *   via questo».
     *
     * @param  array<string, array<string, true>>  $matrice
     * @param  array<string, true>  $seminati
     * @return array<string, array<string, string>> ruolo → permesso → «concesso»|«revocato»
     */
    private static function personalizzazioni(array $matrice, array $seminati): array
    {
        $personalizzate = [];

        foreach (Rbac::roleNames() as $ruolo) {
            // ⚠️ **La config si legge una volta per ruolo**, non una per cella:
            // `permissionsForRole()` risolve `all`/`except`/`only` daccapo a
            // ogni chiamata, quindi un `in_array()` scritto dentro il doppio
            // ciclo costerebbe **324** risoluzioni della matrice, ognuna seguita
            // da una scansione lineare. Un insieme `nome => true` per colonna e
            // 324 `isset()`.
            $daConfig = array_fill_keys(Rbac::permissionsForRole($ruolo), true);

            foreach (Rbac::permissions() as $permesso) {
                if (! isset($seminati[$permesso])) {
                    continue;
                }

                $aDatabase = isset($matrice[$ruolo][$permesso]);

                if ($aDatabase !== isset($daConfig[$permesso])) {
                    $personalizzate[$ruolo][$permesso] = $aDatabase ? 'concesso' : 'revocato';
                }
            }
        }

        return $personalizzate;
    }

    /**
     * Gli stessi gruppi, con le sole righe che hanno almeno una cella divergente.
     *
     * Filtra le **righe** e non le celle: una riga mostrata a metà — solo le
     * colonne divergenti — direbbe «il Tenant ha `qr.scan`» senza far vedere che
     * l'Admin ce l'ha per default, cioè toglierebbe il confronto fra colonne che
     * è l'unica cosa che una griglia sa fare. I gruppi che restano senza righe
     * spariscono: un'intestazione vuota è rumore.
     *
     * @param  array<string, list<string>>  $gruppi
     * @param  array<string, array<string, string>>  $personalizzate
     * @return array<string, list<string>>
     */
    private static function soloDivergenti(array $gruppi, array $personalizzate): array
    {
        $divergenti = [];

        foreach ($personalizzate as $celle) {
            $divergenti += $celle;
        }

        $filtrati = [];

        foreach ($gruppi as $prefisso => $permessi) {
            $restano = array_values(array_filter($permessi, fn (string $p) => isset($divergenti[$p])));

            if ($restano !== []) {
                $filtrati[$prefisso] = $restano;
            }
        }

        return $filtrati;
    }

    /**
     * I permessi **orfani**: righe di `permissions` che il catalogo non conosce più.
     *
     * Non è un caso teorico: i `letture_contaore.*`, tolti dalla config in
     * S3-bis con ADR-019, sono rimasti attaccati a quattro ruoli del DB di
     * sviluppo fino all'8 Ago 2026, perché il seeder usa `firstOrCreate` e non
     * cancella mai ciò che non conosce più (CLAUDE.md). La griglia li rende in
     * una **striscia a parte in sola lettura** invece di nasconderli: se
     * comparissero fra le righe normali l'editor legittimerebbe righe orfane, e
     * se non comparissero affatto la pagina direbbe che il database è pulito
     * quando non lo è.
     *
     * Restano fuori dalla UI anche come gesto — `MatriceRuoli` rifiuta di
     * riassegnarli, e cancellarli di qui sarebbe peggio: `Permission::delete()`
     * cascata su `role_has_permissions` **e** `model_has_permissions`, e il
     * catalogo è codice (ADR-016).
     *
     * @param  list<string>  $aDatabase
     * @return list<string>
     */
    private static function orfani(array $aDatabase): array
    {
        $orfani = array_values(array_diff($aDatabase, Rbac::permissions()));

        sort($orfani);

        return $orfani;
    }
}
