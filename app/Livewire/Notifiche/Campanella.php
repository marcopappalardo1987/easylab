<?php

namespace App\Livewire\Notifiche;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Component;

/**
 * La campanella delle notifiche in-app (ADR-011, ERD §9).
 *
 * Primo componente Livewire **annidato** del progetto: tutti gli altri sono
 * pagine intere. Vive nella top bar, quindi si monta a ogni richiesta di ogni
 * pagina — per questo `mount()` fa una `count()` e nient'altro, e l'elenco si
 * carica solo quando qualcuno apre il pannello.
 *
 * **Niente `wire:poll`**, che sarebbe la scelta automatica per una campanella.
 * Qui la posta arriva una volta al giorno dallo scheduler: interrogare il server
 * ogni minuto significherebbe circa millequattrocento richieste al giorno per
 * utente per un evento quotidiano. Il conteggio si aggiorna al cambio pagina, e
 * per un digest mattutino è abbastanza. Quando arriveranno notifiche più
 * frequenti (S6) il polling sarà una riga.
 *
 * Nessun permesso: le righe sono le proprie notifiche, e un permesso qui
 * proteggerebbe una persona dai propri dati. L'autorizzazione è già avvenuta a
 * monte, quando lo scheduler ha scelto i destinatari.
 */
class Campanella extends Component
{
    public bool $aperta = false;

    /** Numero di notifiche non lette; unico costo del mount su ogni pagina. */
    public int $nonLette = 0;

    public function mount(): void
    {
        $this->nonLette = auth()->user()->unreadNotifications()->count();
    }

    public function apri(): void
    {
        $this->aperta = true;
    }

    /** Il pannello è reso da un `@if`: aprire e chiudere passano dal server. */
    public function alterna(): void
    {
        $this->aperta = ! $this->aperta;
    }

    public function chiudi(): void
    {
        $this->aperta = false;
    }

    public function segnaTutteLette(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        $this->nonLette = 0;
    }

    /**
     * Le ultime notifiche, caricate solo a pannello aperto.
     *
     * Dieci: il digest è quotidiano, quindi sono due settimane di posta — oltre
     * si entra nel territorio dell'archivio, che è una pagina e non un menù a
     * tendina.
     *
     * @return Collection<int, DatabaseNotification>
     */
    public function ultime(): Collection
    {
        // Torna pigro: il pannello è reso da un `@if ($aperta)`, quindi il giro
        // sul server c'è comunque nel gesto di aprire. Il tentativo del 28 Ago
        // di caricarle su ogni pagina è stato annullato — era un costo pagato
        // per una cura che non curava.
        if (! $this->aperta) {
            return new Collection;
        }

        return auth()->user()->notifications()->take(10)->get();
    }

    public function render()
    {
        return view('livewire.notifiche.campanella', [
            'notifiche' => $this->ultime(),
        ]);
    }
}
