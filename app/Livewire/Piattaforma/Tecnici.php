<?php

namespace App\Livewire\Piattaforma;

use App\Livewire\Piattaforma\Concerns\GestisceTecnici;
use App\Models\User;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * I **tecnici di EasyLab** e i clienti su cui lavorano (🔗 ADR-038).
 *
 * Attua la metà di 🔗 ADR-007/030 rimasta scoperta: il pivot `tecnico_cliente`
 * esiste a database dal 16 Ago 2026 e fino a oggi **nessuna interfaccia lo
 * scriveva** — la sua UI era promessa «per S6, con la pagina permessi», e
 * quella pagina è la matrice ruolo→permesso, che è un'altra cosa.
 *
 * 🔴 **Il permesso è `tenants.view_all`, non `utenti.view`.** Il secondo
 * sembrerebbe il naturale e ce l'ha **ogni Admin cliente**: è il precedente già
 * pagato con `audit.view`, scartato perché ridistribuibile dall'editor dei
 * ruoli — «un gate cross-tenant su un permesso ridistribuibile è una falla ad
 * attivazione differita». `tenants.view_all` è nel set bloccato.
 *
 * ## Autorizzazione a tre livelli, come la cabina
 *
 * 1. `can:tenants.view_all` sulla **rotta** — riapplicato anche sugli update di
 *    Livewire, che rimatcha `memo.path` e rigira i middleware persistenti.
 * 2. La **porta** `VistaPiattaforma::enti()`, attraversata a ogni render per
 *    leggere le sedi selezionabili. Gira però *dopo* l'azione, e con
 *    `skipRender()` non gira affatto.
 * 3. `Gate::authorize()` in **ogni azione**, dentro `risolviTecnico()`. È il
 *    livello che conta, e per una ragione che il primo non copre: `users` non ha
 *    global scope di tenancy (🔗 ADR-018), quindi un id che arriva dal browser
 *    raggiunge **qualunque** persona della piattaforma se nessuno lo ferma.
 *
 * ## ⚠️ Costo: niente una query per riga
 *
 * «Su quanti clienti lavora» è la colonna che invita naturalmente all'N+1, e la
 * strada ovvia — `withCount('portafoglioClienti')` — sarebbe **anche sbagliata**:
 * quella relazione è Eloquent su `UnitaOrganizzativa` e ne applica i global
 * scope, quindi per chi apre questa pagina (che un `tenant_id` proprio ce l'ha)
 * il conteggio sarebbe **zero su ogni riga**. Si legge dal pivot col query
 * builder, in **una query per pagina**, intersecato con le sedi che la porta ha
 * consegnato — così una sede cestinata non gonfia il numero.
 *
 * 🔗 I gesti che scrivono vivono in `Concerns\GestisceTecnici`.
 */
#[Layout('components.layouts.app')]
class Tecnici extends Component
{
    use GestisceTecnici, WithPagination;

    public const PERMESSO = VistaPiattaforma::PERMESSO;

    private const PER_PAGE = 10;

    public string $search = '';

    /** I cestinati non sono in elenco per difetto: sono la coda, non il lavoro. */
    public bool $conCestinati = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingConCestinati(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        // La porta, e con lei il permesso: `sediSelezionabili()` passa da
        // `VistaPiattaforma::enti()`. Le sedi servono comunque due volte — al
        // conteggio della colonna e alle caselle della modale — quindi non è
        // una lettura fatta per il gate.
        $sedi = $this->sediSelezionabili();

        $tecnici = $this->tecniciDiPiattaforma($this->conCestinati)
            // Il ruolo si legge accanto al nome (🔗 ADR-046): caricato qui, o
            // sarebbe una query per riga.
            ->with('roles:id,name')
            ->when(trim($this->search) !== '', function (Builder $q) {
                // I jolly di LIKE si neutralizzano: senza, `%` da solo
                // restituisce ogni riga. `ESCAPE` va dichiarato perché SQLite,
                // a differenza di Postgres, non ha un carattere di escape di
                // default. `LOWER LIKE` e non `ILIKE`, che è solo Postgres.
                $ricerca = addcslashes(mb_strtolower(trim($this->search)), '%_\\');

                $q->where(function (Builder $q) use ($ricerca) {
                    foreach (['name', 'email'] as $colonna) {
                        $q->orWhereRaw("LOWER({$colonna}) LIKE ? ESCAPE '\\'", ["%{$ricerca}%"]);
                    }
                });
            })
            // ⚠️ Tie-break sull'id: a parità di nome l'ordine fra due pagine è
            // una **proprietà del motore** — SQLite scandisce stabile, Postgres
            // può riordinare i pari fra la query di pagina 1 e quella di pagina
            // 2, e una riga esce da entrambe (o da nessuna).
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.piattaforma.tecnici', [
            'tecnici' => $tecnici,
            'clientiPerTecnico' => $this->clientiPerTecnico($tecnici->pluck('id')->all()),
            // Raggruppate per ragione sociale: la modale chiede «su quali
            // clienti», e le sedi di uno stesso cliente vanno lette insieme.
            'sediPerCliente' => $sedi->groupBy(fn ($sede) => $sede->account?->ragione_sociale ?? 'Senza contratto'),
            'tecnicoDelPortafoglio' => $this->tecnicoDelPortafoglio(),
        ]);
    }

    /**
     * Quanti clienti per ciascun tecnico **in pagina**, in una query sola.
     *
     * Il vincolo sulle sedi ammesse non è cosmetico: il soft delete di una sede
     * non cancella la riga di pivot, quindi contarle tutte direbbe «lavora su 3
     * clienti» dove i clienti raggiungibili sono 2.
     *
     * @param  list<int>  $tecnicoIds
     * @return Collection<int,int>
     */
    private function clientiPerTecnico(array $tecnicoIds): Collection
    {
        if ($tecnicoIds === []) {
            return collect();
        }

        return DB::table('tecnico_cliente')
            ->whereIn('tecnico_id', $tecnicoIds)
            ->whereIn('ente_id', $this->idSediAmmesse())
            ->groupBy('tecnico_id')
            ->selectRaw('tecnico_id, COUNT(*) as quanti')
            ->pluck('quanti', 'tecnico_id')
            ->map(fn ($n) => (int) $n);
    }

    /**
     * Il tecnico su cui è puntata la modale del portafoglio.
     *
     * Rilegge dal database attraverso lo stesso confine delle azioni: la modale
     * scrive un nome in intestazione, e quel nome non può venire da una property
     * che il browser ha impostato.
     */
    private function tecnicoDelPortafoglio(): ?User
    {
        if ($this->portafoglioTecnico === null) {
            return null;
        }

        return $this->tecniciDiPiattaforma()->find($this->portafoglioTecnico);
    }
}
