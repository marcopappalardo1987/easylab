<?php

namespace Database\Factories;

use App\Enums\StatoIntervento;
use App\Enums\TipoIntervento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Intervento>
 */
class InterventoFactory extends Factory
{
    protected $model = Intervento::class;

    /**
     * Le FK restano null: usare sempre ->forStrumento($strumento), che propaga
     * anche il tenant (invariante interventi.tenant_id == strumenti.tenant_id).
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'reseller_id' => null,
            'strumento_id' => null,
            'tecnico_id' => null,
            'descrizione' => fake()->sentence(),
            'tipo' => TipoIntervento::ManutenzioneOrdinaria,
            'data_scadenza' => now()->addMonth()->toDateString(),
            'stato' => StatoIntervento::NonFatto,
            'data_esecuzione' => null,
        ];
    }

    public function forStrumento(Strumento $strumento): static
    {
        return $this->state(fn () => [
            'tenant_id' => $strumento->tenant_id,
            'strumento_id' => $strumento->id,
        ]);
    }

    public function assegnatoA(User $tecnico): static
    {
        return $this->state(fn () => ['tecnico_id' => $tecnico->id]);
    }

    /**
     * Eseguito: `data_esecuzione` a oggi se non specificata (come l'hook del model).
     */
    public function fatto(?string $data = null): static
    {
        return $this->state(fn () => [
            'stato' => StatoIntervento::Fatto,
            'data_esecuzione' => $data ?? today()->toDateString(),
        ]);
    }

    /**
     * Scaduto e non eseguito: il caso che accende l'arancione (ADR-005).
     */
    public function scaduto(): static
    {
        return $this->state(fn () => [
            'data_scadenza' => now()->subMonth()->toDateString(),
            'stato' => StatoIntervento::NonFatto,
            'data_esecuzione' => null,
        ]);
    }

    /**
     * Pianificato: scadenza futura, non ancora eseguito.
     */
    public function pianificato(): static
    {
        return $this->state(fn () => [
            'data_scadenza' => now()->addMonths(3)->toDateString(),
            'stato' => StatoIntervento::NonFatto,
            'data_esecuzione' => null,
        ]);
    }
}
