<?php

namespace Database\Factories;

use App\Enums\SoggettoGaranzia;
use App\Models\Garanzia;
use App\Models\RicambioUtilizzo;
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
     * Default: garanzia macchina ancora attiva. Le FK restano null: usare
     * sempre ->forStrumento($strumento), che propaga anche il tenant.
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
            'data_inizio' => today()->subMonths(6)->toDateString(),
            'durata_mesi' => 24,
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

    /**
     * Garanzia del singolo pezzo montato (ERD §6.1 — ADR-004/022). Prende una
     * riga `ricambio_utilizzo` VERA e non un id libero: dall'8 Ago 2026 la FK
     * esiste, e un intero inventato viola il vincolo su entrambi i driver.
     */
    public function forRicambio(RicambioUtilizzo $utilizzo): static
    {
        return $this->state(fn () => [
            'tenant_id' => $utilizzo->tenant_id,
            'soggetto' => SoggettoGaranzia::Ricambio,
            'strumento_id' => null,
            'ricambio_utilizzo_id' => $utilizzo->id,
        ]);
    }

    /** Già finita: pesa sul semaforo come uno scaduto-non-fatto. */
    public function scaduta(): static
    {
        return $this->state(fn () => [
            'data_inizio' => today()->subMonths(30)->toDateString(),
            'durata_mesi' => 12,
        ]);
    }

    /** In scadenza entro la soglia "imminente" → arancione. */
    public function imminente(): static
    {
        return $this->state(fn () => [
            'data_inizio' => today()->subMonths(12)->addDays(10)->toDateString(),
            'durata_mesi' => 12,
        ]);
    }

    /** Oltre la soglia → non pesa sul semaforo. */
    public function attiva(): static
    {
        return $this->state(fn () => [
            'data_inizio' => today()->subDays(Semaforo::giorniImminente())->toDateString(),
            'durata_mesi' => 24,
        ]);
    }
}
