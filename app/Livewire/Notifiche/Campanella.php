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
        // 🔴 **Si calcolano SEMPRE, e la ragione è la stessa dello switcher
        // delle sedi (28 Ago 2026).** La versione pigra chiedeva le righe al
        // server nel gesto stesso di aprire: il server rispondeva, Livewire
        // ridisegnava il frammento e il pannello si richiudeva nell'istante in
        // cui arrivavano i dati per riempirlo. Non dava nessun errore.
        //
        // ⚠️ Qui il costo è più visibile che sulle sedi — è **una query da
        // dieci righe su ogni pagina**, per ogni utente — e va detto invece che
        // nascosto. Si paga perché l'alternativa era una tendina che non si
        // apre, e perché il conteggio dei non letti già interroga questa stessa
        // tabella a ogni pagina: il salto è da una query a due, non da zero.
        //
        // Se un domani pesasse, la strada NON è tornare al caricamento pigro:
        // è marcare l'apertura in modo che non ridisegni questo frammento.
        return auth()->user()->notifications()->take(10)->get();
    }

    public function render()
    {
        return view('livewire.notifiche.campanella', [
            'notifiche' => $this->ultime(),
        ]);
    }
}
