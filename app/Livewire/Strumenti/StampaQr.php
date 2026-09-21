<?php

namespace App\Livewire\Strumenti;

use App\Livewire\Concerns\RiancoraStrumentoAlloScope;
use App\Models\Strumento;
use App\Support\QrStrumento;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Foglio da stampare e applicare sulla macchina (🔗 ADR-003).
 *
 * Il route-model binding è scopato come ovunque: uno strumento di un altro Ente
 * dà 404 prima di arrivare qui.
 *
 * **Ristampare non rigenera il token** (decisione del 15 Ago 2026): un adesivo
 * rovinato o perso si rifà identico, e chi stampa una copia di scorta non
 * invalida quella già incollata. Invalidare è un atto separato ed esplicito —
 * `rigeneraQrToken()` — con la propria traccia di audit e la propria conferma,
 * perché la conseguenza (le etichette in giro smettono di funzionare) non si
 * può dedurre dal gesto.
 */
#[Layout('components.layouts.app')]
class StampaQr extends Component
{
    use RiancoraStrumentoAlloScope;

    public Strumento $strumento;

    public bool $showRigeneraForm = false;

    public function mount(Strumento $strumento): void
    {
        $this->strumento = $strumento;
    }

    public function openRigenera(): void
    {
        $this->authorize('strumenti.qr_generate');
        $this->showRigeneraForm = true;
    }

    public function closeRigenera(): void
    {
        $this->showRigeneraForm = false;
    }

    public function rigenera(): void
    {
        $this->authorize('strumenti.qr_generate');

        $this->strumento->rigeneraQrToken();
        $this->showRigeneraForm = false;
    }

    public function render()
    {
        // Ricostruito a ogni render e non memorizzato: dopo una rigenerazione
        // il foglio deve mostrare il QR NUOVO, o si stamperebbe un'etichetta
        // che è già stata invalidata dal gesto precedente.
        return view('livewire.strumenti.stampa-qr', [
            'svg' => QrStrumento::svg($this->strumento),
            'url' => QrStrumento::url($this->strumento),
            'percorso' => $this->percorso(),
            'puoRigenerare' => Gate::allows('strumenti.qr_generate'),
        ]);
    }

    /**
     * Ente › Dipartimento › Sotto-laboratorio: sull'etichetta serve a ritrovare
     * la macchina, ed è il posto in cui un'ubicazione mancante fa più danno —
     * il foglio finisce sulla macchina e nessuno lo rilegge. La risalita vive
     * ora in `Strumento::percorsoUbicazione()`: era scritta due volte, identica.
     */
    private function percorso(): string
    {
        return $this->strumento->percorsoUbicazione();
    }
}
