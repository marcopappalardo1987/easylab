<?php

namespace App\Livewire\Piattaforma;

use Livewire\Component;

/**
 * Il listino dei piani — ADR-035 (27 Ago 2026).
 *
 * ⚠️ SEGNAPOSTO tenuto dall'orchestratore: rotta e sub-nav sono file contesi da
 * più lavorazioni in parallelo. Il corpo arriva col blocco «Piani dalla
 * dashboard».
 */
class Listino extends Component
{
    /**
     * Non una stringa ribattuta nelle viste e nelle rotte: creare un piano è
     * **fissare un prezzo**, e questo permesso è già del solo Developer e
     * Superadmin ed è nel set bloccato (ADR-016) — l'editor di runtime non può
     * regalarlo a un ruolo cliente.
     */
    public const PERMESSO = 'billing.manage_global';

    public function render()
    {
        return view('livewire.piattaforma.listino');
    }
}
