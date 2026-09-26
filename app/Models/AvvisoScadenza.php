<?php

namespace App\Models;

use App\Enums\TransizioneAvviso;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SerializzaGiorniCivili;
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
 * 🔴 **Dal 27 Ago 2026 ospita anche l'obsolescenza (ADR-014), e per quella sola
 * transizione le righe si CANCELLANO.** `easylab:notifica-obsolescenza` scrive
 * una riga `transizione = obsoleta` col morph verso `Strumento` e
 * `data_scadenza = data_installazione` (nuda: vedi il docblock di
 * `App\Support\Notifiche\AvvisiObsolescenza` per il perché non sia
 * `installazione + soglia`). Le colonne non sono cambiate: `transizione` è una
 * `string` e `riferimento` un `morphs()`, quindi lo schema ospitava già sia il
 * valore nuovo sia il terzo tipo di riferimento.
 *
 * ⚠️ **Una migration è servita lo stesso, sulla chiave**: `avvisi_scadenza_unico`
 * comprende ora anche `tenant_id`. Con la `data_installazione` nuda come
 * `data_scadenza`, una macchina trasferita fra Enti (ADR-015) produce nel nuovo
 * Ente una riga identica in tutte e quattro le vecchie colonne a quella rimasta
 * al vecchio — e la violazione del vincolo faceva abortire il comando notturno
 * invece di riavvisare.
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
    use BelongsToTenant, Prunable, SerializzaGiorniCivili;

    protected $table = 'avvisi_scadenza';

    /**
     * Log append-only: si scrive una volta e non si aggiorna mai.
     *
     * ⚠️ **Vero sugli UPDATE, e da S6 non più sui DELETE** — la differenza è
     * dichiarata qui perché la frase da sola indurrebbe in errore. Per le
     * transizioni `Imminente` e `Scaduta` la riga registra un FATTO avvenuto e
     * resta finché la potatura non la porta via. Per `Obsoleta` registra invece
     * uno STATO derivato da `soglia_obsolescenza_anni`, che l'Admin può
     * rialzare: quando la macchina torna sotto la linea la sua riga viene
     * **cancellata** (decisione di prodotto del 27 Ago 2026), o non potrebbe mai
     * più essere riavvisata se la soglia riscendesse. È l'unico caso, ed è
     * l'unico che ha un enum a nominarlo: `TransizioneAvviso::Obsoleta`.
     */
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
     * ⚠️ **Per l'obsolescenza la stessa conseguenza è più marcata, e va
     * dichiarata o al primo `model:prune` sembrerà un difetto
     * dell'idempotenza**: una macchina obsoleta lo resta per sempre — il tempo
     * non la fa ringiovanire — quindi la sua riga potata a 24 mesi fa ripartire
     * un avviso, e da lì in avanti uno ogni due anni. È accettato con la stessa
     * motivazione: a quel punto non è un duplicato ma un **sollecito** biennale
     * su una macchina che nel frattempo ha preso altri due anni.
     *
     * @return Builder<AvvisoScadenza>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths(24));
    }

    /**
     * Intervento, Garanzia — **o Strumento**.
     *
     * ⚠️ La riga precedente diceva «le due sole fonti di scadenza del dominio»
     * ed è diventata falsa il 27 Ago 2026: per la transizione `Obsoleta` il
     * riferimento è lo **Strumento** stesso, che non è una scadenza ma la cosa
     * che invecchia. Resta vero che le fonti di *scadenza* sono due — la terza
     * riga di questo log non nasce da una scadenza (🔗 ADR-014/024).
     */
    public function riferimento(): MorphTo
    {
        return $this->morphTo();
    }
}
