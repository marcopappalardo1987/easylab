<?php

namespace Database\Factories;

use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Strumento>
 */
class StrumentoFactory extends Factory
{
    protected $model = Strumento::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'reseller_id' => null,
            'unita_organizzativa_id' => null,
            'nome' => fake()->words(2, true),
            'modello' => fake()->bothify('MOD-####'),
            'matricola' => fake()->bothify('SN-########'),
            'parametri_tecnici' => null,
            'data_installazione' => fake()->dateTimeBetween('-12 years', 'now')->format('Y-m-d'),
        ];
    }

    /**
     * Colloca lo strumento in un nodo: imposta ubicazione e tenant dell'Ente.
     */
    public function forNode(UnitaOrganizzativa $node): static
    {
        return $this->state(fn () => [
            'unita_organizzativa_id' => $node->id,
            'tenant_id' => $node->tenant_id,
        ]);
    }
}
