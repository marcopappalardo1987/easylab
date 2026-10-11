<?php

namespace App\Livewire\Concerns;

use App\Models\UnitaOrganizzativa;
use App\Support\Tenancy\SediSeguite;
use App\Support\Testo\Ricerca;
use Illuminate\Support\Collection;

/**
 * La tendina in cui si sceglie dove si lavora, filtrabile scrivendo
 * (🔗 ADR-061; ADR-046 per le sedi seguite). La vista è
 * `livewire.tenancy._selettore-sede`.
 *
 * Chi usa il trait tiene `$sedeId` e dà `sedi()`: l'elenco fra cui si può
 * scegliere, già limitato a ciò che la persona segue. Il filtro lo restringe,
 * e la scelta si rilegge da lì: l'id arriva dal browser.
 *
 * 🔴 «Aperto» lo dice il server e non Alpine (`TendineLivewireGuardrailTest`),
 * come per il selettore del fornitore (ADR-051).
 */
trait SceglieSede
{
    public bool $selettoreSede = false;

    public string $cercaSede = '';

    /** @return Collection<int, UnitaOrganizzativa> */
    abstract private function sedi(): Collection;

    public function apriSedi(): void
    {
        abort_if($this->sedi()->isEmpty(), 404);

        $this->selettoreSede = true;
    }

    public function chiudiSedi(): void
    {
        $this->reset(['selettoreSede', 'cercaSede']);
    }

    public function scegliSede(int $id): void
    {
        // 404 e non 403: un «non ti è permesso» confermerebbe che la sede esiste.
        abort_unless($this->sedi()->contains('id', $id), 404);

        $this->sedeId = $id;
        $this->resetValidation('sedeId');
        $this->chiudiSedi();
    }

    /**
     * Ciò che serve alla vista: la sede scelta e, solo a tendina aperta, le
     * righe che il testo lascia passare.
     *
     * @return array{scelta: ?UnitaOrganizzativa, righe: Collection<int, UnitaOrganizzativa>, inTutto: int}
     */
    protected function selettoreSedi(): array
    {
        $sedi = $this->sedi();

        return [
            'scelta' => blank($this->sedeId) ? null : $sedi->firstWhere('id', (int) $this->sedeId),
            'righe' => $this->selettoreSede
                ? $sedi->filter(fn (UnitaOrganizzativa $s) => Ricerca::contiene(SediSeguite::etichetta($s), $this->cercaSede))->values()
                : collect(),
            'inTutto' => $sedi->count(),
        ];
    }
}
