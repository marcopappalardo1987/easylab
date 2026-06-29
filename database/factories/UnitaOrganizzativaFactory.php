<?php

namespace Database\Factories;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitaOrganizzativa>
 */
class UnitaOrganizzativaFactory extends Factory
{
    protected $model = UnitaOrganizzativa::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'reseller_id' => null,
            'parent_id' => null,
            'tipo' => TipoUnitaOrganizzativa::Dipartimento,
            'nome' => fake()->company(),
            'note' => null,
        ];
    }

    /**
     * Nodo radice ente: il tenant_id coincide col proprio id (popolato dopo
     * l'insert), parent_id resta NULL.
     */
    public function ente(): static
    {
        return $this->state(fn () => [
            'tipo' => TipoUnitaOrganizzativa::Ente,
            'parent_id' => null,
        ])->afterCreating(function (UnitaOrganizzativa $node) {
            $node->forceFill(['tenant_id' => $node->id])->saveQuietly();
        });
    }

    public function dipartimento(): static
    {
        return $this->state(fn () => ['tipo' => TipoUnitaOrganizzativa::Dipartimento]);
    }

    public function sottolaboratorio(): static
    {
        return $this->state(fn () => ['tipo' => TipoUnitaOrganizzativa::Sottolaboratorio]);
    }

    /**
     * Figlio di un nodo dato: eredita il tenant_id dell'ente e imposta parent_id.
     */
    public function under(UnitaOrganizzativa $parent): static
    {
        return $this->state(fn () => [
            'tenant_id' => $parent->tenant_id,
            'parent_id' => $parent->id,
        ]);
    }
}
