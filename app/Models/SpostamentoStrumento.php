<?php

namespace App\Models;

use App\Enums\TipoSpostamento;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SpostamentoStrumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Log spostamenti strumento (ERD §5.4 — ADR-015). Append-only: si crea e non si
 * modifica/elimina mai (guard sotto). Tenant-scoped via BelongsToTenant; lo
 * storico si consulta solo dalla scheda di uno strumento già access-controllato.
 */
class SpostamentoStrumento extends Model
{
    /** @use HasFactory<SpostamentoStrumentoFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'spostamenti_strumento';

    protected $fillable = [
        'tenant_id',
        'strumento_id',
        'da_nodo_id',
        'da_esterno',
        'a_nodo_id',
        'a_esterno',
        'tipo_spostamento',
        'data',
        'eseguito_da',
        'nota',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'tipo_spostamento' => TipoSpostamento::class,
        ];
    }

    protected static function booted(): void
    {
        // Append-only: nessun aggiornamento o cancellazione a livello modello.
        static::updating(function (): void {
            throw new RuntimeException('SpostamentoStrumento è append-only: non modificabile.');
        });
        static::deleting(function (): void {
            throw new RuntimeException('SpostamentoStrumento è append-only: non eliminabile.');
        });
    }

    public function strumento(): BelongsTo
    {
        return $this->belongsTo(Strumento::class, 'strumento_id');
    }

    public function daNodo(): BelongsTo
    {
        return $this->belongsTo(UnitaOrganizzativa::class, 'da_nodo_id');
    }

    public function aNodo(): BelongsTo
    {
        return $this->belongsTo(UnitaOrganizzativa::class, 'a_nodo_id');
    }

    public function eseguitoBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eseguito_da');
    }

    public function origineLabel(): string
    {
        return $this->daNodo?->nome
            ?? ($this->da_esterno !== null ? "(esterno) {$this->da_esterno}" : '—');
    }

    public function destinazioneLabel(): string
    {
        return $this->aNodo?->nome
            ?? ($this->a_esterno !== null ? "(esterno) {$this->a_esterno}" : '—');
    }
}
