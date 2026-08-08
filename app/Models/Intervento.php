<?php

namespace App\Models;

use App\Enums\StatoIntervento;
use App\Enums\TipoIntervento;
use App\Models\Concerns\BelongsToOrgNodeThroughStrumento;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Semaforo;
use Carbon\CarbonInterface;
use Database\Factories\InterventoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Attività/intervento su uno strumento (ERD §5.2 — ADR-005/007/009): la fonte
 * di verità del semaforo. Una taratura è un intervento `tipo = taratura`
 * (ADR-009). Visibile anche al ruolo Tenant.
 *
 * Non ha collocazione propria nell'albero: eredita la restrizione di reparto
 * dallo strumento (BelongsToOrgNodeThroughStrumento). NON usare BelongsToOrgNode
 * — filtrerebbe su `unita_organizzativa_id`, colonna qui inesistente.
 *
 * Invariante: `stato = fatto` ⇔ `data_esecuzione` valorizzata. Imposta
 * nell'hook `saving`, quindi usare segnaFatto()/riapri() e MAI un update
 * by-query (`Intervento::where(...)->update(...)` non emette eventi e
 * aggirerebbe l'invariante).
 *
 * Debito noto (ADR-015): al trasferimento cross-tenant di uno strumento gli
 * interventi devono seguirlo. BelongsToTenant blocca il cambio di `tenant_id`
 * sul model, quindi servirà un update by-query esplicito (che non passa per gli
 * eventi). L'invariante `interventi.tenant_id == strumenti.tenant_id` regge la
 * sicurezza di DepartmentThroughStrumentoScope: va preservata.
 */
class Intervento extends Model
{
    /** @use HasFactory<InterventoFactory> */
    use BelongsToOrgNodeThroughStrumento, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'interventi';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'strumento_id',
        'tecnico_id',
        'descrizione',
        'tipo',
        'data_scadenza',
        'stato',
        'data_esecuzione',
    ];

    protected $attributes = [
        'stato' => StatoIntervento::NonFatto->value,
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoIntervento::class,
            'stato' => StatoIntervento::class,
            'data_scadenza' => 'date',
            'data_esecuzione' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Intervento $intervento): void {
            if ($intervento->stato === StatoIntervento::Fatto) {
                $intervento->data_esecuzione ??= today();

                return;
            }

            $intervento->data_esecuzione = null;
        });
    }

    /**
     * Spunta "Fatto" (permesso `interventi.complete`). Senza data esplicita
     * l'esecuzione è oggi.
     */
    public function segnaFatto(?CarbonInterface $data = null): bool
    {
        $this->stato = StatoIntervento::Fatto;
        $this->data_esecuzione = $data;

        return $this->save();
    }

    /**
     * Torna a "non fatto": l'hook `saving` azzera la data di esecuzione.
     */
    public function riapri(): bool
    {
        $this->stato = StatoIntervento::NonFatto;

        return $this->save();
    }

    /**
     * Scaduto-non-fatto (ADR-005): unica definizione di "in ritardo", che il
     * motore semaforo (punto 4) riusa invece di ridefinirla. Un intervento in
     * scadenza OGGI non è ancora scaduto.
     *
     * NON usare `data_scadenza->isPast()`: il cast `date` produce un Carbon a
     * mezzanotte, quindi una scadenza di oggi risulterebbe passata alle 00:01.
     * `scadute()` è il gemello SQL: un test ne verifica l'allineamento.
     */
    public function isScaduto(): bool
    {
        return $this->stato === StatoIntervento::NonFatto
            && $this->data_scadenza->lt(today());
    }

    /**
     * Gemello SQL di isScaduto(), per filtrare tra più strumenti (semaforo,
     * scadenzario, scheduler S5). Confronto diretto sulla colonna `date`: un
     * `whereDate()` applicherebbe DATE() e butterebbe via l'indice
     * (strumento_id, stato, data_scadenza).
     *
     * @param  Builder<Intervento>  $query
     */
    public function scopeScadute(Builder $query): void
    {
        $query->where('stato', StatoIntervento::NonFatto->value)
            ->where('data_scadenza', '<', today()->toDateString());
    }

    /**
     * Interventi aperti la cui scadenza è già passata O cade entro la soglia
     * "imminente": esattamente le due condizioni che accendono l'arancione
     * (ADR-005). È la forma SQL della regola di App\Support\Semaforo::calcola(),
     * e serve a filtrare TRA strumenti (elenco, dashboard S6) dove il calcolo
     * per-model non è utilizzabile senza rompere la paginazione.
     *
     * La soglia arriva da Semaforo, non è riscritta qui: un test verifica che
     * questo scope e il calcolo per-model restino d'accordo.
     *
     * Il confine è INCLUSIVO (oggi+soglia è imminente) ma si esprime come
     * `< oggi+soglia+1`, MAI come `<= oggi+soglia`: su SQLite le date sono
     * stringhe 'Y-m-d H:i:s' e `'2026-08-31 00:00:00' <= '2026-08-31'` è falso
     * (confronto lessicografico), mentre su Postgres è vero. Con `<` sul giorno
     * successivo i due driver danno lo stesso risultato.
     *
     * @param  Builder<Intervento>  $query
     */
    public function scopeApertiEntroSoglia(Builder $query): void
    {
        $query->where('stato', StatoIntervento::NonFatto->value)
            ->where('data_scadenza', '<', today()->addDays(Semaforo::giorniImminente() + 1)->toDateString());
    }

    /**
     * Nome dell'assegnatario per la UI, mai quello di un utente di un altro
     * Ente: `tecnico_id` è una FK su `users`, che NON ha il TenantScope. I
     * Tecnici esterni (`tenant_id` null, ADR-007) restano legittimamente
     * visibili. La validazione di `tecnico_id` in scrittura arriva col punto 3.
     */
    public function tecnicoLabel(): string
    {
        $tecnico = $this->tecnico;

        if ($tecnico === null) {
            return '—';
        }

        return $tecnico->tenant_id === null || $tecnico->tenant_id === $this->tenant_id
            ? $tecnico->name
            : '—';
    }

    public function strumento(): BelongsTo
    {
        return $this->belongsTo(Strumento::class, 'strumento_id');
    }

    /**
     * Assegnatario dell'intervento (ADR-007).
     */
    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id');
    }

    /**
     * Pezzi montati durante questo intervento (ERD §7.2 — ADR-022), il più
     * recente in alto.
     *
     * È anche l'idioma con cui si risolve una riga da rimuovere:
     * `$intervento->ricambiUtilizzi()->findOrFail($id)` riapplica TenantScope e
     * il livello 2 e vincola `intervento_id`, quindi copre in un colpo altro
     * tenant, fuori sotto-albero e id appartenente a un altro intervento.
     */
    public function ricambiUtilizzi(): HasMany
    {
        return $this->hasMany(RicambioUtilizzo::class, 'intervento_id')
            ->orderByDesc('data')
            ->orderByDesc('id');
    }
}
