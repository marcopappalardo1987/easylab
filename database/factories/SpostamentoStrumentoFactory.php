<?php

namespace Database\Factories;

use App\Enums\TipoSpostamento;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpostamentoStrumento>
 */
class SpostamentoStrumentoFactory extends Factory
{
    protected $model = SpostamentoStrumento::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'strumento_id' => null,
            'da_nodo_id' => null,
            'da_esterno' => null,
            'a_nodo_id' => null,
            'a_esterno' => null,
            'tipo_spostamento' => TipoSpostamento::Interno,
            'data' => now()->toDateString(),
            'eseguito_da' => null,
            'nota' => null,
        ];
    }

    public function forStrumento(Strumento $strumento): static
    {
        return $this->state(fn () => [
            'tenant_id' => $strumento->tenant_id,
            'strumento_id' => $strumento->id,
            'a_nodo_id' => $strumento->unita_organizzativa_id,
        ]);
    }
}
