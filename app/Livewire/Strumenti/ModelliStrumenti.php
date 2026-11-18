<?php

namespace App\Livewire\Strumenti;

use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ricerca per modello (S2 stretch): dato un modello, in quali laboratori sono
 * installate le sue unità e quante. La query aggregata parte da Strumento, quindi
 * eredita i global scope (isolamento per Ente + sotto-albero del Responsabile).
 */
#[Layout('components.layouts.app')]
class ModelliStrumenti extends Component
{
    public const SENZA_MODELLO = '— senza modello —';

    #[Url]
    public string $search = '';

    public function render()
    {
        $query = Strumento::query()
            ->selectRaw('modello, unita_organizzativa_id, COUNT(*) as totale')
            ->groupBy('modello', 'unita_organizzativa_id');

        if (filled($this->search)) {
            // `%` e `_` scritti dall'utente sono letterali, come nelle altre ricerche (ESCAPE).
            $like = '%'.addcslashes(mb_strtolower(trim($this->search)), '%_\\').'%';
            $query->whereRaw("LOWER(COALESCE(modello, '')) LIKE ? ESCAPE '\\'", [$like]);
        }

        $righe = $query->get();

        // Il percorso dentro la sede, non il solo nome: due «Camera Bianca»
        // sotto laboratori diversi erano due etichette uguali. Le righe passano
        // già dallo scope: qui si scrive solo come chiamarle.
        $nomiNodi = UnitaOrganizzativa::percorsiNellaSede($righe->pluck('unita_organizzativa_id'));

        $modelli = $righe
            ->groupBy(fn ($r) => $r->modello ?? self::SENZA_MODELLO)
            ->map(fn ($gruppo, $modello) => [
                'modello' => $modello,
                'totale' => (int) $gruppo->sum('totale'),
                'laboratori' => $gruppo
                    ->map(fn ($r) => [
                        'id' => (int) $r->unita_organizzativa_id,
                        'nome' => $nomiNodi[$r->unita_organizzativa_id] ?? '—',
                        'conteggio' => (int) $r->totale,
                    ])
                    ->sortByDesc('conteggio')
                    ->values(),
            ])
            ->sortKeys()
            ->values();

        return view('livewire.strumenti.modelli-strumenti', [
            'modelli' => $modelli,
        ]);
    }
}
