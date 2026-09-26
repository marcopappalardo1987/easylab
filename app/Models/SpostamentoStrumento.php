<?php

namespace App\Models;

use App\Enums\TipoSpostamento;
use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SerializzaGiorniCivili;
use App\Models\Contracts\ReachesStrumento;
use Database\Factories\SpostamentoStrumentoFactory;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Log spostamenti strumento (ERD §5.4 — ADR-015). Append-only: si crea e non si
 * modifica/elimina mai (guard sotto). Tenant-scoped via BelongsToTenant; lo
 * storico si consulta solo dalla scheda di uno strumento già access-controllato.
 *
 * **Perché tracciare una tabella che sembra già un audit** (ADR-027). Questa
 * tabella è append-only e porta `eseguito_da` e `data`: la somiglianza inganna.
 * `data` è la data di BUSINESS, scelta nel form e quindi retrodatabile a
 * piacere, mentre `activity_log.created_at` è l'istante reale della scrittura;
 * `eseguito_da` è `auth()->id()` scritto a mano, è **nullable** (in import e
 * console resta NULL) ed è `nullOnDelete()`, quindi cancellando l'utente
 * sparisce l'unico riferimento a chi ha spostato — la riga di audit conserva
 * `causer_id` per conto proprio. Terzo motivo, quello che conta per S6: la
 * vista Audit filtra per `log_name = audit`, e ciò che non è su quel canale
 * semplicemente non c'è.
 *
 * Delle guardie append-only e del trait scatta quindi solo `created`, e non per
 * fortuna: `bootIfNotBooted()` esegue `boot()` → `bootTraits()` — dove
 * `LogsActivity` registra i propri listener — e solo dopo `booted()`, dove
 * stanno le eccezioni di questa classe. Un `update()` lancia prima di arrivare
 * a scrivere, quindi non lascia né riga né traccia.
 */
class SpostamentoStrumento extends Model implements ReachesStrumento
{
    /** @use HasFactory<SpostamentoStrumentoFactory> */
    use AuditsDomainWrites, BelongsToTenant, HasFactory, SerializzaGiorniCivili;

    protected $table = 'spostamenti_strumento';

    /**
     * Implementazione di `ReachesStrumento` (ADR-030): il tecnico che ha un
     * intervento assegnato deve poter leggere **dove è stata** la macchina su
     * cui va a lavorare — è la «lettura storico» che ADR-003 chiede alla vista
     * di campo del blocco 10.
     *
     * Scritta a mano invece di arrivare da `BelongsToOrgNodeThroughStrumento`,
     * e la differenza non è di comodo: quel trait porta con sé anche
     * `DepartmentThroughStrumentoScope`, cioè cambierebbe la visibilità degli
     * spostamenti per il Responsabile — una decisione che non appartiene a
     * questo blocco e che va presa guardando il caso suo (uno spostamento
     * attraversa due nodi, quindi «di quale nodo è» non ha una risposta ovvia).
     * Il meta-test di `TenantScopeGuardrailTest` ha segnalato questo modello, e
     * segnalarlo era giusto: senza, il tecnico avrebbe perso lo storico in
     * silenzio.
     *
     * @param  Builder<*>  $query
     * @param  Builder<*>|BuilderContract  $strumenti
     */
    public function vincolaAStrumenti(Builder $query, Builder|BuilderContract $strumenti): void
    {
        $query->whereIn($this->qualifyColumn('strumento_id'), $strumenti);
    }

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

    /**
     * «Creazione spostamento», non «Creazione spostamentostrumento»: il default
     * del trait è `class_basename` minuscolo, che su un nome composto produce
     * una parola che nessuno direbbe. La forma nome-primo della descrizione è
     * motivata per esteso in ADR-027 — lasciarla degradare così ne svuota il
     * senso.
     */
    protected function nomeDominio(): string
    {
        return 'spostamento';
    }

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
        // ⚠️ `withTrashed()`: da 🔗 ADR-038 una persona si cestina, e senza
        // questa riga l'attribuzione storica tornerebbe `null` — la pagina
        // direbbe «—» dove prima diceva un nome. Chi ha fatto una cosa l'ha
        // fatta anche dopo essersene andato.
        return $this->belongsTo(User::class, 'eseguito_da')->withTrashed();
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
