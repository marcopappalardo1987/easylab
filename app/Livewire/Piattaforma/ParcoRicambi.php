<?php

namespace App\Livewire\Piattaforma;

use App\Livewire\Piattaforma\Concerns\OffreImpersonazione;
use App\Models\Account;
use App\Models\Ricambio;
use App\Support\Piani;
use App\Support\Piattaforma\ParcoClienti;
use App\Support\Piattaforma\Perimetro;
use App\Support\Piattaforma\RicambiDelParco;
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
 * ⛔ **Il perimetro arriva dal browser** — sono tre property `#[Url]` — quindi
 * non si usa mai grezzo: `Perimetro::daRichiesta()` lo normalizza (e in caso di
 * dubbio dà l'insieme **vuoto**, non «tutti»), e `ParcoClienti::clienti()` lo
 * **interseca** con l'insieme legittimo. Un id forgiato non allarga.
 *
 * ⚠️ **Le garanzie non compaiono in questa scheda, e non è una dimenticanza.**
 * `ParcoClienti::ricambi()` lascia applicato `GaranziaRicambioPrivacyScope`
 * (🔗 ADR-029), che non è uno scope di tenancy: risponde a «questo utente ha
 * titolo a vedere le garanzie dei pezzi montati?». Mostrare qui una garanzia —
 * o dedurla da un conteggio — significherebbe aggirare quella domanda per via
 * traversa. Se domani servisse, si legge da `ParcoClienti::garanzie()`.
 */
#[Layout('components.layouts.app')]
class ParcoRicambi extends Component
{
    use OffreImpersonazione, WithPagination;

    public const PERMESSO = VistaPiattaforma::PERMESSO;

    /** Su quali clienti: `tutti`, `piano` o `scelti` (🔗 `Perimetro`). */
    #[Url]
    public string $modo = Perimetro::TUTTI;

    /**
     * Gli account scelti a mano, quando `modo` vale `scelti`.
     *
     * @var array<int|string>
     */
    #[Url]
    public array $clientiScelti = [];

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
     * ⚠️ È la stessa terna del `match` di `Perimetro::daRichiesta()`, e le due
     * non si possono fondere: `daRichiesta()` risponde «quale perimetro» e fa
     * cadere su `nessuno()` anche il modo **valido** «piano» finché il piano
     * manca. Qui la domanda è un'altra — «questo modo esiste?» — e serve a far
     * dire il vero al **controllo**, non alla query. I nomi restano quelli di
     * `Perimetro`: qui si duplica l'insieme, mai le stringhe.
     */
    private const MODI = [Perimetro::TUTTI, Perimetro::PER_PIANO, Perimetro::SCELTI];

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
     * Il modo che non si è capito diventa `scelti`, **non** `tutti`: è il
     * fail-closed di `Perimetro::nessuno()` detto anche dal controllo, e il
     * consiglio che compare sotto («scegli almeno un cliente») diventa vero.
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
            $this->modo = Perimetro::SCELTI;
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

    public function updatingClientiScelti(): void
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
     * I clienti che il filtro può offrire: l'insieme legittimo, dalla porta.
     *
     * Ordinato per ragione sociale **con tie-break sull'id**: la tendina non è
     * paginata, ma due clienti omonimi darebbero un ordine diverso a ogni
     * render su Postgres, e una `<select>` che si rimescola sotto il dito è un
     * difetto che nessuno saprebbe descrivere.
     *
     * @return Collection<int, Account>
     */
    public function selezionabili(): Collection
    {
        // ⚠️ Solo nel modo che la disegna. Negli altri due la `<select>` non
        // esiste, e questa lista costava comunque una query e l'idratazione di
        // **ogni** account della piattaforma — a ogni battuta nella casella di
        // ricerca, che gira in `wire:model.live.debounce`.
        if ($this->modo !== Perimetro::SCELTI) {
            return new Collection;
        }

        return ParcoClienti::selezionabili()
            ->orderBy('accounts.ragione_sociale')
            ->orderBy('accounts.id')
            ->get(['accounts.id', 'accounts.ragione_sociale', 'accounts.piano']);
    }

    /**
     * Il perimetro **normalizzato**, mai le property grezze.
     *
     * ⛔ Il `piano` vuoto diventa `null` e non stringa vuota: `daRichiesta()`
     * distingue i due casi e fa cadere il secondo su `nessuno()`. Passargli `''`
     * come se fosse un piano lo porterebbe in `perPiano('')`, che non esiste a
     * catalogo e darebbe comunque l'insieme vuoto — stessa risposta, per una
     * strada che il giorno in cui `Piani` cambiasse potrebbe smettere di darla.
     */
    private function perimetro(): Perimetro
    {
        return Perimetro::daRichiesta(
            $this->modo,
            $this->clientiScelti,
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

        return view('livewire.piattaforma.parco-ricambi', [
            'perimetro' => $perimetro,
            'ricambi' => $ricambi,
            'sedi' => $contesto['sedi'],
            'clientiPerId' => $contesto['clienti'],
            'diffusione' => $contesto['diffusione'],
            'selezionabili' => $this->selezionabili(),
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
