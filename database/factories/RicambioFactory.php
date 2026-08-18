<?php

namespace Database\Factories;

use App\Models\Ricambio;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ricambio>
 */
class RicambioFactory extends Factory
{
    protected $model = Ricambio::class;

    /**
     * Default: voce di catalogo senza codice (ADR-022: il codice è facoltativo).
     * Le FK restano null: usare sempre ->forTenant($ente), che propaga il tenant
     * senza dipendere dal contesto di autenticazione.
     * `nome_normalizzato` non si imposta mai: lo calcola il model.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'reseller_id' => null,
            'nome' => 'Guarnizione '.fake()->unique()->bothify('O-Ring ##?'),
            'codice' => null,
            'descrizione' => null,
        ];
    }

    public function forTenant(UnitaOrganizzativa $ente): static
    {
        return $this->state(fn () => ['tenant_id' => $ente->id]);
    }

    /** Voce con codice costruttore: serve alla ricerca per codice e al merge doppioni. */
    public function conCodice(string $codice): static
    {
        return $this->state(fn () => ['codice' => $codice]);
    }
}
