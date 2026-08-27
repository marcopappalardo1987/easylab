<?php

namespace App\Livewire\Interventi;

use Livewire\Component;

/**
 * Scadenzario aggregato degli interventi futuri (Wireframe §5, ADR-005).
 *
 * ⚠️ SEGNAPOSTO — vedi la nota su `ElencoDocumenti`: rotta e menù sono tenuti
 * dall'orchestratore, il corpo arriva col blocco «Calendario/scadenzario».
 */
class Scadenzario extends Component
{
    public function render()
    {
        return view('livewire.interventi.scadenzario');
    }
}
