<?php

namespace App\Livewire\Piattaforma;

use App\Support\Tenancy\VistaPiattaforma;
use Livewire\Component;

/**
 * Il parco di TUTTI i clienti, in sola lettura (ADR-037, in arrivo).
 *
 * ⚠️ SEGNAPOSTO tenuto dall'orchestratore: rotta e sub-nav sono file contesi.
 * Il corpo arriva col blocco «Parco globale».
 *
 * 🔴 Il permesso è `tenants.view_all`, lo stesso della cabina: significa
 * letteralmente «vedi oltre il tuo Ente», è già del solo Developer/Superadmin
 * ed è nel set bloccato (ADR-016). Nessun permesso nuovo, nessun riseeding.
 */
class ParcoGlobale extends Component
{
    public const PERMESSO = VistaPiattaforma::PERMESSO;

    public function render()
    {
        return view('livewire.piattaforma.parco-globale');
    }
}
