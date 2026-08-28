<?php

namespace App\Livewire\Billing;

use App\Models\Account;
use App\Support\Piani;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * L'abbonamento visto dal cliente: il cartello davanti al Billing Portal
 * ospitato di Stripe (🔗 ADR-032 — l'intestatario del rapporto commerciale è
 * l'**Account**, non l'utente né l'Ente; ADR-013 per il lockout; ADR-002 per il
 * piano Free, che un customer Stripe non ce l'ha per definizione).
 *
 * ## 🔴 L'autorizzazione vive QUI, e non c'è nessun `can:` a raccoglierla
 *
 * La rotta `abbonamento.index` sta nel gruppo `auth` **nudo**, accanto a
 * `/bloccato` e fuori da `account.lockout` e `two-factor.enforce`: un account
 * bloccato per insoluto deve poter **pagare**, o la «leva di pagamento forte»
 * di ADR-013 non ha scatto di rilascio. Conseguenza diretta: non esiste un
 * middleware di rotta a proteggere questa pagina, e la guardia è
 * `Gate::authorize('manage', $account)` scritta qui dentro.
 *
 * ⚠️ **L'ability si chiama `manage`, il permesso `billing.manage_own`, e la
 * differenza è ciò che rende la Policy raggiungibile.** Con `teams = false` i
 * permessi di spatie sono **globali** — un `can:` sul permesso nudo
 * concederebbe su OGNI account della piattaforma — e il `Gate::before` di
 * spatie concede appena il permesso esiste sul ruolo, quindi un'ability
 * omonima non verrebbe mai interrogata. `AccountPolicy` restringe con
 * l'appartenenza al pivot `account_user`, e un guardrail meccanico
 * (`tests/Feature/Billing/AccountPolicyTest.php`) rende rossa la stringa nuda
 * ovunque fuori dalla Policy.
 *
 * ⚠️ **La guardia è scritta due volte, in `mount()` e in `render()`**, e non è
 * ridondanza decorativa: `mount()` copre il caricamento iniziale, `render()`
 * ogni giro successivo. Il buco che resta è quello che routes/web.php documenta
 * due volte — un'azione con `skipRender()` non arriverebbe a `render()`. Oggi
 * questo componente **non ha azioni** (il portale si apre con un form POST
 * classico verso un controller, che riscrive la propria guardia), e chi ne
 * aggiungesse una deve autorizzarla nel proprio metodo: qui non c'è un `can:`
 * di rotta sotto a fare da rete.
 *
 * ## Cosa la pagina NON mostra, e perché
 *
 * ⛔ **Nessun `locked_reason`, nessun `stripe_lock_reason`.** Sono annotazioni
 * operative interne — solleciti, riferimenti di pratica, id di evento Stripe —
 * il cui destinatario è la cabina di regia; ADR-013 li tiene fuori da
 * `/bloccato` apposta, e questa pagina è la superficie **nuova** raggiungibile
 * da un account bloccato con l'oggetto Account in mano. Un test negativo lo
 * presidia.
 *
 * ⛔ **Nessun prezzo.** `prezzo_mensile_cent` in `config/easylab.php` è
 * dichiarato «a LISTINO, non incassato», i 4900 sono un segnaposto del 21 Ago
 * 2026 e **nulla in questo repository può accorgersi** se divergono dal Price
 * su Stripe. Stamparlo su una pagina rivolta al cliente pagante significa
 * affermare quanto paga senza saperlo. Le cifre vere sono nel portale.
 *
 * ## ZERO rete nel ciclo di render
 *
 * Tutto ciò che si legge qui viene da colonne locali. La rete la chiama solo il
 * controller, dietro un POST — il progetto vieta di chiamare Stripe da un
 * `render()` (lo dichiara `AmministraAccount::salvaFiscali()`), perché un
 * timeout del fornitore diventerebbe una pagina che non si apre.
 */
#[Title('Abbonamento — Easy Lab')]
#[Layout('components.layouts.app')]
class PaginaAbbonamento extends Component
{
    public function mount(): void
    {
        $this->account();
    }

    public function render()
    {
        $account = $this->account();
        $account->loadMissing('subscriptions');

        // ⚠️ `Piani::etichetta()` e `Piani::maxEnti()` **lanciano** su un piano
        // fuori catalogo, e `accounts.piano` è una stringa senza CHECK a DB: i
        // piani fuori catalogo esistono già ed è uno stato governato, non
        // impedito (ADR-035). Il ripiego stampa il codice grezzo — mai
        // un'eccezione sulla pagina da cui il cliente sta cercando di pagare.
        $pianoNoto = Piani::esiste($account->piano);

        return view('livewire.billing.pagina-abbonamento', [
            'ragioneSociale' => $account->ragione_sociale,
            'etichettaPiano' => $pianoNoto ? Piani::etichetta($account->piano) : $account->piano,
            'maxEnti' => $pianoNoto ? Piani::maxEnti($account->piano) : null,
            'entiUsati' => $account->enti()->count(),
            'statoAbbonamento' => $this->statoAbbonamento($account),
            // 🔴 SOLO il booleano. Il motivo del blocco non esce da qui.
            'sospeso' => (bool) $account->is_locked,
            'pianoGratuito' => $pianoNoto && Piani::eGratuito($account->piano),
            // Le due condizioni insieme: un customer che non c'è (Free, ADR-002)
            // e un ambiente senza chiavi darebbero entrambi un bottone che
            // porta a un errore. Meglio non offrirlo.
            'haPortale' => $account->hasStripeId() && filled(config('cashier.secret')),
        ]);
    }

    /**
     * L'account è quello dell'Ente CORRENTE (`$user->ente?->account`), non una
     * scelta esplicita: stesso criterio di `EnforceAccountLockout`, e con lo
     * switcher già in top bar (ADR-032) chi amministra due account cambia sede
     * e la pagina segue. Un select degli account sarebbe una seconda superficie
     * di scelta cross-account da proteggere, per un caso che oggi non ha
     * clienti.
     *
     * Ente senza account → **404** (fail-closed: non c'è nulla da amministrare,
     * e un 403 dichiarerebbe l'esistenza di qualcosa). Account presente ma
     * utente non membro → **403**, dalla Policy.
     */
    private function account(): Account
    {
        $account = auth()->user()?->ente?->account;

        abort_if($account === null, 404);

        Gate::authorize('manage', $account);

        return $account;
    }

    /**
     * Lo stato dell'abbonamento in una frase italiana, letto da `stripe_status`
     * — una colonna locale, zero rete.
     *
     * 🔴 **Non `subscribed()` né `Subscription::valid()`**, ed è la stessa
     * trappola che `easylab:abbona` ha già pagato: coi default di Cashier
     * (`$deactivatePastDue`, `$deactivateIncomplete`) una subscription in
     * `past_due` risulta NON valida, quindi la pagina direbbe «nessun
     * abbonamento» proprio al cliente in dunning che sta cercando di pagare —
     * cioè all'unica persona per cui questa pagina esiste davvero.
     */
    private function statoAbbonamento(Account $account): string
    {
        $sub = $account->subscription('default');

        $stato = match ($sub?->stripe_status) {
            null => 'Nessun abbonamento',
            'active' => 'Attivo',
            'trialing' => 'In prova',
            'past_due' => 'Pagamento in sospeso: Stripe sta ritentando l\'addebito',
            'unpaid', 'canceled' => 'Chiuso',
            'incomplete', 'incomplete_expired' => 'Mai partito',
            // Uno stato che Stripe introducesse domani non deve diventare una
            // pagina bianca: si stampa com'è, e chi legge ha di che chiamare.
            default => $sub->stripe_status,
        };

        if ($sub?->ends_at !== null && $sub->ends_at->isFuture()) {
            $stato .= ' — disdetto, attivo fino al '.$sub->ends_at->format('d/m/Y');
        }

        return $stato;
    }
}
