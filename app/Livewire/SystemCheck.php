<?php

namespace App\Livewire;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\App;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Componente TEMPORANEO di verifica del TALL stack (Sprint 1 · punto 3).
 * Prova che Livewire (wire:click) e Alpine (x-data) funzionano con i token
 * del design system. Da rimuovere/sostituire quando arriva la dashboard
 * reale (Sprint 1 · punto 10). Rotta: /_tall-check.
 */
#[Layout('components.layouts.app')]
class SystemCheck extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render()
    {
        return view('livewire.system-check', [
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => App::version(),
            'livewireVersion' => InstalledVersions::getPrettyVersion('livewire/livewire'),
        ]);
    }
}
