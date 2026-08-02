<?php

namespace App\Models;

use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Models\Concerns\BelongsToOrgNode;
use App\Models\Concerns\BelongsToTenant;
use App\Support\AuditLog;
use App\Support\Semaforo;
use Database\Factories\StrumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * Asset/strumento (ERD §5.1). Collocato in un nodo dell'albero
 * (`unita_organizzativa_id` = ubicazione). Usa il default `orgNodeColumn`
 * (`unita_organizzativa_id`), quindi eredita gratis l'isolamento per Ente
 * (BelongsToTenant) e la restrizione sotto-albero del Responsabile
 * (BelongsToOrgNode).
 */
class Strumento extends Model
{
    /** @use HasFactory<StrumentoFactory> */
    use BelongsToOrgNode, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'strumenti';

    /**
     * NOTA: le colonne `forced_*` sono deliberatamente ESCLUSE. Il form della
     * scheda e l'import CSV scrivono per mass-assignment: tenendole fuori,
     * nessun payload — presente o futuro — può forzare il semaforo scavalcando
     * forzaSemaforo()/rimuoviForzatura(), che sono l'unica via.
     */
    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'unita_organizzativa_id',
        'nome',
        'modello',
        'matricola',
        'parametri_tecnici',
        'data_installazione',
    ];

    protected function casts(): array
    {
        return [
            'parametri_tecnici' => 'array',
            'data_installazione' => 'date',
            'forced_state' => StatoSemaforo::class,
            'forced_at' => 'datetime',
        ];
    }

    /**
     * Ubicazione corrente (nodo dipartimento/sotto-laboratorio).
     */
    public function unita(): BelongsTo
    {
        return $this->belongsTo(UnitaOrganizzativa::class, 'unita_organizzativa_id');
    }

    /**
     * Attività/interventi (ERD §5.2): storico passato + pianificati, scadenza
     * più recente in alto. Fonte di verità del semaforo (ADR-005).
     */
    public function interventi(): HasMany
    {
        return $this->hasMany(Intervento::class, 'strumento_id')
            ->orderByDesc('data_scadenza')
            ->orderByDesc('id');
    }

    /**
     * Garanzie del macchinario (ERD §6.1), la più vicina a scadere in alto.
     * Solo `soggetto = macchina`: le righe `ricambio` hanno `strumento_id` NULL
     * e si raggiungeranno via `ricambio_utilizzo` in S4.
     */
    public function garanzie(): HasMany
    {
        return $this->hasMany(Garanzia::class, 'strumento_id')
            ->orderBy('data_scadenza_effettiva')
            ->orderBy('id');
    }

    /**
     * Storico letture contaore (append-only), più recente in alto.
     */
    public function lettureContaore(): HasMany
    {
        return $this->hasMany(LetturaContaore::class, 'strumento_id')
            ->orderByDesc('data')
            ->orderByDesc('id');
    }

    /**
     * Garanzia con la scadenza effettiva più vicina: il secondo ingresso del
     * semaforo (ADR-004/005). Speculare a prossimoInterventoAperto().
     *
     * Nota privacy per S4: il semaforo è un AGGREGATO e dovrà considerare anche
     * le garanzie ricambio, bypassando GaranziaRicambioPrivacyScope — il
     * pallino non rivela la riga. La colonna "Prossima scadenza" dell'elenco è
     * invece un DETTAGLIO e dovrà restare filtrata per permesso.
     */
    public function prossimaGaranzia(): ?Garanzia
    {
        if ($this->relationLoaded('garanzie')) {
            return $this->garanzie
                ->sortBy([['data_scadenza_effettiva', 'asc'], ['id', 'asc']])
                ->first();
        }

        return $this->garanzie()->first(); // la relazione ordina già asc
    }

    /**
     * Prossimo intervento aperto (non_fatto con scadenza minima): l'unico dato
     * che serve al semaforo e alla colonna "Prossima scadenza".
     *
     * Se la relazione è già caricata la riusa (zero query extra); altrimenti
     * una first() servita dall'indice (strumento_id, stato, data_scadenza).
     * `reorder()` è OBBLIGATORIO: la relazione ordina per data_scadenza DESC,
     * quindi senza si otterrebbe la scadenza più LONTANA invece della prossima.
     */
    public function prossimoInterventoAperto(): ?Intervento
    {
        if ($this->relationLoaded('interventi')) {
            return $this->interventi
                ->filter(fn (Intervento $i) => $i->stato === StatoIntervento::NonFatto)
                ->sortBy([['data_scadenza', 'asc'], ['id', 'asc']])
                ->first();
        }

        return $this->interventi()->reorder()
            ->where('stato', StatoIntervento::NonFatto->value)
            ->orderBy('data_scadenza')->orderBy('id')
            ->first();
    }

    /**
     * Stato calcolato (ADR-005): derivato, MAI persistito. Considera sia gli
     * interventi aperti sia le garanzie, entrambi ridotti a una data — le
     * garanzie ci arrivano già normalizzate in `data_scadenza_effettiva`
     * (ADR-004), quindi il motore non sa nulla di ore né di durate.
     */
    public function statoSemaforoCalcolato(): StatoSemaforo
    {
        return Semaforo::calcola(
            $this->prossimoInterventoAperto()?->data_scadenza,
            $this->prossimaGaranzia()?->data_scadenza_effettiva,
        );
    }

    /**
     * Stato mostrato = forzato se presente, altrimenti calcolato (ADR-005).
     * La UI deve chiamare SEMPRE questo metodo, mai statoSemaforoCalcolato():
     * è l'unico punto in cui la forzatura vince, e vale anche per le forme SQL
     * dell'elenco (filtro e ordinamento), che replicano questa stessa regola.
     */
    public function statoSemaforoEffettivo(): StatoSemaforo
    {
        return $this->forced_state ?? $this->statoSemaforoCalcolato();
    }

    /**
     * Utente che ha forzato il semaforo (ADR-005).
     */
    public function forcedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forced_by');
    }

    /**
     * Forza manualmente il semaforo (ADR-005, permesso `semaforo.force`): lo
     * stato forzato vince su quello calcolato finché non viene rimosso. La
     * forzatura non nasconde nulla — la lista interventi resta la fonte di
     * verità e continua a mostrare gli scaduti.
     *
     * Il motivo è OBBLIGATORIO solo per il rosso: dichiarare uno strumento
     * "non idoneo" senza dire perché lascia l'audit log senza la sola
     * informazione che conta. Per verde e arancione resta opzionale (ADR-005:
     * "opzionale ma raccomandato"). La guardia sta qui e non solo nel form:
     * vale per qualunque chiamante futuro (QR S4, scheduler S5).
     *
     * `forced_by` è sempre l'utente autenticato, mai un valore in ingresso.
     */
    public function forzaSemaforo(StatoSemaforo $stato, ?string $motivo = null): bool
    {
        if ($stato === StatoSemaforo::Rosso && blank($motivo)) {
            throw new InvalidArgumentException('Il motivo è obbligatorio quando si forza il rosso (ADR-005).');
        }

        $calcolato = $this->statoSemaforoCalcolato();

        $this->forced_state = $stato;
        $this->forced_by = auth()->id();
        $this->forced_at = now();
        $this->forced_reason = blank($motivo) ? null : $motivo;

        $salvato = $this->save();

        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->performedOn($this)
            ->withProperties([
                'stato_calcolato' => $calcolato->value,
                'forced_state' => $stato->value,
                'motivo' => $this->forced_reason,
            ])
            ->log('Semaforo forzato');

        return $salvato;
    }

    /**
     * Rimuove la forzatura: lo stato mostrato torna a quello calcolato.
     * Loggata come la forzatura — riabilitare uno strumento dichiarato non
     * idoneo è un atto sensibile quanto dichiararlo tale.
     */
    public function rimuoviForzatura(): bool
    {
        $precedente = $this->forced_state;

        $this->forced_state = null;
        $this->forced_by = null;
        $this->forced_at = null;
        $this->forced_reason = null;

        $salvato = $this->save();

        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->performedOn($this)
            ->withProperties([
                'forced_state_rimosso' => $precedente?->value,
                'stato_calcolato' => $this->statoSemaforoCalcolato()->value,
            ])
            ->log('Forzatura semaforo rimossa');

        return $salvato;
    }

    /**
     * Storico spostamenti (append-only), più recente in alto.
     */
    public function spostamenti(): HasMany
    {
        return $this->hasMany(SpostamentoStrumento::class, 'strumento_id')
            ->orderByDesc('data')
            ->orderByDesc('id');
    }
}
