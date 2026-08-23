<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
     * La issue di cui questa riga è un campione.
     *
     * @return BelongsTo<Errore, $this>
     */
    public function errore(): BelongsTo
    {
        return $this->belongsTo(Errore::class);
    }
}
