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

    /**
     * Lockout arrivato dal **webhook** e non da una persona (blocco Cashier):
     * è l'altra sorgente, e serve a esercitare l'incrocio fra le due — un
     * blocco manuale non deve essere riaperto da un pagamento riuscito.
     */
    public function bloccatoDaStripe(): static
    {
        return $this->state(fn () => [
            'is_locked' => true,
            'stripe_locked_at' => now(),
            'stripe_lock_reason' => 'Stripe: abbonamento in stato «unpaid» (fixture di test).',
        ]);
    }

    /**
     * L'account che è EasyLab stessa, non un cliente: la cabina di regia lo
     * esclude da tutti i KPI. Le factory girano unguarded, quindi il
     * fuori-fillable di `di_piattaforma` non le riguarda.
     */
    public function diPiattaforma(): static
    {
        return $this->state(fn () => ['di_piattaforma' => true]);
    }

    /** Account sul piano a pagamento: più di un Ente, e un customer Stripe. */
    public function saas(): static
    {
        return $this->state(fn () => ['piano' => 'saas']);
    }

    /**
     * Un account con un customer Stripe già associato. `cus_` fittizio: serve a
     * far risolvere `Cashier::findBillable()` nei test del webhook, che non
     * parlano con Stripe.
     */
    public function conStripe(?string $stripeId = null): static
    {
        return $this->state(fn () => [
            'stripe_id' => $stripeId ?? 'cus_test_'.fake()->unique()->numerify('##########'),
        ]);
    }
}
