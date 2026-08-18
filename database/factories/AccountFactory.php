<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'ragione_sociale' => fake()->company(),
        ];
    }

    /**
     * Account in lockout per insoluto (ADR-013): lo switcher lo rifiuta già
     * oggi, il middleware arriverà col suo blocco. Le factory girano unguarded,
     * quindi il fuori-fillable di `is_locked` non le riguarda.
     */
    public function bloccato(): static
    {
        return $this->state(fn () => [
            'is_locked' => true,
            'locked_at' => now(),
            'locked_reason' => 'Insoluto (fixture di test)',
        ]);
    }
}
