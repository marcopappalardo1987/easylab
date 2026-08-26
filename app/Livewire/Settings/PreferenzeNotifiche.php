<?php

namespace App\Livewire\Settings;

use App\Enums\TemaUtente;
use App\Livewire\Settings\Concerns\ScegliTema;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Le preferenze dell'utente autenticato: notifiche (ADR-011) e tema (ADR-034).
 *
 * È il diritto di opposizione del registro dei trattamenti (T4) reso una
 * schermata: finora era un impegno scritto senza un posto dove esercitarlo.
 *
 * Nessun permesso sulla rotta, a differenza di quasi tutte le altre: qui non si
 * governa un dato dell'Ente ma la propria casella di posta — e il proprio
 * schermo — e un permesso significherebbe che l'Admin può decidere chi riceve
 * email, cioè che qualcuno possa opporsi *al posto tuo* o impedirti di farlo.
 *
 * ⚠️ **La pagina si intitola «Preferenze», ma l'indirizzo resta
 * `/settings/notifiche` e la rotta si chiama ancora `settings.notifiche`.** Non
 * è una svista: il nome della rotta è citato dal menù utente, dal piè di pagina
 * del digest email (`mail/digest-scadenze.blade.php`), da un docblock del
 * comando `easylab:notifica-scadenze` e da due test. Rinominarli è un
 * refactoring che tocca cinque file per cambiare una stringa che l'utente non
 * legge — e un URL già inviato per email smetterebbe di rispondere. Il
 * restyling cambia il **titolo e l'intestazione**, non l'indirizzo. Per la
 * stessa ragione questa classe conserva il proprio nome.
 *
 * **Le due colonne stanno fuori dall'attributo `Fillable` di `User`**, e si
 * scrivono con `forceFill`: `riceve_email_scadenze` da `salva()` qui sotto,
 * `tema` dal trait `ScegliTema` — che dal 26 Ago 2026 questa classe **condivide**
 * con la scorciatoia in top bar invece di ospitarne una copia. È la postura di
 * `visibilita_garanzie_ricambio` (ADR-029) e di `tenant_id` (ADR-032): la
 * preferenza di una persona non deve poter arrivare dal form di un'altra.
 */
#[Title('Preferenze — Easy Lab')]
#[Layout('components.layouts.app')]
class PreferenzeNotifiche extends Component
{
    // ⚠️ **`scegliTema()` arriva da qui e non è più scritto in questa classe.**
    // Dal 26 Ago 2026 i consumatori sono due — questa pagina e la scorciatoia in
    // top bar (`SelettoreTema`) — e due copie della stessa guardia divergono: il
    // giorno in cui una delle due dimenticasse `tryFrom` non ci sarebbe nessun
    // sintomo, solo un endpoint in più che scrive nella colonna ciò che il
    // client gli manda. Il perché di ogni riga sta nel docblock del trait.
    use ScegliTema;

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
        return view('livewire.settings.preferenze-notifiche', [
            // ⚠️ **Dal database a ogni render, e non da una proprietà
            // pubblica.** Una `public string $tema` sarebbe scrivibile dal
            // client: `aria-pressed` — cioè l'unica cosa che dice a uno screen
            // reader quale tema è attivo — riporterebbe ciò che il browser ha
            // mandato, non ciò che il server sa. È la stessa regola del
            // combobox letta sull'accessibilità: lo stato del client non
            // aggiunge mai nulla a ciò che il server ha deciso di mostrare.
            'temaCorrente' => TemaUtente::oSistema($this->user()->tema),
        ]);
    }
}
