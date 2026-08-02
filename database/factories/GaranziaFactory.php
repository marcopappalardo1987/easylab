<?php

namespace Database\Factories;

use App\Enums\SoggettoGaranzia;
use App\Enums\TipoScadenzaGaranzia;
use App\Models\Garanzia;
use App\Models\Strumento;
use App\Support\Semaforo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Garanzia>
 */
class GaranziaFactory extends Factory
{
    protected $model = Garanzia::class;

    /**
     * Default: garanzia macchina a data, ancora attiva. Le FK restano null:
     * usare sempre ->forStrumento($strumento), che propaga anche il tenant.
     * `data_scadenza_effettiva` non si imposta mai: la calcola il model.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'reseller_id' => null,
            'soggetto' => SoggettoGaranzia::Macchina,
            'strumento_id' => null,
            'ricambio_utilizzo_id' => null,
            'tipo_scadenza' => TipoScadenzaGaranzia::Data,
            'data_inizio' => today()->subMonths(6)->toDateString(),
            'durata_mesi' => 24,
            'soglia_ore' => null,
            'data_scadenza_prevista' => null,
        ];
    }

    public function forStrumento(Strumento $strumento): static
    {
        return $this->state(fn () => [
            'tenant_id' => $strumento->tenant_id,
            'soggetto' => SoggettoGaranzia::Macchina,
            'strumento_id' => $strumento->id,
            'ricambio_utilizzo_id' => null,
        ]);
    }

    /** Garanzia a ore: in V1 la data prevista è inserita a mano (ADR-004). */
    public function aOre(?string $prevista = null, int $soglia = 10000): static
    {
        return $this->state(fn () => [
            'tipo_scadenza' => TipoScadenzaGaranzia::Ore,
            'durata_mesi' => null,
            'soglia_ore' => $soglia,
            'data_scadenza_prevista' => $prevista ?? today()->addYear()->toDateString(),
        ]);
    }

    /** Già finita: pesa sul semaforo come uno scaduto-non-fatto. */
    public function scaduta(): static
    {
        return $this->state(fn () => [
            'tipo_scadenza' => TipoScadenzaGaranzia::Data,
            'data_inizio' => today()->subMonths(30)->toDateString(),
            'durata_mesi' => 12,
            'soglia_ore' => null,
            'data_scadenza_prevista' => null,
        ]);
    }

    /** In scadenza entro la soglia "imminente" → arancione. */
    public function imminente(): static
    {
        return $this->state(fn () => [
            'tipo_scadenza' => TipoScadenzaGaranzia::Data,
            'data_inizio' => today()->subMonths(12)->addDays(10)->toDateString(),
            'durata_mesi' => 12,
            'soglia_ore' => null,
            'data_scadenza_prevista' => null,
        ]);
    }

    /** Oltre la soglia → non pesa sul semaforo. */
    public function attiva(): static
    {
        return $this->state(fn () => [
            'tipo_scadenza' => TipoScadenzaGaranzia::Data,
            'data_inizio' => today()->subDays(Semaforo::giorniImminente())->toDateString(),
            'durata_mesi' => 24,
            'soglia_ore' => null,
            'data_scadenza_prevista' => null,
        ]);
    }
}
