<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrgNode;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StrumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asset/strumento (ERD §5.1). Collocato in un nodo dell'albero
 * (`unita_organizzativa_id` = ubicazione). Usa il default `orgNodeColumn`
 * (`unita_organizzativa_id`), quindi eredita gratis l'isolamento per Ente
 * (BelongsToTenant) e la restrizione sotto-albero del Responsabile
 * (BelongsToOrgNode).
 */
class Strumento extends Model
{
    /** @use HasFactory<StrumentoFactory> */
    use BelongsToOrgNode, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'strumenti';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'unita_organizzativa_id',
        'nome',
        'modello',
        'matricola',
        'parametri_tecnici',
        'data_installazione',
    ];

    protected function casts(): array
    {
        return [
            'parametri_tecnici' => 'array',
            'data_installazione' => 'date',
        ];
    }

    /**
     * Ubicazione corrente (nodo dipartimento/sotto-laboratorio).
     */
    public function unita(): BelongsTo
    {
        return $this->belongsTo(UnitaOrganizzativa::class, 'unita_organizzativa_id');
    }
}
