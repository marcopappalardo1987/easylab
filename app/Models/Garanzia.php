<?php

namespace App\Models;

use App\Enums\SoggettoGaranzia;
use App\Enums\TipoScadenzaGaranzia;
use App\Models\Concerns\BelongsToOrgNodeThroughStrumento;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\GaranziaRicambioPrivacyScope;
use App\Support\Semaforo;
use Database\Factories\GaranziaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * Garanzia del macchinario o del singolo ricambio montato (ERD §6.1 — ADR-004).
 *
 * Il motore è "sdoppiato" nel soggetto ma UNICO nel calcolo: qualunque sia il
 * tipo di scadenza, tutto si normalizza in `data_scadenza_effettiva`, e da lì
 * in poi semaforo e notifiche ragionano solo su date. Le ore non arrivano mai
 * al motore: servono a stimare quella data.
 *
 * Debiti dichiarati, entrambi da sciogliere in S4:
 * - `ricambio_utilizzo_id` non ha vincolo FK finché la tabella non esiste.
 * - Il livello 2 dello scope (sotto-albero del Responsabile) passa da
 *   `strumento_id`, che sulle righe `ricambio` è NULL: quelle righe restano
 *   quindi invisibili al Responsabile (fail-closed, accettabile finché non
 *   esistono). Servirà il doppio salto garanzie → ricambio_utilizzo → strumenti.
 * - ADR-015: al trasferimento cross-tenant le garanzie devono seguire lo
 *   strumento con un update by-query (BelongsToTenant blocca il cambio sul model).
 */
class Garanzia extends Model
{
    /** @use HasFactory<GaranziaFactory> */
    use BelongsToOrgNodeThroughStrumento, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'garanzie';

    /**
     * `data_scadenza_effettiva` è deliberatamente ESCLUSA: è un valore derivato,
     * ricalcolato a ogni salvataggio dall'hook `saving`. Fuori da qui, nessun
     * payload (form, import, API future) può falsificare il campo che pilota
     * il semaforo. Stesso principio delle colonne `forced_*` di Strumento.
     */
    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'soggetto',
        'strumento_id',
        'ricambio_utilizzo_id',
        'tipo_scadenza',
        'data_inizio',
        'durata_mesi',
        'soglia_ore',
        'data_scadenza_prevista',
    ];

    protected function casts(): array
    {
        return [
            'soggetto' => SoggettoGaranzia::class,
            'tipo_scadenza' => TipoScadenzaGaranzia::class,
            'data_inizio' => 'date',
            'data_scadenza_prevista' => 'date',
            'data_scadenza_effettiva' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new GaranziaRicambioPrivacyScope);

        static::saving(function (Garanzia $garanzia): void {
            $garanzia->verificaSoggetto();
            $garanzia->normalizzaScadenza();
        });
    }

    /**
     * Invariante ERD §6.1: esattamente una fra `strumento_id` e
     * `ricambio_utilizzo_id`, coerente col soggetto. Una garanzia appesa a
     * nulla (o a entrambi) renderebbe ambiguo a chi si riferisce.
     */
    protected function verificaSoggetto(): void
    {
        $coerente = match ($this->soggetto) {
            SoggettoGaranzia::Macchina => $this->strumento_id !== null && $this->ricambio_utilizzo_id === null,
            SoggettoGaranzia::Ricambio => $this->ricambio_utilizzo_id !== null && $this->strumento_id === null,
        };

        if (! $coerente) {
            throw new InvalidArgumentException(
                'Garanzia: va valorizzato esattamente uno fra strumento_id e ricambio_utilizzo_id, coerente col soggetto (ERD §6.1).'
            );
        }
    }

    /**
     * Normalizzazione ADR-004 — l'unico punto in cui si scrive
     * `data_scadenza_effettiva`, ricalcolata a OGNI salvataggio:
     *
     *   tipo `data` → data_inizio + durata_mesi
     *   tipo `ore`  → la data prevista inserita a mano (V1; l'estrapolazione
     *                 dalle letture contaore è V1.1)
     *
     * Si assegna un Carbon e non una stringa: su SQLite le colonne `date`
     * diventano 'Y-m-d 00:00:00', lo stesso formato di `interventi.data_scadenza`,
     * e il confronto fra le due colonne nell'ordinamento dell'elenco resta valido.
     */
    protected function normalizzaScadenza(): void
    {
        $this->data_scadenza_effettiva = match ($this->tipo_scadenza) {
            TipoScadenzaGaranzia::Data => $this->data_inizio?->copy()->addMonths(
                $this->durata_mesi ?? throw new InvalidArgumentException('Garanzia a data: `durata_mesi` è obbligatoria (ADR-004).')
            ) ?? throw new InvalidArgumentException('Garanzia: `data_inizio` è obbligatoria.'),

            TipoScadenzaGaranzia::Ore => $this->data_scadenza_prevista
                ?? throw new InvalidArgumentException('Garanzia a ore: `data_scadenza_prevista` è obbligatoria in V1 (ADR-004).'),
        };
    }

    /**
     * Garanzie che pesano sul semaforo: scadute o in scadenza entro la soglia
     * (ADR-005). Gemello SQL del confronto fatto da Semaforo::calcola.
     *
     * Nessun limite inferiore, di proposito: una garanzia scaduta è una fine
     * garanzia avvenuta, e resta rilevante come un intervento scaduto-non-fatto.
     * Non è un'attività che si "fa": si risolve rinnovandola o forzando il
     * verde con motivazione (ADR-005). Un limite inferiore inventerebbe una
     * seconda soglia che gli ADR non prevedono.
     *
     * Confine espresso come `< oggi+soglia+1` e MAI `<=`: vedi
     * Intervento::scopeApertiEntroSoglia (su SQLite le date sono stringhe).
     *
     * @param  Builder<Garanzia>  $query
     */
    public function scopeEntroSoglia(Builder $query): void
    {
        $query->where('data_scadenza_effettiva', '<', today()->addDays(Semaforo::giorniImminente() + 1)->toDateString());
    }

    /** True se la garanzia è già finita. */
    public function isScaduta(): bool
    {
        return $this->data_scadenza_effettiva->lt(today());
    }

    public function strumento(): BelongsTo
    {
        return $this->belongsTo(Strumento::class, 'strumento_id');
    }
}
