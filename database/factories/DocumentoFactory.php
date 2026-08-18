<?php

namespace Database\Factories;

use App\Enums\TipoDocumento;
use App\Models\Documento;
use App\Models\Intervento;
use App\Models\Strumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Documento>
 */
class DocumentoFactory extends Factory
{
    protected $model = Documento::class;

    /**
     * Le FK restano null: si usa sempre `->perStrumento()` o `->perIntervento()`,
     * che tengono insieme morph, `strumento_id` e tenant. Una `create()` nuda
     * produrrebbe una riga incoerente proprio sulla colonna da cui dipende il
     * livello 2.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'reseller_id' => null,
            'documentabile_type' => null,
            'documentabile_id' => null,
            'strumento_id' => null,
            'tipo' => TipoDocumento::Manuale,
            'nome' => 'manuale-uso.pdf',
            'path' => 'documenti/test/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'size' => 120_000,
            'caricato_da' => null,
        ];
    }

    public function perStrumento(Strumento $strumento): static
    {
        return $this->state(fn () => [
            'tenant_id' => $strumento->tenant_id,
            'documentabile_type' => Strumento::class,
            'documentabile_id' => $strumento->id,
            'strumento_id' => $strumento->id,
        ]);
    }

    /** `strumento_id` viene dall'INTERVENTO: è la denormalizzazione che regge lo scope. */
    public function perIntervento(Intervento $intervento): static
    {
        return $this->state(fn () => [
            'tenant_id' => $intervento->tenant_id,
            'documentabile_type' => Intervento::class,
            'documentabile_id' => $intervento->id,
            'strumento_id' => $intervento->strumento_id,
            'tipo' => TipoDocumento::CertificatoTaratura,
            'nome' => 'certificato-taratura.pdf',
        ]);
    }
}
