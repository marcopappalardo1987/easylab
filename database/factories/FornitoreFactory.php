<?php

namespace Database\Factories;

use App\Models\Fornitore;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fornitore>
 */
class FornitoreFactory extends Factory
{
    protected $model = Fornitore::class;

    /**
     * `tenant_id` resta null: si usa sempre `->forTenant($ente)`, come per
     * `Ricambio`. Una `create()` nuda produrrebbe una riga senza Ente, cioè
     * invisibile a ogni lettura scopata.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'reseller_id' => null,
            'ragione_sociale' => fake()->unique()->company(),
            'email' => fake()->companyEmail(),
            'telefono' => fake()->numerify('0## ######'),
            'note' => null,
        ];
    }

    public function forTenant(UnitaOrganizzativa $ente): static
    {
        return $this->state(fn () => ['tenant_id' => $ente->id]);
    }
}
