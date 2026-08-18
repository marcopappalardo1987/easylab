<?php

namespace Database\Factories;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
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

    /**
     * Aggancia il nodo (ente) a un account (ADR-032). Opt-in e non automatico
     * dentro `ente()`: centinaia di test creano Enti senza che l'account
     * c'entri, e una riga `accounts` implicita per ciascuno sarebbe rumore.
     * `account_id` è fuori dal fillable: si scrive col forceFill, come il
     * tenant_id qui sopra.
     */
    public function perAccount(Account $account): static
    {
        return $this->afterCreating(function (UnitaOrganizzativa $node) use ($account) {
            $node->forceFill(['account_id' => $account->id])->saveQuietly();
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
