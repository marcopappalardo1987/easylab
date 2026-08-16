<?php

namespace App\Livewire\Ricambi;

use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ricerca incrociata «dove è montato questo pezzo» (🔗 ADR-008, ERD §7.1/§7.2).
 *
 * **Pagina propria e non un tab della scheda**, ed è la differenza che dà senso
 * al blocco: il tab Ricambi guarda una macchina e ne elenca i pezzi, qui la
 * domanda parte dal PEZZO e attraversa tutte le macchine dell'Ente. Nessuna
 * scheda strumento è il posto giusto per una risposta che non riguarda quella
 * macchina.
 *
 * È anche il consumatore per cui `nome_normalizzato` esiste: ADR-008 vuole un
 * catalogo con nomi normalizzati proprio perché questa ricerca dia una riga
 * sola per pezzo invece di una per grafia.
 *
 * ⚠️ **L'isolamento non è scritto qui, ed è voluto.** Ogni passo parte da un
 * model (`Ricambio`, `RicambioUtilizzo`, `Strumento`, `UnitaOrganizzativa`) e
 * si porta dietro i suoi global scope: confine Ente su tutti, sotto-albero del
 * Responsabile su `RicambioUtilizzo` (via `strumento_id`) e su `Strumento`.
 * Una `join` scritta a mano — la strada ovvia per «pezzo → macchine →
 * laboratori» — avrebbe portato in query tabelle senza i loro scope, e il
 * Responsabile avrebbe visto i montaggi di tutto l'Ente. Le quattro query
 * costano meno di quella tentazione.
 *
 * 🧪 **Il confine è doppio, e la prova di mutazione lo dice esplicitamente**:
 * togliere lo scope dai soli montaggi NON fa cadere nessun test, perché le
 * macchine sono a loro volta scopate e la riga rimasta orfana viene scartata
 * dal filtro qui sotto; e viceversa. Cadono le mutazioni che li tolgono
 * INSIEME — per il sotto-albero e per l'Ente. È la stessa forma di "guardia su
 * due livelli" già registrata su `Ricambio::nome_normalizzato`, e va saputa
 * prima di "semplificare" togliendone uno: sembrerebbe gratis, e la suite
 * resterebbe verde.
 */
#[Layout('components.layouts.app')]
class RicercaRicambi extends Component
{
    /**
     * Tetto ai risultati di catalogo, non alle macchine.
     *
     * Serve a tenere COSTANTE il numero di query: le tre query a valle lavorano
     * su un `whereIn` costruito da questa lista, e senza un tetto una ricerca
     * generica ("o") ci infilerebbe l'intero catalogo. Il troncamento si
     * dichiara in pagina invece di accadere in silenzio — un elenco tagliato
     * senza avviso si legge come un elenco completo.
     */
    public const MAX_RISULTATI = 50;

    #[Url]
    public string $search = '';

    /**
     * Corrispondenze di catalogo.
     *
     * Il terzo elemento dice se una domanda è stata posta, e non è una comodità
     * della vista: «casella vuota» lo decide `ripulisciNome()`, che toglie anche
     * l'NBSP dei copia-incolla, mentre un `blank(trim(...))` nel blade sarebbe
     * una SECONDA definizione della stessa regola — e le due divergerebbero
     * esattamente sul carattere per cui la prima esiste.
     *
     * @return array{0: Collection<int, Ricambio>, 1: bool, 2: bool} righe, "troncato", "domanda posta"
     */
    protected function catalogoCheCorrisponde(): array
    {
        try {
            // La regola di normalizzazione si CHIAMA, non si riscrive in SQL:
            // è l'intero motivo per cui la colonna `nome_normalizzato` esiste
            // (ERD §7.1). Un `lower(nome)` nel WHERE avrebbe pure due difetti
            // pratici: non usa l'indice, e `like` è case-insensitive su SQLite
            // ma case-SENSITIVE su Postgres — cioè una ricerca che funziona in
            // locale e non in produzione.
            $ago = Ricambio::normalizzaNome($this->search);
        } catch (InvalidArgumentException) {
            // Ricerca vuota — o di soli spazi, compreso l'NBSP che `trim()` non
            // toglie e che `ripulisciNome()` invece riconosce. Si torna il
            // vuoto e NON l'intero catalogo: una casella non ancora compilata
            // non è una domanda, e un elenco completo travestito da risultato
            // farebbe credere che quei pezzi c'entrino con la ricerca.
            return [collect(), false, false];
        }

        // `%`, `_` e `\` diventano testo: chi cerca "50%" cerca il pezzo che si
        // chiama così, non "tutto ciò che inizia per 50". Il carattere di
        // escape va DICHIARATO perché SQLite, a differenza di Postgres, non ne
        // ha uno per default — senza `escape` il backslash resterebbe un
        // carattere qualunque e la difesa varrebbe su un driver solo.
        $ago = addcslashes($ago, '%_\\');

        $trovati = Ricambio::query()
            ->whereRaw("\"nome_normalizzato\" like ? escape '\\'", ['%'.$ago.'%'])
            // Sulla colonna normalizzata e non su `nome`: l'ordinamento di
            // `nome` dipenderebbe dalla collation del driver, e due ambienti
            // darebbero due ordini per la stessa ricerca.
            ->orderBy('nome_normalizzato')
            ->limit(self::MAX_RISULTATI + 1)
            ->get();

        return [
            $trovati->take(self::MAX_RISULTATI),
            $trovati->count() > self::MAX_RISULTATI,
            true,
        ];
    }

    /**
     * «Pezzo → macchine → laboratori» in tre query, indipendenti dal numero di
     * righe: aggregato per `(ricambio, strumento)`, poi le macchine, poi i nomi
     * dei nodi. La strada ingenua — un `with('utilizzi.strumento.unita')` —
     * sarebbe stata una query per pezzo e poi una per macchina.
     *
     * @param  Collection<int, Ricambio>  $ricambi
     * @return Collection<int, array<string, mixed>>
     */
    protected function montaggiPerRicambio(Collection $ricambi): Collection
    {
        $righe = RicambioUtilizzo::query()
            // Colonne QUALIFICATE anche senza join: `DepartmentThroughStrumentoScope`
            // qualifica le proprie, e `tenant_id`/`strumento_id` esistono su più
            // tabelle di questo dominio — l'ambiguità si paga a distanza.
            ->selectRaw(
                'ricambio_utilizzo.ricambio_id, ricambio_utilizzo.strumento_id, '.
                'count(*) as montaggi, '.
                'sum(ricambio_utilizzo.quantita) as pezzi, '.
                // ADR-020: `data` NULL è «registrato ma non ancora montato».
                // Non si esclude, si CONTA a parte: la riga esiste davvero e
                // nasconderla farebbe sparire un pezzo che qualcuno ha
                // registrato, mentre confonderla con un montaggio direbbe una
                // cosa falsa sulla macchina. Il `case` sta qui e non in PHP
                // perché altrimenti servirebbe leggere le righe una per una.
                'sum(case when ricambio_utilizzo.data is null then 1 else 0 end) as non_montati'
            )
            ->whereIn('ricambio_utilizzo.ricambio_id', $ricambi->modelKeys())
            ->groupBy('ricambio_utilizzo.ricambio_id', 'ricambio_utilizzo.strumento_id')
            ->get();

        // I pezzi cestinati (smontati) non arrivano fin qui: `SoftDeletes` è
        // sul model, e questa query passa dal model apposta.

        $strumenti = Strumento::query()
            ->whereIn('id', $righe->pluck('strumento_id')->unique())
            ->get(['id', 'nome', 'matricola', 'unita_organizzativa_id'])
            ->keyBy('id');

        $laboratori = UnitaOrganizzativa::query()
            ->whereIn('id', $strumenti->pluck('unita_organizzativa_id')->unique())
            ->pluck('nome', 'id');

        // Indicizzato una volta sola: un `where('ricambio_id', …)` dentro la
        // map riscorrerebbe l'intero aggregato per ogni pezzo trovato.
        $perRicambio = $righe->groupBy('ricambio_id');

        return $ricambi
            ->map(fn (Ricambio $ricambio) => $this->componiRisultato(
                $ricambio,
                $perRicambio->get($ricambio->id, collect()),
                $strumenti,
                $laboratori,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, RicambioUtilizzo>  $aggregati
     * @param  Collection<int, Strumento>  $strumenti
     * @param  Collection<int, string>  $laboratori
     * @return array<string, mixed>
     */
    protected function componiRisultato(
        Ricambio $ricambio,
        Collection $aggregati,
        Collection $strumenti,
        Collection $laboratori,
    ): array {
        $macchine = $aggregati
            // Riga senza macchina corrispondente = macchina CESTINATA (lo scope
            // di `Strumento` la esclude, quello di `RicambioUtilizzo` no — e la
            // differenza è deliberata lì, vedi `DepartmentThroughStrumentoScope`).
            // Qui si scarta: «dove è montato questo pezzo» chiede macchine su cui
            // si può ancora andare a mettere le mani, e una riga con la colonna
            // macchina vuota si leggerebbe come un dato mancante.
            ->filter(fn (RicambioUtilizzo $r) => $strumenti->has($r->strumento_id))
            ->map(function (RicambioUtilizzo $r) use ($strumenti, $laboratori): array {
                $strumento = $strumenti->get($r->strumento_id);

                return [
                    'id' => $strumento->id,
                    'nome' => $strumento->nome,
                    'matricola' => $strumento->matricola,
                    'laboratorio_id' => (int) $strumento->unita_organizzativa_id,
                    // Nodo cestinato: il montaggio resta, il nome no. Meglio un
                    // trattino che una riga in meno.
                    'laboratorio' => $laboratori->get($strumento->unita_organizzativa_id) ?? '—',
                    'montaggi' => (int) $r->montaggi,
                    'pezzi' => (int) $r->pezzi,
                    'non_montati' => (int) $r->non_montati,
                ];
            })
            ->sortBy('nome')
            ->values();

        return [
            'id' => $ricambio->id,
            'nome' => $ricambio->nome,
            'codice' => $ricambio->codice,
            'macchine' => $macchine,
            // Raggruppati per ID e non per nome: due laboratori possono
            // chiamarsi entrambi "Chimica" sotto dipartimenti diversi, e
            // raggruppare per etichetta li fonderebbe in una riga sola.
            'laboratori' => $macchine
                ->groupBy('laboratorio_id')
                ->map(fn (Collection $gruppo) => [
                    'id' => $gruppo->first()['laboratorio_id'],
                    'nome' => $gruppo->first()['laboratorio'],
                    'macchine' => $gruppo->count(),
                    'pezzi' => (int) $gruppo->sum('pezzi'),
                ])
                ->sortByDesc('macchine')
                ->values(),
            // Somme calcolate sulle macchine SOPRAVVISSUTE al filtro qui sopra,
            // non sugli aggregati: un totale che comprende righe non elencate è
            // il modo più rapido di far dubitare della pagina intera.
            'totale_macchine' => $macchine->count(),
            'totale_pezzi' => (int) $macchine->sum('pezzi'),
            'non_montati' => (int) $macchine->sum('non_montati'),
        ];
    }

    public function render()
    {
        [$ricambi, $troncato, $domandaPosta] = $this->catalogoCheCorrisponde();

        // `ricambi.view` apre la pagina (è sulla rotta), ma le righe che si
        // mostrano sono montaggi: si chiede quindi ANCHE il permesso che le
        // governa, invece di dedurlo dal primo. Oggi nessun ruolo di
        // `config/rbac.php` ha l'uno senza l'altro; un ruolo creato dalla UI di
        // S6 potrebbe, e allora questo ramo è l'unica cosa che sta fra lui e i
        // dati. Permesso nudo via Gate e non ability di Policy perché su
        // `ricambio_utilizzo` una Policy non c'è: la trappola di ADR-029
        // riguarda le sole garanzie ricambio, dove il permesso scavalcherebbe
        // l'impostazione dell'Ente.
        $puoVedereMontaggi = Gate::allows('ricambio_utilizzo.view');

        return view('livewire.ricambi.ricerca-ricambi', [
            'risultati' => $puoVedereMontaggi && $ricambi->isNotEmpty()
                ? $this->montaggiPerRicambio($ricambi)
                : collect(),
            'puoVedereMontaggi' => $puoVedereMontaggi,
            'troncato' => $troncato,
            'domandaPosta' => $domandaPosta,
        ]);
    }
}
