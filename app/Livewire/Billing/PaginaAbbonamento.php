<?php

namespace App\Livewire\Billing;

use Livewire\Component;

/**
 * L'abbonamento visto dal cliente (ADR-032: l'intestatario è l'Account).
 *
 * ⚠️ SEGNAPOSTO tenuto dall'orchestratore — vedi `Listino`.
 */
class PaginaAbbonamento extends Component
{
    public function render()
    {
        return view('livewire.billing.pagina-abbonamento');
    }
}
