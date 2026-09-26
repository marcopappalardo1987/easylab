<?php

namespace Database\Factories;

use App\Models\Registrazione;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Registrazione>
 *
 * ⚠️ Le factory girano **unguarded**, quindi il `$fillable` vuoto del model
 * (che è la sua difesa contro il mass-assignment da una superficie pubblica)
 * non le riguarda: qui si scrive tutto per nome.
 */
class RegistrazioneFactory extends Factory
{
    protected $model = Registrazione::class;

    /** La password in chiaro che ogni fixture usa, per poter provare il login dopo. */
    public const PASSWORD = 'ParolaSegreta!2026';

    public function definition(): array
    {
        return [
            'nome_ente' => fake()->company(),
            'nome_referente' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make(self::PASSWORD),
            'piano' => 'saas',
            'email_verificata_at' => null,
            'stripe_session_id' => null,
            'completata_at' => null,
            'account_id' => null,
        ];
    }

    /** La casella è stata verificata: il checkout si può aprire. */
    public function verificata(): static
    {
        return $this->state(fn () => ['email_verificata_at' => now()]);
    }

    /** Il checkout è stato aperto: la sessione è nostra e non arriva dall'URL. */
    public function conSessione(?string $sessionId = null): static
    {
        return $this->state(fn () => [
            'email_verificata_at' => now(),
            'stripe_session_id' => $sessionId ?? 'cs_test_'.fake()->unique()->numerify('##########'),
        ]);
    }
}
