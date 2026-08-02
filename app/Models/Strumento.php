<?php

namespace App\Models;

use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Models\Concerns\BelongsToOrgNode;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Semaforo;
use Database\Factories\StrumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
     * Stato calcolato (ADR-005): derivato, MAI persistito. Le garanzie
     * (secondo input di Semaforo::calcola) arrivano col punto 7.
     */
    public function statoSemaforoCalcolato(): StatoSemaforo
    {
        return Semaforo::calcola($this->prossimoInterventoAperto()?->data_scadenza);
    }

    /**
     * Stato mostrato = forzato se presente, altrimenti calcolato (ADR-005).
     * Oggi ≡ calcolato: le colonne forced_* arrivano col punto 5. La UI deve
     * chiamare SEMPRE questo metodo, mai statoSemaforoCalcolato().
     */
    public function statoSemaforoEffettivo(): StatoSemaforo
    {
        return $this->statoSemaforoCalcolato();
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
