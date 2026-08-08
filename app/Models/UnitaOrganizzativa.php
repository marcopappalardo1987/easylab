<?php

namespace App\Models;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Concerns\BelongsToOrgNode;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UnitaOrganizzativaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Nodo dell'albero organizzativo del cliente (ERD §4.1 — ADR-006/015).
 * Il nodo radice `tipo = ente` È il tenant.
 *
 * Scope: BelongsToTenant (livello 1, isolamento tra Enti) + BelongsToOrgNode
 * (livello 2, sotto-albero Responsabile — qui ristretto per il proprio `id`).
 */
class UnitaOrganizzativa extends Model
{
    /** @use HasFactory<UnitaOrganizzativaFactory> */
    use BelongsToOrgNode, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'unita_organizzativa';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'parent_id',
        'tipo',
        'nome',
        'note',
        'soglia_obsolescenza_anni',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoUnitaOrganizzativa::class,
            // Solo sul nodo ente (ADR-014); sugli altri resta al default e non si legge.
            'soglia_obsolescenza_anni' => 'integer',
        ];
    }

    /**
     * Un nodo è ristretto dal livello 2 in base al proprio id (non a una
     * colonna di collocazione esterna).
     */
    public function orgNodeColumn(): string
    {
        return 'id';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Nodo ente radice (= tenant) a cui appartiene questo nodo.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(self::class, 'tenant_id');
    }

    public function responsabili(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'responsabile_unita')->withTimestamps();
    }
}
