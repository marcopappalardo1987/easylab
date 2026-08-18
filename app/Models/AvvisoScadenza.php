<?php

namespace App\Models;

use App\Enums\TransizioneAvviso;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Riga di memoria dello scheduler scadenze (ERD §5.5 — ADR-011, S5).
 *
 * Dice una cosa sola: «di *questa* scadenza, in *questa* transizione, ho già
 * avvisato». Il comando `easylab:notifica-scadenze` la interroga in
 * `whereNotExists` e la scrive prima di inviare, così un'interruzione costa al
 * più un'email persa e mai una duplicata.
 *
 * **Non è un audit e non va sul canale audit** (🔗 ADR-027): non registra un
 * gesto di una persona ma il lavoro di un cron. La sua esenzione è dichiarata in
 * `AuditCoverageGuardrailTest::ESENZIONI` — tracciarla significherebbe scrivere
 * l'audit di un log.
 *
 * **`BelongsToTenant` benché nasca in console.** Il trait in console non timbra
 * nulla (`CurrentTenant::shouldScope()` è false), quindi il `tenant_id` lo
 * valorizza il comando a mano; serve però per il lato lettura — una futura vista
 * di diagnostica deve vedere solo il proprio Ente — ed è ciò che il meta-test di
 * `TenantScopeGuardrailTest` esige da ogni modello di business.
 */
class AvvisoScadenza extends Model
{
    use BelongsToTenant, Prunable;

    protected $table = 'avvisi_scadenza';

    /** Log append-only: si scrive una volta e non si aggiorna mai. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'riferimento_type',
        'riferimento_id',
        'transizione',
        'data_scadenza',
    ];

    protected function casts(): array
    {
        return [
            'transizione' => TransizioneAvviso::class,
            'data_scadenza' => 'date',
        ];
    }

    /**
     * Rotazione a 24 mesi: è la «rotazione» che il registro dei trattamenti
     * dichiara per T4 (notifiche), qui resa un fatto invece che un'intenzione.
     *
     * ⚠️ Conseguenza accettata: una scadenza rimasta aperta e immutata per più
     * di due anni, potata la sua riga, riceve un secondo avviso. A quel punto
     * non è un duplicato ma un sollecito — e una scadenza aperta da due anni
     * merita di essere ricordata di nuovo.
     *
     * @return Builder<AvvisoScadenza>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths(24));
    }

    /** Intervento o Garanzia: le due sole fonti di scadenza del dominio. */
    public function riferimento(): MorphTo
    {
        return $this->morphTo();
    }
}
