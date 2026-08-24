<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un **avvenimento** dell'error tracker interno, col suo contesto (S6 — 🔗
 * `docs/Architettura/Error Tracker Interno (piano).md`).
 *
 * Risponde a «con quali dati si rompe»: stack trace, percorso, utente,
 * impersonazione, ip, user agent, input. La domanda «quante volte» la risponde
 * invece il contatore su `Errore`, perché queste righe sono un **campione** —
 * al più N per issue, una ogni M secondi (`config('easylab.errori')`).
 *
 * **Modello di PIATTAFORMA: niente `BelongsToTenant`**, come `Errore` e
 * `Account` (ADR-032). `user_id` dice *chi* c'era quando è successo, non *di
 * chi* è la riga: il contesto di un errore appartiene all'applicazione, e la
 * pagina che lo legge è del solo Developer. Esenzione dichiarata in
 * `TenantScopeGuardrailTest::NON_TENANT_MODELS`.
 *
 * **Niente `AuditsDomainWrites`**: è un log append-only scritto da un gestore di
 * eccezioni, non il gesto di una persona — la ragione di `AvvisoScadenza`, e la
 * sua esenzione è in `AuditCoverageGuardrailTest::ESENZIONI`.
 *
 * ⚠️ **Le righe di questa tabella portano dati personali** (stack trace, ip,
 * user agent, input) e la sanificazione avviene **in scrittura**, a monte: ciò
 * che entra qui in chiaro resta in chiaro. È anche la ragione del
 * `cascadeOnDelete`: cancellare una issue deve portare via le sue prove, non
 * lasciarle orfane e irraggiungibili.
 */
class OccorrenzaErrore extends Model
{
    use Prunable;

    /**
     * Novanta giorni, ed è **l'orizzonte più corto delle due tabelle**.
     *
     * Qui stanno i dati personali (stack trace con i percorsi, ip, user agent,
     * input della richiesta): la riga T8 del registro dei trattamenti dichiara
     * questa cifra, e la minimizzazione vuole che sia la più corta che risponda
     * ancora alla domanda «con quali dati si rompe». Tre mesi sono il tempo in
     * cui un guasto si guarda; oltre, la si legge dal contatore.
     *
     * Coincide con `Errore::GIORNI_CHIUSE` di proposito — vedi là il perché.
     */
    private const GIORNI = 90;

    /** Il default di Eloquent direbbe `occorrenza_errores`. */
    protected $table = 'occorrenze_errore';

    /**
     * Log append-only: si scrive una volta e non si aggiorna mai — come
     * `AvvisoScadenza`. Qui però non c'è nemmeno `created_at`: il momento è
     * `avvenuta_at`, e una seconda colonna che dice la stessa cosa è una seconda
     * colonna che può dirla diversa.
     */
    public $timestamps = false;

    /**
     * `$fillable` e non `$guarded`: vedi la nota su `Errore`. `$guarded = ['id']`
     * **rilassa** il default di Eloquent invece di stringerlo.
     */
    protected $fillable = [
        'errore_id', 'messaggio', 'stack_trace', 'percorso', 'metodo',
        'codice_http', 'user_id', 'impersonato_da', 'ip', 'user_agent',
        'input', 'contesto', 'avvenuta_at',
    ];

    protected function casts(): array
    {
        return [
            'codice_http' => 'integer',
            // Torna l'array già decodificato: la pagina di dettaglio lo scorre,
            // e chi lo scrive lo passa come array.
            'input' => 'array',
            'avvenuta_at' => 'datetime',
        ];
    }

    /**
     * La potatura, sul `model:prune` già schedulato
     * (`App\Support\Retention::MODELLI`).
     *
     * ⚠️ **Il confine è `avvenuta_at`**, che è l'unico momento che questa riga
     * conosce: non c'è `created_at` (`$timestamps = false`), ed è voluto — una
     * seconda colonna che dice la stessa cosa è una seconda colonna che può
     * dirla diversa. L'indice su `avvenuta_at` da sola esiste **per questa
     * query**: la potatura scandisce per data senza `errore_id`, e su questa
     * tabella non c'è nient'altro che cresca così.
     *
     * 🔴 **Nessuna clausola sullo stato della issue, e non è una dimenticanza.**
     * Un'occorrenza di novanta giorni fa se ne va anche se la sua issue è
     * `ignorato`, cioè l'unico stato che non si pota mai: ciò che non va potato
     * è **la riga di `errori`**, perché è lei a tenere il silenzio (toglierla la
     * farebbe rinascere `aperto` al prossimo `firstOrCreate`). Le sue prove non
     * tengono niente, e sono la parte che porta dati personali: conservarle per
     * sempre perché qualcuno ha zittito la issue sarebbe la conservazione
     * illimitata ottenuta con un click.
     *
     * ⚠️ **Il verso opposto non vale**: le prove non sopravvivono mai alla
     * propria issue, e non per questa query — per il `cascadeOnDelete` dello
     * schema, che porta via anche le occorrenze di ieri quando la issue viene
     * potata.
     *
     * `<` e non `<=` come su `Errore`: i confini si rieseguono su Postgres.
     *
     * @return Builder<OccorrenzaErrore>
     */
    public function prunable(): Builder
    {
        return static::query()->where('avvenuta_at', '<', now()->subDays(self::GIORNI));
    }

    /**
     * La issue di cui questa riga è un campione.
     *
     * @return BelongsTo<Errore, $this>
     */
    public function errore(): BelongsTo
    {
        return $this->belongsTo(Errore::class);
    }
}
