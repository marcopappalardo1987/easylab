<?php

namespace Database\Factories;

use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RicambioUtilizzo>
 */
class RicambioUtilizzoFactory extends Factory
{
    protected $model = RicambioUtilizzo::class;

    /**
     * Le FK restano null: le guardie del model (ERD §7.2) esigono che tenant,
     * strumento, ricambio ed eventuale intervento siano coerenti, quindi una
     * riga va sempre composta con gli stati "for*", che propagano il tenant dal
     * genitore. Una `create()` nuda fallisce di proposito.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'reseller_id' => null,
            'strumento_id' => null,
            'ricambio_id' => null,
            'intervento_id' => null,
            'quantita' => 1,
            'data' => today()->subMonths(2)->toDateString(),
        ];
    }

    /** Strumento su cui il pezzo è montato: porta con sé il tenant. */
    public function forStrumento(Strumento $strumento): static
    {
        return $this->state(fn () => [
            'tenant_id' => $strumento->tenant_id,
            'strumento_id' => $strumento->id,
        ]);
    }

    public function forRicambio(Ricambio $ricambio): static
    {
        return $this->state(fn () => ['ricambio_id' => $ricambio->id]);
    }

    /**
     * Intervento in cui il pezzo è stato montato (ADR-022, il punto d'ingresso
     * abituale). Propaga anche tenant e `strumento_id` DALL'INTERVENTO: la
     * guardia esige che l'intervento sia dello stesso strumento, e senza questa
     * propagazione ogni fixture ci inciamperebbe.
     */
    public function forIntervento(Intervento $intervento): static
    {
        return $this->state(fn () => [
            'tenant_id' => $intervento->tenant_id,
            'strumento_id' => $intervento->strumento_id,
            'intervento_id' => $intervento->id,
        ]);
    }
}
