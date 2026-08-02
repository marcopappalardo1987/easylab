<?php

namespace Database\Factories;

use App\Models\LetturaContaore;
use App\Models\Strumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LetturaContaore>
 */
class LetturaContaoreFactory extends Factory
{
    protected $model = LetturaContaore::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'reseller_id' => null,
            'strumento_id' => null,
            'data' => today()->toDateString(),
            'ore' => fake()->numberBetween(100, 20000),
            'registrata_da' => null,
        ];
    }

    public function forStrumento(Strumento $strumento): static
    {
        return $this->state(fn () => [
            'tenant_id' => $strumento->tenant_id,
            'strumento_id' => $strumento->id,
        ]);
    }
}
