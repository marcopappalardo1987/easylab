<?php

namespace App\Livewire\Documenti;

use Livewire\Component;

/**
 * Archivio documentale d'Ente — elenco cross-macchina (ADR-026, ADR-031).
 *
 * ⚠️ SEGNAPOSTO. La rotta e la voce di menù esistono già perché `routes/web.php`
 * e la shell sono file **contesi da sei lavorazioni in parallelo**: sono tenuti
 * da una mano sola (l'orchestratore) e i costruttori non li toccano. Il corpo
 * di questa classe arriva col blocco «Area documentale d'Ente».
 */
class ElencoDocumenti extends Component
{
    public function render()
    {
        return view('livewire.documenti.elenco-documenti');
    }
}
