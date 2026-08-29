<?php

namespace App\Livewire\Piattaforma;

use App\Livewire\Piattaforma\Concerns\OffreImpersonazione;
use App\Models\Account;
use App\Models\Ricambio;
use App\Support\Piani;
use App\Support\Piattaforma\Perimetro;
use App\Support\Piattaforma\Preferiti;
use App\Support\Piattaforma\RicambiDelParco;
use App\Support\Piattaforma\TitoloPerimetro;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Parco clienti — scheda «Ricambi», in **SOLA LETTURA** (🔗 ADR-037).
 *
 * Il catalogo pezzi di tutti i clienti nel perimetro, in un elenco solo. La
 * domanda è quella che nessuna schermata di Ente sa porre: «che pezzi hanno in
 * catalogo i clienti che sto guardando, e chi altro ha lo stesso pezzo».
 *
 * 🔴 **Da qui si guarda, non si scrive.** Non c'è una sola azione che modifichi
 * un dato di cliente: ogni modifica continua a passare dall'impersonazione, che
 * è per cliente e lascia nel registro di audit chi agiva e per conto di chi
 * (🔗 ADR-018, ADR-027). Le uniche azioni di questa classe sono l'ordinamento,
 * la paginazione e l'**apertura** della scelta del membro da impersonare —
 * quest'ultima presa in prestito da `OffreImpersonazione`, che è la stessa della
 * cabina di regia: la logica dei candidati non si riscrive, o le due
 * definizioni di «chi è protetto» divergerebbero.
 *
 * **Autorizzazione a tre livelli**, come la cabina, e nessuno copre gli altri:
 *
 * 1. `can:tenants.view_all` sulla **rotta**, riapplicato anche sugli update di
 *    Livewire — `Illuminate\Auth\Middleware\Authorize` è fra i middleware
 *    persistenti del pacchetto.
 * 2. `ParcoClienti::porta()` dentro **ogni lettura**: nessuna riga di cliente si
 *    ottiene senza passare di lì, e senza un `Perimetro`.
 * 3. `Gate::authorize('utenti.impersonate')` dentro `apriScelta()` e
 *    `updatingSceltaImpersonazione()`: stare in questa pagina non è poter
 *    entrare in casa di un cliente, e i due permessi sono **diversi**.
 *
 * ⛔ **Il perimetro arriva dal browser** — `modo` e `piano` sono property
 * `#[Url]` — quindi non si usa mai grezzo: `Perimetro::daRichiesta()` lo
 * normalizza (e in caso di dubbio dà l'insieme **vuoto**, non «tutti»), e
 * `ParcoClienti::clienti()` lo **interseca** con l'insieme legittimo.
 *
 * 🔴 Dal 29 Ago 2026 il terzo modo non è più «scelti a mano» ma **«i miei
 * preferiti»** (🔗 ADR-037): l'insieme non si ricompone a ogni visita dentro
 * una `<select multiple>`, si segna una volta con la ★ nell'elenco Clienti e
 * resta. La conseguenza che conta per questo file è che **quegli id non
 * arrivano più dal browser**: li legge `Preferiti::perimetro()`, che è gata
 * sullo stesso permesso, e la property `clientiScelti` è sparita invece di
 * restare inerte — un `#[Url]` che nessun ramo può onorare è un link che
 * promette un filtro e non lo applica.
 *
 * ⚠️ **Le garanzie non compaiono in questa scheda, e non è una dimenticanza —
 * ma la ragione non è uno scope.** `ParcoClienti::ricambi()` consegna il solo
 * catalogo pezzi. Il filtro di 🔗 ADR-029 — `GaranziaRicambioPrivacyScope`, che
 * non è di tenancy e risponde a «questo utente ha titolo a vedere le garanzie
 * dei pezzi montati?» — lo registra `Garanzia::booted()`, unico punto del
 * progetto a farlo: su un builder di `Ricambio` quel filtro non c'è mai stato.
 *
 * ⛔ Quindi qui **non c'è niente da cui una colonna nuova erediti un controllo.**
 * Il giorno in cui servisse la colonna «in garanzia?» — la domanda naturale su un
 * catalogo pezzi — un `join` o un `withCount` scritto qui la mostrerebbe **senza
 * nessuna verifica per riga**, a un ruolo che potesse avere `tenants.view_all`
 * e non `garanzie.ricambio.view`. La strada è `ParcoClienti::garanzie()`, il
 * builder su cui quella domanda viene posta davvero.
 *
 * ⛔ **Il tasto «impersona» resta sulla rotta del pacchetto, e non è una svista.**
 * Dal 29 Ago 2026 il Parco ha una seconda porta — `piattaforma.parco.impersona`
 * — che impersona e **atterra sulla macchina** della riga invece di rimbalzare
 * in dashboard. La usano le schede in cui la riga *è* una macchina. Qui la riga
 * è una **voce di catalogo**, e un pezzo non ha una macchina: `ricambi` non
 * porta nessuna colonna verso `strumenti` (ERD §7.1), e il ponte verso le
 * macchine è la tabella degli utilizzi — deliberatamente fuori dalla porta, e
 * che questa scheda non legge. Due conseguenze, e ciascuna basterebbe da sola:
 *
 *   1. **non esiste una macchina univoca**: un pezzo a catalogo sta su zero,
 *      una o venti macchine, e sceglierne una vorrebbe dire lasciarla decidere
 *      all'ordinamento di una query. Atterrare sulla macchina **sbagliata** è
 *      peggio che atterrare in dashboard: chi arriva crede di essere dove
 *      voleva, e agisce lì;
 *   2. **anche se la macchina fosse una sola, dirlo sarebbe una fuga**:
 *      l'indirizzo del pulsante direbbe «questo pezzo è montato su quella
 *      macchina», cioè la domanda a cui questa scheda non risponde (🔗 ADR-029).
 *      Un dato non trapela solo dalle colonne di una tabella: trapela anche
 *      dalla **destinazione di un link**.
 *
 * Il giorno in cui esistesse una scheda degli utilizzi, il tasto che atterra
 * sulla macchina nascerebbe **lì**, dove la riga una macchina ce l'ha davvero.
 */
#[Layout('components.layouts.app')]
class ParcoRicambi extends Component
{
    use OffreImpersonazione, WithPagination;

    public const PERMESSO = VistaPiattaforma::PERMESSO;

    /** Su quali clienti: `tutti`, `piano` o `preferiti` (🔗 `Perimetro`). */
    #[Url]
    public string $modo = Perimetro::TUTTI;

    /** Il piano commerciale, quando `modo` vale `piano`. */
    #[Url]
    public string $piano = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $sortBy = 'nome';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public int $perPage = 20;

    /**
     * I tre modi che la tendina del perimetro sa disegnare.
     *
     * ⚠️ **Non** è più la terna del `match` di `Perimetro::daRichiesta()`, e non
     * lo era mai davvero: `daRichiesta()` risponde «quale perimetro» e fa cadere
     * su `nessuno()` anche il modo **valido** «piano» finché il piano manca. Qui
     * la domanda è un'altra — «questo modo esiste **nella tendina**?» — e serve
     * a far dire il vero al **controllo**, non alla query. I nomi restano quelli
     * di `Perimetro`: qui si duplica l'insieme, mai le stringhe.
     *
     * 🔴 `Perimetro::SCELTI` **non è in questa lista**, ed è la sostanza del
     * cambiamento del 29 Ago 2026: la scelta a mano dei clienti — una
     * `<select multiple>` da ricomporre a ogni visita — è diventata «i miei
     * preferiti», un insieme durevole della persona che si segna dall'elenco
     * Clienti. Il modo resta valido **nel dominio** (`Perimetro::nessuno()` è
     * `scelti([])`), ma non è più un modo che questa pagina sa disegnare:
     * togliendolo di qui, un `?modo=scelti` rimasto in un link salvato cade
     * sotto la stessa normalizzazione di un modo inventato.
     */
    private const MODI = [Perimetro::TUTTI, Perimetro::PER_PIANO, Perimetro::PREFERITI];

    /**
     * 🔴 Le due property che **disegnano un controllo** si normalizzano subito.
     *
     * `modo` e `piano` non finiscono solo nella query: finiscono in due
     * `<select wire:model.live>`, e una `<select>` legata a un valore che non
     * corrisponde a nessuna `<option>` non resta vuota — il browser evidenzia la
     * **prima**. Con `?modo=tutto-quanto` il perimetro cadeva correttamente su
     * `nessuno()` e la pagina mostrava zero righe, ma la tendina diceva «Tutti i
     * clienti»: un controllo che mente sopra una tabella vuota. Peggio, da lì non
     * si usciva — riselezionare «Tutti i clienti» non emette nessun evento,
     * perché il valore mostrato è già quello.
     *
     * 🔴 Il modo che non si è capito diventa `preferiti`, **non** `tutti`: è il
     * fail-closed di `Perimetro::nessuno()` detto anche dal controllo — chi non
     * ha preferiti vede zero righe e un consiglio vero, chi ne ha vede i suoi.
     *
     * ⚠️ E il ripiego **è cambiato insieme alla tendina**: finché la voce
     * «scelti» era in pagina, `SCELTI` era il fallback giusto per la stessa
     * ragione. Tolta la voce, quel ripiego sarebbe diventato *esso stesso* il
     * difetto che questo metodo esiste per togliere: un `modo` senza `<option>`
     * corrispondente, quindi di nuovo la prima voce evidenziata sopra una
     * tabella che non le corrisponde, e di nuovo nessuna strada per uscirne.
     */
    public function mount(): void
    {
        $this->normalizzaControlli();
    }

    /**
     * ⚠️ `mount()` non rigira sugli update, e le property arrivano dal browser a
     * **ogni** richiesta: senza questi due hook la normalizzazione varrebbe solo
     * per la prima pagina caricata.
     */
    public function updatedModo(): void
    {
        $this->normalizzaControlli();
    }

    public function updatedPiano(): void
    {
        $this->normalizzaControlli();
    }

    private function normalizzaControlli(): void
    {
        if (! in_array($this->modo, self::MODI, true)) {
            $this->modo = Perimetro::PREFERITI;
        }

        // Un piano fuori catalogo è già l'insieme vuoto per `Perimetro`; qui
        // torna a essere «nessun piano scelto» anche per la tendina, che
        // altrimenti mostrerebbe la prima voce come se fosse stata scelta.
        if ($this->piano !== '' && ! Piani::esiste($this->piano)) {
            $this->piano = '';
        }
    }

    public function updatingModo(): void
    {
        $this->resetPage();
    }

    public function updatingPiano(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * Chiude ogni modale della pagina.
     *
     * Richiesto da `OffreImpersonazione::apriScelta()`, che lo chiama per tenere
     * l'invariante «una modale alla volta». Qui la modale è una sola, e il
     * metodo esiste lo stesso: il concern è condiviso con la cabina, e un
     * componente che lo usasse senza fornirlo sarebbe fatale al primo clic.
     */
    public function chiudiOgniModale(): void
    {
        $this->sceltaImpersonazione = null;
    }

    /**
     * Cambia colonna o inverte la direzione.
     *
     * ⚠️ `resetPage()` anche qui e non solo negli hook dei filtri: cambiare
     * ordinamento rimescola **tutte** le righe, quindi restare a pagina 3 fa
     * atterrare a metà di un elenco che non si è mai visto dall'inizio.
     *
     * La whitelist è qui **e** in `RicambiDelParco::filtriNormalizzati()`:
     * questa ferma il clic, quella ferma la query string. Sono due strade, non
     * una ripetizione — e la definizione resta **una**, la mappa delle colonne.
     */
    public function ordina(string $colonna): void
    {
        if (! array_key_exists($colonna, RicambiDelParco::COLONNE_ORDINE)) {
            return;
        }

        // ⛔ Il confronto è contro lo stato **normalizzato**, non contro le
        // property grezze. `?sortBy=codice` è fuori dalla whitelist: la query
        // ordina per «nome» e l'intestazione «Pezzo» porta già la freccia ▲.
        // Leggendo il grezzo, il primo clic su «Pezzo» finiva nel ramo «colonna
        // nuova» e riscriveva lo stesso ordine — freccia ferma, elenco fermo,
        // e un secondo clic necessario per un effetto solo. Identico con
        // `?sortDir=DISCENDENTE`, che vale `asc` e veniva invertito in `asc`.
        $attuale = RicambiDelParco::filtriNormalizzati(
            $this->search,
            $this->sortBy,
            $this->sortDir,
            $this->perPage,
        );

        $this->resetPage();

        // In entrambi i rami `sortBy` viene **riscritto**: è ciò che ripulisce
        // dalla query string il nome di colonna che non esiste.
        $this->sortBy = $colonna;
        $this->sortDir = $attuale['sortBy'] === $colonna
            ? ($attuale['sortDir'] === 'asc' ? 'desc' : 'asc')
            : 'asc';
    }

    /** Le taglie di pagina offerte dalla vista: la costante non è raggiungibile da Blade. */
    public function opzioniPerPage(): array
    {
        return RicambiDelParco::PER_PAGE;
    }

    /**
     * I clienti **preferiti** di chi guarda: quelli che la pagina elenca sotto
     * la tendina, in sola lettura.
     *
     * 🔴 Non è più una `<select multiple>` da riempire: si segnano con la ★
     * nell'elenco Clienti e restano. Qui si mostrano soltanto — nomi e
     * conteggio — perché un secondo posto in cui si *cambia* l'insieme sarebbe
     * un secondo insieme il giorno in cui i due divergessero.
     *
     * L'ordine per ragione sociale **con tie-break sull'id** e l'intersezione
     * con l'insieme legittimo stanno dentro `Preferiti::clienti()`: un
     * preferito verso un account nel frattempo cestinato, o verso l'account di
     * piattaforma, non compare qui e non porta righe.
     *
     * @return Collection<int, Account>
     */
    // ⚠️ `private` come sulle altre due schede: la vista riceve `preferiti`
    // dai dati di `render()`, quindi un metodo pubblico qui sarebbe solo
    // un'azione Livewire in più raggiungibile dal browser — una query gata
    // che nessuna pagina chiede.
    private function preferiti(): Collection
    {
        // ⚠️ Solo nel modo che li disegna. Negli altri due l'elenco non è in
        // pagina, e leggerlo costerebbe comunque una query a ogni render —
        // cioè a ogni battuta nella casella di ricerca, che gira in
        // `wire:model.live.debounce`. È la stessa disciplina che valeva per la
        // tendina dei selezionabili, applicata a ciò che l'ha sostituita.
        if ($this->modo !== Perimetro::PREFERITI) {
            return new Collection;
        }

        return Preferiti::clienti();
    }

    /**
     * Il perimetro **normalizzato**, mai le property grezze.
     *
     * 🔴 Il modo «preferiti» non passa da `daRichiesta()`, e non è una svista:
     * i suoi id **non arrivano dal browser**, arrivano dal database, quindi
     * l'unico costruttore è `Preferiti::perimetro()` — che è gata sullo stesso
     * permesso della porta. Un chiamante che se lo dimenticasse otterrebbe
     * `nessuno()`, cioè zero righe: l'errore cade dalla parte giusta.
     *
     * ⛔ Il `piano` vuoto diventa `null` e non stringa vuota: `daRichiesta()`
     * distingue i due casi e fa cadere il secondo su `nessuno()`. Passargli `''`
     * come se fosse un piano lo porterebbe in `perPiano('')`, che non esiste a
     * catalogo e darebbe comunque l'insieme vuoto — stessa risposta, per una
     * strada che il giorno in cui `Piani` cambiasse potrebbe smettere di darla.
     */
    private function perimetro(): Perimetro
    {
        if ($this->modo === Perimetro::PREFERITI) {
            return Preferiti::perimetro();
        }

        // ⚠️ La lista di id passata è **vuota** per costruzione: la scelta a
        // mano non esiste più in questa pagina, e i due modi rimasti non la
        // leggono. Scriverci `$this->qualcosa` di pubblico rimetterebbe al
        // browser, sotto un altro nome, la lista che i preferiti gli tolgono.
        return Perimetro::daRichiesta(
            $this->modo,
            [],
            $this->piano === '' ? null : $this->piano,
        );
    }

    public function render(): View
    {
        $perimetro = $this->perimetro();

        $filtri = RicambiDelParco::filtriNormalizzati(
            $this->search,
            $this->sortBy,
            $this->sortDir,
            $this->perPage,
        );

        /** @var LengthAwarePaginator<Ricambio> $ricambi */
        $ricambi = RicambiDelParco::elenco($perimetro, $filtri)
            ->paginate($filtri['perPage'])
            ->onEachSide(1);

        // Di CHI sono le righe: sede, cliente e diffusione, in tre query a
        // costo costante. È il requisito che il committente ha posto per primo,
        // ed è anche ciò che rende sicuro il tasto «impersona» accanto a ogni
        // riga: si entra in casa del cliente il cui nome si sta leggendo.
        $contesto = RicambiDelParco::contesto($perimetro, $ricambi->getCollection());

        // 🔴 Il titolo dice **su chi** si sta guardando, con la regola unica
        // delle tre schede (`TitoloPerimetro`). Fino al 29 Ago 2026 questo H1
        // era scritto a mano in Blade e annunciava «di tutti i clienti» in
        // tutti e tre i modi: su una pagina da cui si impersona, un testo che
        // allarga il perimetro a parole lavora contro la sola cosa che conta.
        $preferiti = $this->preferiti();

        return view('livewire.piattaforma.parco-ricambi', [
            'titolo' => TitoloPerimetro::componi('Ricambi', $perimetro, $preferiti->count()),
            'perimetro' => $perimetro,
            'ricambi' => $ricambi,
            'sedi' => $contesto['sedi'],
            'clientiPerId' => $contesto['clienti'],
            'diffusione' => $contesto['diffusione'],
            // ⚠️ La **stessa** collezione già letta per il titolo: una sola
            // query, non due per la stessa risposta.
            'preferiti' => $preferiti,
            // Solo i clienti **in pagina**: `candidatiDellaPagina()` costa due
            // query e un giro di filtri, e i clienti dei «gemelli» non hanno un
            // pulsante da riempire.
            'candidatiPerAccount' => $this->candidatiDellaPagina($contesto['clientiInPagina']),
            'ordinamento' => [$filtri['sortBy'], $filtri['sortDir']],
            // La ricerca **come la query l'ha usata**, non come sta nella
            // casella: `ripulisciNome()` toglie anche l'NBSP, quindi una casella
            // che sembra piena può non essere una domanda. La vista deve dire il
            // vero su cosa ha cercato.
            'cercato' => $filtri['search'],
        ]);
    }
}
