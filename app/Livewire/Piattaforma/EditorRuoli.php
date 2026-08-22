<?php

namespace App\Livewire\Piattaforma;

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
 * Per ora è **in sola lettura**: la matrice intera resa in tabella, e nessun
 * controllo che scriva. È voluto, ed è la stessa sequenza con cui sono nate
 * `Cabina` e `RegistroAudit` — prima un guscio già gatato (una pagina che non
 * mostra nulla rende il gate **dimostrabile prima** che ci sia qualcosa da
 * proteggere), poi la lettura, e solo dopo la scrittura. Leggere correttamente
 * 324 celle è un lavoro a sé, e questa tappa è ciò che permette di verificare a
 * occhio, su staging, che `MatriceRuoli::stato()` dica il vero **prima** che
 * esista un bottone che scrive. L'ordine opposto mette la guardia addosso a una
 * vista già scritta, e la prova diventa «non sembra rotto».
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

        return view('livewire.piattaforma.editor-ruoli', [
            // L'ordine di `config/rbac.php`, che è **anche** quello della
            // tabella di `Schema Ruoli §5`: la vista si affianca al documento e
            // chi confronta le due cose non deve trasporre a mente. Ordinare
            // altrimenti (per nome, per numero di permessi) sarebbe una scelta
            // di presentazione che rompe quel confronto.
            'ruoli' => Rbac::roleNames(),
            'gruppi' => self::gruppi(),
            'matrice' => MatriceRuoli::stato(),
            // Insieme e non lista: il consumo è `isset($seminati[$permesso])`
            // per ognuna delle 54 righe.
            'seminati' => array_fill_keys($aDatabase, true),
            'orfani' => self::orfani($aDatabase),
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
    /**
     * ⚠️ **Le asimmetrie di RUOLO non sono rese, ed è una scelta da dichiarare.**
     *
     * Questa pagina si prende cura delle due asimmetrie del **catalogo dei
     * permessi** — l'orfano rimasto a database, il dichiarato-ma-non-seminato —
     * e lascia cadere in silenzio le due gemelle sui **ruoli**: un `Role` creato
     * fuori catalogo non compare da nessuna parte, e un ruolo del catalogo
     * mancante a database rende una colonna tutta ❌ senza dire che è vuota per
     * assenza, non per scelta. È esattamente la confusione che la striscia degli
     * orfani esiste per evitare sui permessi, accettata sui ruoli.
     *
     * Non è un rinvio comodo: `MatriceRuoli` nomina il ruolo fuori catalogo come
     * minaccia viva («`Role::create()` è a portata di chiunque abbia una
     * console… non avrebbe né scope di riga né 2FA obbligatorio»), e questa è
     * l'unica schermata da cui lo si vedrebbe. Resta fuori perché la griglia di
     * questo blocco è in sola lettura e renderlo qui vorrebbe dire decidere ora
     * cosa se ne fa chi lo trova — che è materia dei blocchi successivi.
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
