<?php

namespace App\Livewire\Anagrafica;

use Livewire\Component;

/**
 * Il marchio dell'Ente nelle email (ADR-011).
 *
 * ⚠️ SEGNAPOSTO tenuto dall'orchestratore — vedi `Listino`.
 */
class MarchioEnte extends Component
{
    public function render()
    {
        return view('livewire.anagrafica.marchio-ente');
    }
}
