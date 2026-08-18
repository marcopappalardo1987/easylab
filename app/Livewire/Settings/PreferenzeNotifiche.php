<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Preferenze di notifica dell'utente autenticato (ADR-011).
 *
 * È il diritto di opposizione del registro dei trattamenti (T4) reso una
 * schermata: finora era un impegno scritto senza un posto dove esercitarlo.
 *
 * Nessun permesso sulla rotta, a differenza di quasi tutte le altre: qui non si
 * governa un dato dell'Ente ma la propria casella di posta, e un permesso
 * significherebbe che l'Admin può decidere chi riceve email — cioè che qualcuno
 * possa opporsi *al posto tuo*, o impedirti di farlo.
 *
 * La colonna sta fuori dall'attributo `Fillable` di `User`: si scrive solo da
 * qui, con `forceFill`. È la postura di `visibilita_garanzie_ricambio`
 * (ADR-029) e serve alla stessa cosa — che la regola non viva solo nella vista.
 */
#[Layout('components.layouts.app')]
class PreferenzeNotifiche extends Component
{
    public bool $riceveEmailScadenze = true;

    public function mount(): void
    {
        $this->riceveEmailScadenze = (bool) $this->user()->riceve_email_scadenze;
    }

    public function salva(): void
    {
        $this->user()->forceFill([
            'riceve_email_scadenze' => $this->riceveEmailScadenze,
        ])->save();

        $this->dispatch('preferenze-salvate');
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render()
    {
        return view('livewire.settings.preferenze-notifiche');
    }
}
