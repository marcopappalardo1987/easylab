<?php

namespace App\Livewire\Piattaforma;

use App\Support\Tenancy\VistaPiattaforma;
use Livewire\Component;

/**
 * Parco clienti — scheda «Ricambi», in SOLA LETTURA (ADR-037).
 *
 * ⚠️ SEGNAPOSTO tenuto dall'orchestratore. Il corpo arriva col suo blocco.
 *
 * 🔴 Tre componenti e non tre schede dentro uno solo: così i tre blocchi si
 * costruiscono su file disgiunti, e nessuno riscrive il lavoro dell'altro. La
 * fila di schede è un partial condiviso, che tiene l'orchestratore.
 */
class ParcoRicambi extends Component
{
    public const PERMESSO = VistaPiattaforma::PERMESSO;

    public function render()
    {
        return view('livewire.piattaforma.parco-ricambi');
    }
}
