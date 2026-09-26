<?php

namespace App\Livewire\Piattaforma;

use App\Models\Errore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 🔴 L'error tracker interno — l'elenco delle issue (S6).
 *
 * 🔗 `docs/Architettura/Error Tracker Interno (piano).md`, ADR-016 (RBAC),
 * ADR-018 (tenancy senza bypass, `can:` di rotta sulle azioni).
 *
 * Le eccezioni PHP finiscono a database perché su Laravel Cloud `laravel.log`
 * vive su un disco **effimero e per-replica**, azzerato a ogni deploy e a ogni
 * risveglio da scale-to-zero: un errore visto da un cliente può non lasciare
 * nulla di consultabile. Questa è la pagina da cui lo si guarda.
 *
 * ## Sola lettura, e non provvisoriamente
 *
 * ⚠️ **Questa pagina non ha azioni, e non è un lavoro rimandato.** I tre gesti
 * (risolvi/ignora/riapri) esistono e vivono su `SchedaErrore`: è là che si è
 * appena letto lo stack trace, l'input e chi c'era, cioè dove si sa abbastanza
 * per decidere. La stessa fila di pulsanti qui chiederebbe di zittire una issue
 * **senza averla aperta** — e `ignorato` è l'unico interruttore di silenzio del
 * tracker, il solo stato che non si riapre mai da sé. Il link sulla classe è
 * quindi anche la strada verso le azioni, non solo verso il dettaglio.
 *
 * Qui **nessun metodo scrive**: c'è `render()`, un solo hook di filtro
 * (`updatingStato`, che chiama `resetPage()`), e i sette metodi che
 * `WithPagination` porta con sé — `gotoPage`, `resetPage` e compagnia.
 *
 * ⚠️ **Quei sette sono pubblici e raggiungibili da `/livewire/update`**, e va
 * detto invece di scrivere «nessun metodo pubblico oltre `render()`», che è
 * falso: la reflection ne conta nove per componente. Sono innocui — muovono un
 * numero di pagina, non toccano il database, non accettano un id di dominio —
 * ma «innocuo» e «inesistente» non sono la stessa cosa, e su una pagina che si
 * dichiara in sola lettura la differenza va scritta.
 *
 * Gli hook di ciclo di vita, invece, non sono invocabili dall'esterno:
 * Livewire li rifiuta con `DirectlyCallingLifecycleHooksNotAllowedException`.
 *
 * ## Le due cifre, che non sono la stessa cosa
 *
 * 🔴 `errori.occorrenze` conta **tutti** gli avvenimenti; `errori.contesti`
 * quante prove se ne sono conservate (al più `contesti_per_errore`, non più di
 * una ogni `finestra_contesto_secondi`). Dirle separate — o peggio, dirne una
 * sola — manda a cercare diecimila righe di dettaglio che non esistono: da qui
 * `<x-errori.cifre>`, che le stampa **nella stessa frase** e in un posto solo,
 * condiviso con la scheda. Il componente esiste apposta perché non ci sia modo
 * di scrivere la prima senza la seconda.
 *
 * ## Perché la scheda è una **seconda rotta** e non un dettaglio espanso
 *
 * Per la ragione già scritta in `RegistroAudit` («non un tab della cabina»),
 * qui però meccanica prima ancora che di sicurezza: elenco e occorrenze sono
 * **entrambe paginate**, e `WithPagination` ha un `page` solo. Nello stesso
 * componente le due paginazioni collidono — pagina 2 delle occorrenze
 * sposterebbe anche l'elenco sotto. In più la proprietà di sicurezza è
 * per-URL: `SchedaErrore` ha il proprio `can:` di rotta e il proprio 403.
 *
 * ## Il permesso, e la prima pagina che il Superadmin non vede
 *
 * `system.logs.view` esiste a catalogo dalla S1, è nel **set bloccato** di
 * `config/rbac.php` — quindi l'editor della matrice non può darlo né toglierlo
 * a nessuno — ed è del **solo Developer**: il Superadmin ne è escluso per
 * eccezione esplicita (`'except' => ['system.logs.view']`), pur avendo tutti
 * gli altri permessi della piattaforma.
 *
 * ⚠️ **È la prima schermata del progetto che il Superadmin non può aprire**, e
 * con essa la prima in cui la partizione di `system.logs.view` **non coincide**
 * con quella di `tenants.view_all`. Ogni pagina di piattaforma nata finora ha
 * potuto permettersi di trattare le due cose come sinonimi; da qui in poi no —
 * ed è la ragione per cui `AccessoErroriTest` si scrive dataset propri invece
 * di riusare `RUOLI_CON_PIATTAFORMA` / `RUOLI_SENZA_PIATTAFORMA`.
 *
 * Non è un dettaglio di autorizzazione ma una scelta di prodotto: qui si legge
 * tutto ciò che si è rotto in **ogni** Ente, con dentro messaggi, percorsi e
 * input di richiesta. È il gate più stretto del progetto, e chi un giorno vorrà
 * allargarlo trova il perché nel negativo dedicato al Superadmin, non un 403
 * muto. Farlo è comunque **un commit su `config/rbac.php` più un riseeding**,
 * non un click: il permesso è bloccato.
 *
 * **Come ci arriva il Developer.** Nessuna seconda voce di sidebar: entra da
 * `/piattaforma` con `tenants.view_all` — che ha, avendo tutto — e trova la
 * **quarta voce** di sub-nav, filtrata sulla `PERMESSO` di questa classe. Il
 * Superadmin quella voce non la vede, perché una voce di menù che porta a un
 * 403 è un invito a bussare.
 *
 * ## Perché `Gate::authorize()` in testa a `render()`
 *
 * Per la ragione già scritta in `EditorRuoli`: `RegistroAudit` non ne ha
 * bisogno perché il suo `render()` passa da `VistaPiattaforma::audit()` e il
 * permesso si chiede **dentro la porta**. Qui porta non c'è e non deve
 * esserci — `errori` e `occorrenze_errore` sono tabelle **globali**, senza
 * tenancy, quindi non c'è nessuno scope da togliere e un
 * `VistaPiattaforma::errori()` sarebbe il «bypass finto» che il docblock della
 * porta rifiuta per nome. Il gate va quindi scritto qui, esplicitamente: senza,
 * il montaggio diretto del componente — `Livewire::test()`, che **disabilita i
 * middleware** — non incontrerebbe nessuna guardia.
 *
 * Il `can:` di rotta resta comunque, e non è ridondante: è la guardia larga, e
 * la sola che regge sugli update Livewire quando un'azione non arriva mai a
 * `render()` (`skipRender()`). Su questa pagina il caso non si presenta — azioni
 * non ce ne sono — ma sulla scheda sì, e là ogni gesto porta il proprio
 * `Gate::authorize()` in testa: senza, `render()` risponderebbe 403 **dopo** che
 * la scrittura è già avvenuta.
 *
 * La rotta sta **dentro** il gruppo `['auth','account.lockout','two-factor.enforce']`
 * per la ragione già scritta per `/piattaforma`: il Developer è un utente
 * tenant-bound con un account proprio (ADR-018), e se quell'account fosse in
 * lockout deve vedere `/bloccato` come chiunque.
 *
 * ⚠️ **Il file sta in `app/Livewire/Piattaforma/`, non in una sottocartella
 * propria.** `LocalizzazioneTest:77` deriva il proprio universo da un `glob()`
 * su `app/Livewire` con un doppio asterisco, e in PHP quel pattern **non è
 * ricorsivo**: si ferma a profondità 2. A profondità 3 questo file sfuggirebbe
 * al meta-test in silenzio — non lo farebbe fallire, glielo **toglierebbe**. È
 * la stessa trappola già colta sui due model in `SchemaErroriTest`, e vale
 * identica per `SchedaErrore`.
 */
#[Layout('components.layouts.app')]
class Errori extends Component
{
    use WithPagination;

    /**
     * Il permesso che apre la pagina, in un posto solo.
     *
     * Lo leggono le **due** rotte (`can:`), la voce di `x-piattaforma.nav`,
     * `SchedaErrore::render()` e i test strutturali: stessa forma di
     * `EditorRuoli::PERMESSO` e per la stessa ragione — cinque stringhe uguali
     * scritte in cinque file sono cinque occasioni di gatare una pagina su un
     * permesso e l'altra su un altro. Qui pesa di più che altrove, perché è
     * l'unico permesso di piattaforma la cui partizione non coincide con
     * `tenants.view_all`: una copia sbagliata aprirebbe la pagina al Superadmin
     * senza che nulla lo dica.
     */
    public const PERMESSO = 'system.logs.view';

    /**
     * Gli stati che una issue può avere, e le etichette del filtro.
     *
     * ⚠️ È anche la **whitelist** del valore che arriva dalla query string: la
     * property è `#[Url]`, quindi `?stato=` può valere qualunque cosa e finisce
     * in una clausola `where`. Chi non è qui dentro non filtra niente.
     *
     * I tre stati sono quelli della colonna, e i gesti che li muovono stanno su
     * `SchedaErrore` (`Errore::risolvi()`, `ignora()`, `riapri()`) più la
     * riapertura automatica di `CatturaErrori`. Il filtro è ciò che rende
     * utilizzabile l'unico interruttore di silenzio del tracker: una lista che
     * mostrasse gli `ignorato` fra gli aperti lo renderebbe indistinguibile dal
     * rumore, cioè lo annullerebbe.
     */
    public const STATI = [
        'aperto' => 'Aperti',
        'risolto' => 'Risolti',
        'ignorato' => 'Ignorati',
    ];

    /** La sentinella «tutti gli stati»: `''`, cioè nessuna clausola. */
    public const TUTTI = '';

    /**
     * Lo stato mostrato, in query string.
     *
     * Default **`aperto`** e non «tutti», ed è la stessa scelta che la migration
     * dichiara nel proprio indice (`['stato','ultima_occorrenza_at']`, «la lista
     * di default: aperti, i più recenti in cima»): chi apre questa pagina sta
     * cercando cosa è rotto **adesso**. Le issue chiuse e quelle zittite restano
     * a un click, e il vuoto lo dice esplicitamente invece di far cercare un
     * filtro che non si sapeva di avere.
     */
    #[Url]
    public string $stato = 'aperto';

    private const PER_PAGE = 25;

    /**
     * Cambiare filtro riparte da pagina 1.
     *
     * Restare a pagina 3 di un elenco che ora ne ha una sola mostra il vuoto —
     * la ragione di sempre. ⚠️ È un **hook di ciclo di vita**, non un'azione:
     * non scrive niente e non legge un id dal browser, ed è l'unico metodo
     * pubblico di questa classe oltre a `render()`.
     */
    public function updatingStato(): void
    {
        $this->resetPage();
    }

    /**
     * Lo stato **effettivamente applicato**, o la sentinella.
     *
     * ⚠️ La vista non deve leggere `$stato`: arriva dalla query string e può
     * valere qualunque cosa, mentre la query ha usato la sentinella. Un `select`
     * che mostra «Aperti» davanti a un elenco non filtrato è una bugia piccola,
     * e per questo credibile — è la lezione di `RegistroAudit::direzione()`.
     *
     * Un valore fuori catalogo **non filtra** invece di dare errore: il valore
     * arriva da un link, e `?stato=pippo` deve mostrare l'elenco senza quel
     * filtro, non una pagina di errore.
     */
    private function statoApplicato(): string
    {
        return array_key_exists($this->stato, self::STATI) ? $this->stato : self::TUTTI;
    }

    public function render(): View
    {
        // La guardia gira **a ogni render**, prima di qualunque lettura: è ciò
        // che rende il 403 provabile senza middleware.
        Gate::authorize(self::PERMESSO);

        $statoAttivo = $this->statoApplicato();

        $errori = Errore::query()
            ->when($statoAttivo !== self::TUTTI, fn ($query) => $query->where('stato', $statoAttivo))
            // ⚠️ **L'eager load non è un'ottimizzazione facoltativa**: la colonna
            // «Stato» nomina chi ha chiuso una issue risolta, e senza questo
            // sarebbe una query per riga — venticinque per pagina. La relazione
            // è `nullOnDelete`, quindi resta vuota su una issue chiusa da un
            // utente poi cancellato: la vista lo gestisce, non ci cade.
            ->with('risoltoDa')
            // Il più recente in cima: su un tracker «cosa si sta rompendo
            // adesso» è la sola domanda che si pone aprendo la pagina.
            ->orderByDesc('ultima_occorrenza_at')
            // ⚠️ Il tie-break non è prudenza: i timestamp si serializzano al
            // secondo e un deploy sbagliato fa nascere venti issue nello stesso
            // istante. Senza, la paginazione perde e ripete righe fra una pagina
            // e l'altra.
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->onEachSide(1);

        return view('livewire.piattaforma.errori', [
            'errori' => $errori,
            // Dopo la whitelist, non la property: vedi `statoApplicato()`.
            'statoAttivo' => $statoAttivo,
            'stati' => self::STATI,
        ]);
    }
}
