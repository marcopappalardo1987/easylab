<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrgNodeThroughStrumento;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LetturaContaoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Lettura manuale del contaore (ERD §5.3 — ADR-004): input alternativo per le
 * garanzie "a ore". In V1 lo storico è solo registrato; l'estrapolazione del
 * ritmo (≥2 letture → ore/giorno → data prevista) è V1.1.
 *
 * Append-only come SpostamentoStrumento: una lettura è un fatto osservato a una
 * data, non un dato da correggere. Se è sbagliata se ne registra un'altra —
 * così lo storico resta fedele a ciò che è stato letto, che è anche il motivo
 * per cui ADR-004 la indica come fonte per un'eventuale rivalsa sulle ore.
 */
class LetturaContaore extends Model
{
    /** @use HasFactory<LetturaContaoreFactory> */
    use BelongsToOrgNodeThroughStrumento, BelongsToTenant, HasFactory;

    protected $table = 'letture_contaore';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'strumento_id',
        'data',
        'ore',
        'registrata_da',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'ore' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('LetturaContaore è append-only: non modificabile.'));
        static::deleting(fn () => throw new RuntimeException('LetturaContaore è append-only: non eliminabile.'));
    }

    public function strumento(): BelongsTo
    {
        return $this->belongsTo(Strumento::class, 'strumento_id');
    }

    public function registrataBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrata_da');
    }
}
