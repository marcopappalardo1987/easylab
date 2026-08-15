<?php

namespace App\Models;

use App\Enums\TipoDocumento;
use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Concerns\BelongsToOrgNodeThroughStrumento;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DocumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Documento allegato a uno Strumento o a un Intervento (ERD §8.1 —
 * ADR-009/025/026).
 *
 * Il livello 2 arriva da `BelongsToOrgNodeThroughStrumento`, che filtra su
 * `strumento_id`: è la colonna denormalizzata di cui la migration spiega la
 * ragione — sul solo morph la restrizione al sotto-albero non sarebbe
 * esprimibile senza una seconda copia della subquery di sicurezza.
 *
 * **Il file non si serve mai direttamente** (ADR-026): il download passa da una
 * rotta firmata dell'applicazione, che ricontrolla l'autorizzazione a ogni
 * richiesta. Una URL pre-firmata dell'object store sarebbe di fatto un bearer
 * token — chi ce l'ha legge il file fino alla scadenza, con la Policy fuori dal
 * giro — e legherebbe il prodotto al provider.
 */
class Documento extends Model
{
    /** @use HasFactory<DocumentoFactory> */
    use AuditsDomainWrites, BelongsToOrgNodeThroughStrumento, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'documenti';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'documentabile_type',
        'documentabile_id',
        'strumento_id',
        'tipo',
        'nome',
        'path',
        'mime',
        'size',
        'caricato_da',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumento::class,
            'size' => 'integer',
        ];
    }

    /** Il disco è UNO e dichiarato qui: `documenti`, l'unico con `throw => true`. */
    public const DISCO = 'documenti';

    public function documentabile(): MorphTo
    {
        return $this->morphTo();
    }

    public function strumento(): BelongsTo
    {
        return $this->belongsTo(Strumento::class, 'strumento_id');
    }

    public function caricatoBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caricato_da');
    }

    /**
     * Cancella la riga **e non il file** (soft delete).
     *
     * Il file resta nel bucket, e la rimozione fisica è materiale del job di
     * retention: cancellare subito renderebbe il soft delete una bugia — una
     * riga ripristinabile che punta a un oggetto che non c'è più. Si incrocia
     * col diritto alla cancellazione GDPR (T6, ancora aperto col legale), ed è
     * annotato lì.
     */
    public function url(): string
    {
        return route('documenti.download', $this);
    }

    /** Dimensione leggibile: la vista non deve fare aritmetica. */
    public function dimensioneLeggibile(): string
    {
        if ($this->size === null) {
            return '—';
        }

        return $this->size >= 1048576
            ? round($this->size / 1048576, 1).' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }

    public function esisteSulDisco(): bool
    {
        return Storage::disk(self::DISCO)->exists($this->path);
    }

    protected function nomeDominio(): string
    {
        return 'documento';
    }
}
