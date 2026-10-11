<?php

namespace App\Livewire\Settings;

use App\Enums\StatoSemaforo;
use App\Enums\TemaUtente;
use App\Livewire\Settings\Concerns\ScegliTema;
use App\Models\User;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Notifiche\DestinatariEnte;
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

    /**
     * Le preferenze sulle email che seguono un gesto (🔗 ADR-047) — tre sugli
     * interventi e una sulla macchina segnalata — nella stessa forma: una
     * property pubblica ciascuna, scritta con `forceFill` solo da `salva()`.
     */
    public bool $riceveEmailInterventiProgrammati = true;

    public bool $riceveEmailInterventiEseguiti = true;

    public bool $riceveEmailInterventiAssegnati = true;

    public bool $riceveEmailMacchineSegnalate = true;

    /** Property → colonna di `users`: l'unica mappa fra il form e il database. */
    private const COLONNE = [
        'riceveEmailScadenze' => 'riceve_email_scadenze',
        'riceveEmailInterventiProgrammati' => 'riceve_email_interventi_programmati',
        'riceveEmailInterventiEseguiti' => 'riceve_email_interventi_eseguiti',
        'riceveEmailInterventiAssegnati' => 'riceve_email_interventi_assegnati',
        'riceveEmailMacchineSegnalate' => 'riceve_email_macchine_segnalate',
    ];

    public function mount(): void
    {
        // Riletta dal database: l'istanza in sessione può non avere le colonne
        // nuove, e `null` non deve leggersi come «spenta».
        $utente = $this->user()->fresh() ?? $this->user();

        foreach (self::COLONNE as $proprieta => $colonna) {
            $this->{$proprieta} = (bool) ($utente->getAttribute($colonna) ?? true);
        }
    }

    public function salva(): void
    {
        $valori = [];

        foreach (self::COLONNE as $proprieta => $colonna) {
            $valori[$colonna] = (bool) $this->{$proprieta};
        }

        $this->user()->forceFill($valori)->save();

        $this->dispatch('preferenze-salvate');
    }

    /**
     * Le email sugli interventi fra cui questa persona può scegliere.
     *
     * 🔴 **Solo quelle che potrebbe davvero ricevere**: un interruttore per
     * un'email che non le arriverebbe comunque è una promessa falsa in
     * entrambe le posizioni. Due condizioni, entrambe lette altrove e non
     * riscritte qui: la piattaforma l'ha accesa (`InterruttoriEmail`), e il
     * suo ruolo è fra i destinatari (`DestinatariEnte` per le email del
     * cliente, il portafoglio per quella di chi viene assegnato).
     *
     * @return list<array{proprieta: string, titolo: string, testo: string}>
     */
    public function emailInterventi(): array
    {
        $utente = $this->user();
        $delCliente = DestinatariEnte::riguarda($utente);

        $voci = [
            [
                'chiave' => CatalogoEmail::MACCHINA_SEGNALATA,
                'riguarda' => $delCliente,
                'proprieta' => 'riceveEmailMacchineSegnalate',
                'titolo' => 'Macchina segnalata',
                'testo' => 'Un\'email quando qualcuno segnala una macchina che segui come «'.StatoSemaforo::Arancione->etichetta().'» o «'.StatoSemaforo::Rosso->etichetta().'».',
            ],
            [
                'chiave' => CatalogoEmail::INTERVENTO_PROGRAMMATO,
                'riguarda' => $delCliente,
                'proprieta' => 'riceveEmailInterventiProgrammati',
                'titolo' => 'Intervento programmato',
                'testo' => 'Un\'email quando qualcuno pianifica un intervento su una macchina che segui.',
            ],
            [
                'chiave' => CatalogoEmail::INTERVENTO_ESEGUITO,
                'riguarda' => $delCliente,
                'proprieta' => 'riceveEmailInterventiEseguiti',
                'titolo' => 'Intervento eseguito',
                'testo' => 'Un\'email quando un intervento su una macchina che segui viene chiuso come eseguito.',
            ],
            [
                'chiave' => CatalogoEmail::INTERVENTO_ASSEGNATO,
                'riguarda' => $utente->lavoraPerPortafoglio(),
                'proprieta' => 'riceveEmailInterventiAssegnati',
                'titolo' => 'Intervento assegnato a te',
                'testo' => 'Un\'email quando ti viene assegnato un intervento. Il lavoro resta comunque in «Campo».',
            ],
        ];

        return array_values(array_map(
            fn (array $voce) => ['proprieta' => $voce['proprieta'], 'titolo' => $voce['titolo'], 'testo' => $voce['testo']],
            array_filter($voci, fn (array $voce) => $voce['riguarda'] && InterruttoriEmail::attiva($voce['chiave'])),
        ));
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
            'emailInterventi' => $this->emailInterventi(),
        ]);
    }
}
