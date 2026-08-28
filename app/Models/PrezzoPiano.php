<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un Price di Stripe nella storia di un piano (🔗 ADR-035).
 *
 * Su Stripe un Price è **immutabile**: cambiare cifra ne crea uno nuovo e
 * archivia il vecchio. Le subscription in essere continuano però a fatturare
 * sul price archiviato — è ciò che rende attuabile la decisione di prodotto
 * «chi è già abbonato resta al suo» — quindi la riga vecchia va **conservata**,
 * o `Piani::perPrice()` smetterebbe di riconoscere il piano di ogni cliente
 * precedente e `accounts.piano` non si riallineerebbe più.
 *
 * **Niente `AuditsDomainWrites`, e non è una dimenticanza**: nessuna persona
 * crea, modifica o cancella queste righe — le scrive la sincronizzazione, che è
 * l'effetto di un gesto già tracciato su `Piano`. Tracciarle sarebbe l'audit di
 * un log, cioè la ragione per cui `AvvisoScadenza` è esente. L'esenzione è
 * dichiarata per nome in `AuditCoverageGuardrailTest::ESENZIONI`.
 *
 * **Niente `BelongsToTenant`**: tabella di piattaforma, come il piano a cui
 * appartiene (`TenantScopeGuardrailTest::NON_TENANT_MODELS`).
 */
class PrezzoPiano extends Model
{
    /** Il plurale italiano non si indovina: `prezzi_piano`, non `prezzo_pianos`. */
    protected $table = 'prezzi_piano';

    protected $fillable = [
        'piano_id',
        'stripe_price_id',
        'importo_cent',
        'valuta',
        'corrente',
    ];

    protected $attributes = [
        'corrente' => true,
    ];

    protected function casts(): array
    {
        return [
            'corrente' => 'boolean',
            'importo_cent' => 'integer',
        ];
    }

    /** @return BelongsTo<Piano, $this> */
    public function piano(): BelongsTo
    {
        return $this->belongsTo(Piano::class);
    }
}
