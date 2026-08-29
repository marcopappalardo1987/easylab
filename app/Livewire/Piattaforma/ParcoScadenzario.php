<?php

namespace App\Livewire\Piattaforma;

use App\Enums\TipoIntervento;
use App\Livewire\Piattaforma\Concerns\OffreImpersonazione;
use App\Support\Piani;
use App\Support\Piattaforma\Perimetro;
use App\Support\Piattaforma\Preferiti;
use App\Support\Piattaforma\ScadenzarioParco;
use App\Support\Piattaforma\TitoloPerimetro;
use App\Support\Semaforo;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Parco clienti — scheda «Scadenzario»: **cosa scade su tutto il parco**, in
 * SOLA LETTURA (🔗 ADR-037).
 *
 * È la vista che risponde alla domanda per cui il committente ha chiesto questa
 * funzione: «mi occupo della strumentazione di molti Enti e voglio vedere la
 * situazione a colpo d'occhio». Le stesse tre partizioni del scadenzario di un
 * Ente solo (`App\Livewire\Interventi\Scadenzario`) e le stesse regole — scaduto
 * da `Intervento::scopeScadute()`, soglia da `Semaforo` — con due colonne in
 * più che sono la ragione per cui la vista è accettabile: **cliente** e **sede**,
 * su ogni riga.
 *
 * 🔴 Tre componenti e non tre schede dentro uno solo: così i tre blocchi si
 * costruiscono su file disgiunti, e nessuno riscrive il lavoro dell'altro. La
 * fila di schede è un partial condiviso (`x-parco.nav`).
 *
 * ## Il confine, che è la ragione per cui la scheda esiste
 *
 * Da qui si **guarda** oltre il proprio Ente, non si scrive: nessuna azione di
 * scrittura, nessuna chiusura di intervento, nessun `skipRender()`. Ogni
 * modifica passa dall'impersonazione, che è per cliente e lascia una riga di
 * audit con dentro chi agiva e per conto di chi (🔗 ADR-018, ADR-027) — il
 * contesto che una scrittura cross-cliente perderebbe proprio dove serve di più.
 * Per questo accanto a ogni riga c'è il tasto «impersona»: è ciò che rende quel
 * confine rapido invece che fastidioso — e **atterra sulla scheda della
 * macchina della riga** (`piattaforma.parco.impersona`), non in dashboard, che
 * costringeva a ritrovare a mano la macchina appena vista in elenco.
 *
 * ⛔ **Si legge da `ParcoClienti`, mai da `VistaPiattaforma`**: i builder della
 * cabina non sono filtrati per cliente (servono a *contare* tutti i clienti, non
 * a *elencare* le righe di quelli scelti), quindi usarli qui mostrerebbe «tutti»
 * mentre il filtro in cima alla pagina dice altro. `ParcoBypassGuardrailTest` lo
 * rende rosso. `VistaPiattaforma::PERMESSO` resta lecito: è una costante, non
 * una query, ed è ciò che la rotta dichiara.
 *
 * ## Il perimetro arriva dal browser
 *
 * `modo` e `piano` sono property pubbliche, cioè input non fidato a ogni
 * update. La **forma** non si valida qui: si passano a
 * `Perimetro::daRichiesta()`, che normalizza e in caso di dubbio torna
 * `nessuno()` — mai «tutti» — e da lì a `ParcoClienti`, che **interseca** gli id
 * con l'insieme legittimo. Le due metà stanno in due posti apposta (forma e
 * appartenenza), e nessuna delle due vive in questo file.
 *
 * 🔴 Il terzo modo è **«i miei preferiti»** (29 Ago 2026): non più una
 * `<select multiple>` da ricomporre a ogni visita, ma un insieme durevole della
 * persona, segnato con la ★ nell'elenco Clienti. I suoi id **non arrivano dal
 * browser** — li legge `Preferiti::perimetro()`, gata sullo stesso permesso —
 * quindi la property `clientiScelti` è sparita invece di restare inerte: un
 * `#[Url]` che nessun ramo può onorare è un link che promette un filtro e non
 * lo applica.
 *
 * ⚠️ E qui vive l'**unica** guardia che questo file possiede in proprio:
 * `normalizzaControlli()`. `modo` e `piano` non finiscono solo nella query,
 * finiscono in due `<select wire:model.live>`, e una `<select>` legata a un
 * valore senza `<option>` corrispondente evidenzia la **prima** voce — «Tutti i
 * clienti» — sopra una tabella che tutti i clienti non li mostra. Da lì non si
 * esce: riselezionare quella voce non emette nessun evento, perché il valore
 * mostrato è già quello.
 *
 * ## Costo
 *
 * Dieci statement **costanti** rispetto al numero di righe: 1 count del
 * paginatore + 1 pagina + 1 macchine + 2 titolari (sedi e clienti) + 2 per i
 * candidati all'impersonazione (pivot e ruoli) + 3 contatori. Si parte da
 * `Intervento` e non da `Strumento`, quindi nessuna sottoquery correlata per
 * riga e nessun ordinamento su colonna derivata.
 *
 * L'undicesimo — l'elenco dei clienti preferiti — si esegue **solo nel modo in
 * cui quel blocco è in pagina**: negli altri due sarebbe una lettura scartata a
 * ogni render. Era la stessa disciplina della tendina dei selezionabili che i
 * preferiti hanno sostituito, e vale per la stessa ragione.
 *
 * ⚠️ **Macchina, cliente e sede NON si prendono dalle relazioni.**
 * `$intervento->strumento` e la risalita al nodo passano da modelli scopati sul
 * tenant di chi guarda: cross-cliente tornerebbero `null`, cioè una tabella di
 * trattini — plausibile e muta. Si rileggono dalla porta, che gli scope li
 * toglie per nome. La ragione per esteso è nel docblock di
 * `ScadenzarioParco::titolari()`.
 */
#[Layout('components.layouts.app')]
#[Title('Scadenzario del parco — Easy Lab')]
class ParcoScadenzario extends Component
{
    use OffreImpersonazione, WithPagination;

    public const PERMESSO = VistaPiattaforma::PERMESSO;

    /** Su quali clienti si sta guardando: `tutti`, `piano` o `preferiti`. */
    #[Url]
    public string $modo = Perimetro::TUTTI;

    /** Il piano commerciale, quando `modo` è `piano`. */
    #[Url]
    public ?string $piano = null;

    #[Url]
    public string $search = '';

    /** Partizione selezionata: valori ammessi in `ScadenzarioParco::STATI`. */
    #[Url]
    public ?string $stato = null;

    /** Filtro per tipo di attività (ADR-021), validato con `tryFrom()`. */
    #[Url]
    public ?string $tipo = null;

    #[Url]
    public string $sortDir = 'asc';

    /**
     * Righe per pagina. Arriva dalla query string, quindi si rivalida sempre
     * contro `PER_PAGE`: un `?perPage=999999` chiederebbe al DB l'intero
     * scadenzario di tutti i clienti della piattaforma.
     */
    #[Url]
    public int $perPage = self::PER_PAGE_DEFAULT;

    private const PER_PAGE = [20, 50, 100];

    private const PER_PAGE_DEFAULT = 20;

    /**
     * I tre modi che la tendina del perimetro sa disegnare.
     *
     * ⚠️ **Non** è la terna del `match` di `Perimetro::daRichiesta()`: quella
     * risponde «quale perimetro» e fa cadere su `nessuno()` anche il modo
     * **valido** «piano» finché il piano manca. Qui la domanda è un'altra —
     * «questo modo esiste **nella tendina**?» — e serve a far dire il vero al
     * **controllo**, non alla query. I nomi restano quelli di `Perimetro`: qui
     * si duplica l'insieme, mai le stringhe.
     *
     * 🔴 `Perimetro::SCELTI` non c'è, e resta valido nel dominio (`nessuno()` è
     * `scelti([])`): un `?modo=scelti` rimasto in un link salvato è, per questa
     * pagina, un modo che la tendina non sa disegnare — quindi cade sotto la
     * stessa normalizzazione di un modo inventato.
     */
    private const MODI = [Perimetro::TUTTI, Perimetro::PER_PIANO, Perimetro::PREFERITI];

    /**
     * 🔴 Le due property che **disegnano un controllo** si normalizzano subito.
     *
     * Il difetto è quello descritto nel docblock di classe: una `<select>`
     * legata a un valore senza `<option>` corrispondente evidenzia la prima
     * voce, e da lì non si esce. La forma è la stessa di `ParcoRicambi`, ed è
     * deliberatamente la stessa: il difetto è identico, quindi la risposta non
     * può essere diversa a seconda della scheda.
     *
     * Il modo che non si è capito diventa `preferiti`, **non** `tutti`: è il
     * fail-closed di `Perimetro::nessuno()` detto anche dal controllo — chi non
     * ha preferiti vede zero righe e un consiglio vero, chi ne ha vede i suoi.
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
        //
        // ⚠️ `null` e non `''`: qui la property è nullable e la `<option>`
        // vuota vale `''`, che Livewire riporta come stringa — entrambi i
        // valori vanno a `nessuno()` in `daRichiesta()`, e il ramo qui sotto li
        // riconduce a uno solo perché la tendina possa evidenziare «Scegli un
        // piano…» invece del primo piano a catalogo.
        if ($this->piano !== null && $this->piano !== '' && ! Piani::esiste($this->piano)) {
            $this->piano = null;
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

    public function updatingStato(): void
    {
        $this->resetPage();
    }

    public function updatingTipo(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * La **macchina** della riga da cui si è aperta la scelta del membro, o
     * `null` se quella riga non ne ha più una leggibile.
     *
     * Serve perché la scelta del membro è per *account* — `apriScelta()` vive
     * nel concern condiviso con la cabina, che di macchine non ne ha — mentre
     * qui l'impersonazione ha una **destinazione**: la scheda dello strumento
     * che si stava guardando. Senza, la modale rimanderebbe in dashboard
     * proprio nel caso in cui ritrovare la macchina a mano costa di più, cioè
     * quando i membri sono più d'uno.
     *
     * ⚠️ È una property pubblica, cioè input del browser a ogni update. **Non è
     * una guardia e non finge di esserlo**: cambia soltanto la destinazione del
     * link, e chi la riceve — `ImpersonaVersoStrumento` — rilegge la macchina
     * dalla porta, ne confronta il tenant con quello dell'impersonato e
     * altrimenti atterra in dashboard dicendolo. Le guardie dell'impersonazione
     * stanno tutte lì, insieme a quelle del pacchetto.
     */
    public ?int $strumentoImpersonazione = null;

    /**
     * Chiude ogni modale della pagina.
     *
     * `OffreImpersonazione::apriScelta()` la chiama per tenere l'invariante
     * «una modale alla volta»; qui la modale è una sola, e il metodo esiste
     * perché il concern è condiviso con la cabina — che di modali ne ha cinque.
     * Il componente è il solo posto che sa quali modali ha.
     */
    public function chiudiOgniModale(): void
    {
        $this->sceltaImpersonazione = null;

        // ⚠️ E con lei la **macchina**: `apriScelta()` passa di qui, quindi
        // riaprire la scelta da una riga senza macchina non può ereditare la
        // destinazione di quella precedente — che porterebbe l'operatore sulla
        // scheda di un'altra macchina, magari di un altro cliente, dopo aver
        // già cambiato identità.
        $this->strumentoImpersonazione = null;
    }

    /**
     * Apre la scelta del membro **portandosi dietro la macchina della riga**.
     *
     * ⛔ **Si delega, non si riscrive**: `apriScelta()` è il metodo del concern
     * e porta con sé il `Gate::authorize('utenti.impersonate')` e la rilettura
     * dell'account dalla porta. Aprire la modale da qui — un `$this->scelta… =
     * $accountId` — sarebbe un secondo ingresso con guardie più larghe, cioè il
     * modo in cui una regola si aggira senza accorgersene.
     *
     * ⚠️ E l'ordine non è indifferente: `apriScelta()` chiama
     * `chiudiOgniModale()`, che **azzera la macchina**. Assegnando per primo la
     * destinazione verrebbe cancellata subito dopo, e la modale rimanderebbe in
     * dashboard — cioè il difetto che questo metodo esiste per togliere, in una
     * forma che si legge come corretta.
     */
    public function apriSceltaSuStrumento(int $accountId, ?int $strumentoId = null): void
    {
        $this->apriScelta($accountId);

        $this->strumentoImpersonazione = $strumentoId;
    }

    /**
     * Il link «impersona» di una riga: verso la **scheda della macchina**,
     * quando la riga ne ha una.
     *
     * 🔴 Sta qui e non in Blade perché è la stessa decisione presa in due posti
     * — la riga col candidato unico e la modale coi candidati multipli — e due
     * copie divergono: quella meno guardata resterebbe sulla rotta del
     * pacchetto, cioè continuerebbe a rimbalzare in dashboard senza che nulla
     * diventi rosso. Chiesto da Marco il 29 Ago 2026: il tasto del parco serve
     * a intervenire in fretta, e il rimbalzo gli toglie proprio quello.
     *
     * La rotta del pacchetto resta il **ripiego** quando non c'è una macchina su
     * cui atterrare: entrare come quel cliente ha senso lo stesso, atterrare su
     * un 404 no.
     *
     * ⚠️ **Dove il ripiego scatta davvero, e dove no.** Scatta dalla modale
     * aperta da `apriScelta()` — la strada del concern, che di macchine non sa
     * nulla ed è quella da cui apre la cabina — e dopo `chiudiOgniModale()`,
     * che azzera la destinazione apposta. **Non** scatta dalla riga della
     * tabella: `ScadenzarioParco::base()` tiene solo gli interventi di una
     * macchina ancora leggibile e `macchine()` costruisce la mappa dallo
     * *stesso* builder nella *stessa* richiesta, quindi la chiave non può
     * mancare — cestinare uno strumento con la pagina aperta non lascia una
     * riga senza macchina, fa **sparire la riga**. Lì `$macchinaId === null` è
     * difesa in profondità, oggi irraggiungibile: sta scritto perché il
     * prossimo lettore non si fidi di uno scenario che non esiste per
     * giustificare altro codice.
     */
    public function linkImpersona(int $utenteId, ?int $strumentoId): string
    {
        return $strumentoId === null
            ? route('impersonate', $utenteId)
            : route('piattaforma.parco.impersona', ['utente' => $utenteId, 'strumento' => $strumentoId]);
    }

    /**
     * Le tre tile in cima indirizzano l'elenco, come nella dashboard.
     *
     * `null` («Tutti gli aperti») è un valore legittimo; qualunque altra cosa
     * ricade su `null` invece di produrre un elenco che non corrisponde a
     * nessuna etichetta.
     */
    public function filtra(?string $stato): void
    {
        $this->stato = in_array($stato, ScadenzarioParco::STATI, true) ? $stato : null;
        $this->resetPage();
    }

    /**
     * Inverte la direzione dell'ordinamento.
     *
     * ⛔ Si inverte a partire dalla direzione **effettivamente applicata**, mai
     * dal valore grezzo: `sortDir` arriva dalla query string e può valere
     * qualunque cosa. Con due copie della whitelist un `?sortDir=inventato`
     * renderebbe 'asc' e al primo clic riscriverebbe 'asc' — l'elenco non si
     * muove e la freccia non cambia. Stessa ragione di `Scadenzario` e di
     * `RegistroAudit::inverti()`.
     */
    public function invertiOrdine(): void
    {
        $this->sortDir = $this->direzione() === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    /**
     * Almeno un filtro è attivo, cioè l'elenco è un SOTTOINSIEME degli aperti
     * del perimetro.
     *
     * 🔴 Esiste come metodo perché **la vista non deve riscrivere questa
     * lista**: quando la condizione viveva in Blade nominava due filtri su
     * cinque e mandava a togliere filtri che il componente aveva già scartato.
     * Ogni ramo usa **lo stesso predicato con cui il filtro è applicato**: un
     * `?tipo=inventato` non è un filtro applicato, quindi non è un filtro da
     * annunciare.
     *
     * ⚠️ Il **perimetro** non è qui dentro: non è un filtro dell'elenco, è il
     * suo confine, e ha un messaggio suo quando è vuoto.
     */
    public function haFiltriAttivi(): bool
    {
        return filled($this->search)
            || $this->tipoValido() !== null
            || in_array($this->stato, ScadenzarioParco::STATI, true);
    }

    /**
     * Opzioni del select delle righe per pagina.
     *
     * @return list<int>
     */
    public function opzioniPerPage(): array
    {
        return self::PER_PAGE;
    }

    /**
     * Il perimetro come arriva dal browser — normalizzato **fuori di qui**.
     *
     * Un `modo` sconosciuto o un piano fuori catalogo cadono su `nessuno()`,
     * mai su «tutti»: fra i due errori possibili, mostrare più di quanto è
     * stato chiesto è quello che non si vede.
     */
    private function perimetro(): Perimetro
    {
        // 🔴 Il modo «preferiti» non passa da `daRichiesta()`, e non è una
        // svista: i suoi id non arrivano dal browser, arrivano dal database,
        // quindi l'unico costruttore è `Preferiti::perimetro()` — gata sullo
        // stesso permesso della porta. Un chiamante che se lo dimenticasse
        // otterrebbe `nessuno()`, cioè zero righe.
        if ($this->modo === Perimetro::PREFERITI) {
            return Preferiti::perimetro();
        }

        // ⚠️ La lista di id è **vuota** per costruzione: la scelta a mano non
        // esiste più in questa pagina, e i due modi rimasti non la leggono.
        return Perimetro::daRichiesta($this->modo, [], $this->piano);
    }

    /**
     * La direzione **effettivamente applicata**, e l'unico posto in cui la
     * whitelist di `sortDir` è scritta.
     *
     * `mb_strtolower()` perché un `?sortDir=DESC` incollato a mano è la stessa
     * direzione, non un valore da scartare.
     */
    private function direzione(): string
    {
        return mb_strtolower(trim($this->sortDir)) === 'desc' ? 'desc' : 'asc';
    }

    /** Il tipo selezionato se è un valore dell'enum, altrimenti nulla (ADR-021). */
    private function tipoValido(): ?TipoIntervento
    {
        return $this->tipo === null ? null : TipoIntervento::tryFrom($this->tipo);
    }

    public function render(): View
    {
        // Rivalidati a ogni render e non solo negli hook: i valori arrivano
        // dalla query string senza passare da `updating*`.
        $perimetro = $this->perimetro();
        $direzione = $this->direzione();
        $perPage = in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : self::PER_PAGE_DEFAULT;
        $stato = in_array($this->stato, ScadenzarioParco::STATI, true) ? $this->stato : null;

        $base = fn () => ScadenzarioParco::base($perimetro, $this->search, $this->tipoValido());

        $pagina = fn () => ScadenzarioParco::ordinata(
            ScadenzarioParco::partiziona($base(), $stato),
            $direzione
        )->paginate($perPage)->onEachSide(1);

        $interventi = $pagina();

        // ⚠️ `page` sta nella query string e nessuno lo riconduce da sé: una
        // pagina oltre l'ultima esistente torna ZERO righe mentre le tile
        // restano corrette, e la tabella direbbe «Nessun intervento aperto»
        // davanti a un elenco che ne ha cinque una pagina più indietro. Si
        // rimbalza sull'ULTIMA pagina esistente, che è quella più vicina a dove
        // si stava guardando, e solo con righe da mostrare.
        if ($interventi->total() > 0 && $interventi->currentPage() > $interventi->lastPage()) {
            $this->setPage($interventi->lastPage());
            $interventi = $pagina();
        }

        $righe = $interventi->getCollection();

        [
            'titolari' => $titolari,
            'clienti' => $clienti,
        ] = ScadenzarioParco::titolari($perimetro, $righe->pluck('tenant_id')->filter()->unique()->values()->all());

        // I contatori portano ricerca e tipo ma **non** la partizione
        // selezionata: applicandola anche a loro, due tile su tre direbbero zero
        // appena se ne clicca una, e non si potrebbe più passare dall'una
        // all'altra leggendo i numeri. Passano dalla stessa `base()` delle
        // righe, quindi non possono divergere da ciò che l'elenco mostra —
        // compreso il perimetro, che è la superficie che si dimentica: un
        // conteggio fuori dal filtro direbbe il numero di clienti che non sono
        // in pagina, senza mostrarne una riga.
        $contatori = [
            'scaduti' => ScadenzarioParco::partiziona($base(), 'scaduti')->count(),
            'in_scadenza' => ScadenzarioParco::partiziona($base(), 'in_scadenza')->count(),
            'oltre' => ScadenzarioParco::partiziona($base(), 'oltre')->count(),
        ];

        // 🔴 Il titolo dice **su chi** si sta guardando, e la regola è una sola
        // per le tre schede (`TitoloPerimetro`). Fino al 29 Ago 2026 questo H1
        // era scritto a mano in Blade e diceva «di tutti i clienti» in tutti e
        // tre i modi — anche con zero preferiti, cioè sopra tre contatori a
        // zero. Su una pagina da cui si impersona, un testo che allarga il
        // perimetro a parole lavora contro la sola cosa che conta: sapere di
        // CHI sono le righe che si stanno guardando.
        $preferiti = $this->modo === Perimetro::PREFERITI ? Preferiti::clienti() : collect();

        return view('livewire.piattaforma.parco-scadenzario', [
            'titolo' => TitoloPerimetro::componi('Scadenzario', $perimetro, $preferiti->count()),
            'interventi' => $interventi,
            'titolari' => $titolari,
            'macchine' => ScadenzarioParco::macchine(
                $perimetro,
                $righe->pluck('strumento_id')->filter()->unique()->values()->all()
            ),
            // I candidati all'impersonazione degli account in pagina: due query
            // fisse (pivot e ruoli) invece di due per riga, e nessuna affatto
            // per chi non potrà mai impersonare.
            'candidati' => $this->candidatiDellaPagina($clienti),
            'contatori' => $contatori,
            // ⛔ Il nome è `direzione` e non `sortDir`: quest'ultima è la
            // property pubblica, cioè il valore grezzo, e Livewire la condivide
            // con la vista DOPO i dati di `view()`. La freccia deve indicare
            // l'ordine applicato — indicare il contrario è una bugia piccola, e
            // per questo credibile.
            'direzione' => $direzione,
            'statoAttivo' => $stato,
            // La soglia si mostra e non si riscrive: viene da `Semaforo`, cioè
            // dallo stesso posto da cui la legge la query.
            'soglia' => Semaforo::giorniImminente(),
            'tipi' => TipoIntervento::cases(),
            // I preferiti di chi guarda, in SOLA LETTURA: nomi e conteggio.
            // L'ordine per ragione sociale col tie-break sull'id e
            // l'intersezione con l'insieme legittimo stanno dentro
            // `Preferiti::clienti()` — un preferito verso un account nel
            // frattempo cestinato, o verso l'account di piattaforma, non
            // compare qui e non porta righe.
            //
            // ⚠️ Si legge SOLO nel modo in cui l'elenco è in pagina, e la
            // condizione è la stessa che la vista usa per disegnarlo (`$modo`,
            // la property pubblica): negli altri due modi — «tutti» è il
            // default, quindi il caso normale — sarebbe una query buttata via a
            // ogni render, cioè a ogni pausa di digitazione nella ricerca. È la
            // stessa disciplina che valeva per la tendina dei selezionabili,
            // applicata a ciò che l'ha sostituita.
            // ⚠️ La **stessa** collezione già letta per il titolo: leggerla
            // due volte sarebbe una query in più per la stessa risposta, a
            // ogni pausa di digitazione nella ricerca.
            'preferiti' => $preferiti,
            'piani' => Piani::codici(),
            // ⛔ «Nessun cliente selezionato» e «questi clienti non hanno
            // scadenze» sono due fatti diversi, e una tabella vuota li
            // confonderebbe — che è la stessa distinzione fra un 403 e un
            // builder vuoto che la porta fa sul permesso.
            'perimetroVuoto' => $perimetro->eNessuno(),
        ]);
    }
}
