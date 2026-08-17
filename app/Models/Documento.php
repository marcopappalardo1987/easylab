<?php

namespace App\Models;

use App\Enums\TipoDocumento;
use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Concerns\BelongsToOrgNodeThroughStrumento;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Contracts\ReachesStrumento;
use Database\Factories\DocumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

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
class Documento extends Model implements ReachesStrumento
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

    /**
     * Soggetti ammessi: la whitelist è esplicita perché `morphTo` accetta
     * QUALUNQUE stringa in `documentabile_type`, e una riga che puntasse a un
     * model senza `tenant_id` uscirebbe da ogni scoping — silenziosamente.
     */
    public const SOGGETTI = [Strumento::class, Intervento::class];

    /**
     * Due invarianti, su `creating`/`updating` e **mai su `saving`**: `saving`
     * gira PRIMA di `creating`, ed è in `creating` che `BelongsToTenant`
     * riscrive `tenant_id` — una guardia in `saving` confronterebbe un valore
     * che sta per cambiare, cioè non guarderebbe nulla. È la trappola già
     * pagata su `RicambioUtilizzo` e ripetuta su `Strumento`.
     */
    protected static function booted(): void
    {
        static::creating(fn (Documento $documento) => $documento->verificaSoggetto());
        static::updating(fn (Documento $documento) => $documento->verificaSoggetto());
    }

    /**
     * Query builder e non Eloquent: i global scope nasconderebbero proprio la
     * riga da controllare, e un riferimento cross-tenant si presenterebbe come
     * un "non trovato" dal messaggio fuorviante.
     */
    protected function verificaSoggetto(): void
    {
        if (! in_array($this->documentabile_type, self::SOGGETTI, true)) {
            throw new InvalidArgumentException(
                'Documento: si allega a uno Strumento o a un Intervento (ERD §8.1).'
            );
        }

        $tabella = $this->documentabile_type === Strumento::class ? 'strumenti' : 'interventi';
        $tenantSoggetto = DB::table($tabella)->where('id', $this->documentabile_id)->value('tenant_id');

        if ($tenantSoggetto === null || (int) $tenantSoggetto !== (int) $this->tenant_id) {
            throw new InvalidArgumentException(
                'Documento: il soggetto deve appartenere allo stesso Ente del documento — è l\'invariante su cui poggia il livello 2 dello scope (ADR-006).'
            );
        }

        // `strumento_id` è la colonna da cui dipende la restrizione al
        // sotto-albero: una riga che puntasse allo strumento sbagliato sarebbe
        // invisibile al Responsabile giusto e visibile a quello sbagliato.
        $strumentoAtteso = $this->documentabile_type === Strumento::class
            ? $this->documentabile_id
            : DB::table('interventi')->where('id', $this->documentabile_id)->value('strumento_id');

        if ((int) $this->strumento_id !== (int) $strumentoAtteso) {
            throw new InvalidArgumentException(
                'Documento: `strumento_id` deve essere lo strumento del soggetto (ERD §8.1).'
            );
        }
    }

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
