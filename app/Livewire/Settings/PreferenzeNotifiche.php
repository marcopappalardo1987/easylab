<?php

namespace App\Livewire\Settings;

use App\Enums\TemaUtente;
use App\Models\User;
use Illuminate\Validation\ValidationException;
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
 * scrivono solo da qui con `forceFill`. È la postura di
 * `visibilita_garanzie_ricambio` (ADR-029) e di `tenant_id` (ADR-032): la
 * preferenza di una persona non deve poter arrivare dal form di un'altra.
 */
#[Title('Preferenze — Easy Lab')]
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

    /**
     * Sceglie il tema (🔗 ADR-034 — DS §8.3), e lo scrive subito.
     *
     * **Nessun «Salva» qui, a differenza del digest**, ed è deliberato: Alpine
     * ha già cambiato il colore della pagina nel millisecondo del click. Se il
     * database restasse indietro fino a un bottone, il primo ricaricamento
     * riporterebbe il tema di prima — cioè l'interruttore sembrerebbe aver
     * dimenticato, che è peggio del non averlo.
     *
     * ⚠️ **Il valore si converte con `tryFrom` prima di toccare la colonna.**
     * Il parametro arriva dal client e un componente Livewire è un endpoint a
     * tutti gli effetti: `from()` lancerebbe un `ValueError` (500), un cast
     * cieco scriverebbe. La colonna ha un `CHECK` a database che rifiuterebbe
     * comunque, ma un vincolo che scatta è un errore di sistema, non una
     * risposta — e su SQLite e Postgres non ha nemmeno lo stesso testo.
     *
     * ⛔ **L'identità viene da `auth()`, mai da un parametro.** Non esiste una
     * firma in cui si possa nominare *un altro utente*: è il solo modo per cui
     * «cambiare il tema di qualcun altro» non è una richiesta esprimibile,
     * invece che una richiesta respinta da un controllo che si può dimenticare.
     */
    public function scegliTema(string $tema): void
    {
        $scelto = TemaUtente::tryFrom($tema);

        if ($scelto === null) {
            throw ValidationException::withMessages([
                'tema' => 'Tema non riconosciuto.',
            ]);
        }

        $this->user()->forceFill(['tema' => $scelto])->save();
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
