<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Piano;
use App\Support\AuditLog;
use App\Support\Billing\AbbonamentoGiaSuStripe;
use App\Support\Billing\AbbonamentoStripe;
use App\Support\Billing\OffertaPiani;
use App\Support\Billing\PianiAcquistabili;
use App\Support\Piani;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * Il cliente attiva un piano a pagamento, o passa a uno più grande, dalla
 * propria pagina «Abbonamento» (🔗 ADR-045; ADR-032 — l'intestatario è
 * l'Account; ADR-013 — ci si arriva anche da bloccati; ADR-002 — il piano
 * gratuito non passa da Stripe).
 *
 * Fino al 3 Ott 2026 un piano a pagamento cominciava in due soli modi: il
 * self-signup pubblico, per chi un account non l'aveva, e `easylab:abbona`, da
 * un terminale. Chi un account l'aveva già — il cliente nato Free dalla cabina
 * — non aveva nessuna porta per pagare.
 *
 * ## 🔴 L'autorizzazione è QUI, e si RISCRIVE
 *
 * La rotta `abbonamento.piano` vive nel gruppo `auth` **nudo**, accanto al
 * portale e a `/bloccato`: un account chiuso per disdetta deve poter
 * **riattivarsi**, o la leva di ADR-013 non ha scatto di rilascio. Non c'è
 * quindi nessun `can:` di rotta, e la guardia è `Gate::authorize('manage', …)`
 * scritta in questo corpo — non ereditata dalla pagina che offre il bottone: un
 * POST arriva senza passare dalla pagina.
 *
 * ## 🔴 La regola di prodotto si rifà sul POST, e non è il bottone a tenerla
 *
 * «Mai un piano gratuito, mai uno che costa meno» vive in `PianiAcquistabili`.
 * La pagina la usa per decidere cosa mostrare; questo controller la **rifà**
 * sul codice arrivato dal form, perché un `piano=free` scritto a mano nel corpo
 * della richiesta non è passato da nessun bottone. Il piano si cerca
 * nell'offerta e si usa quello trovato lì: dal form arriva solo una stringa.
 *
 * ⚠️ **Il modo non lo sceglie il cliente.** Che si apra un checkout o si cambi
 * prezzo lo decide lo stato dell'account, letto qui: lasciarlo a un campo del
 * form permetterebbe di aprire un secondo abbonamento accanto a quello in
 * corso.
 *
 * ## ⛔ Tutte le guardie PRIMA di qualunque chiamata di rete
 *
 * È la disciplina di `easylab:abbona` e di `AperturaPortaleStripe`: chi viene
 * rifiutato non deve lasciare dietro di sé un customer o una sessione a metà.
 *
 * ## 🔴 In IMPERSONAZIONE non si compra
 *
 * `Gate::authorize('manage', …)` risponde sull'utente della guard, e
 * impersonando quell'utente è il cliente: un operatore aprirebbe un pagamento,
 * o cambierebbe un prezzo, **a nome suo**. È lo stesso rifiuto del portale, per
 * una ragione più forte — là si poteva disdire, qui si impegna qualcuno a
 * pagare di più.
 *
 * ## Cosa NON fa: scrivere il piano all'attivazione
 *
 * Aprire un checkout non è pagare. `accounts.piano` lo scrive il webhook
 * quando Stripe conferma l'incasso (`StripeWebhookController::applicaStato()`),
 * e il ritorno del browser è solo un cartello. Sul **cambio** invece Stripe ha
 * già risposto dentro questa richiesta: il piano si scrive subito, e il webhook
 * che arriva dopo trova un no-op.
 *
 * ⚠️ **Controller invokable e non azione Livewire**, come il portale: la pagina
 * `/abbonamento` non deve avere azioni (`PaginaAbbonamento`, la fuga dal
 * lockout), e il progetto vieta di chiamare la rete da un ciclo di render.
 */
class SceltaPianoAbbonamento extends Controller
{
    /**
     * Le due descrizioni di audit, come costanti: i test contano queste righe,
     * e una stringa riscritta a mano resterebbe verde a qualunque cosa la si
     * cambiasse.
     */
    public const AUDIT_CHECKOUT = 'Checkout di attivazione piano aperto';

    public const AUDIT_CAMBIO = 'Cambio piano richiesto dal cliente';

    public function __invoke(Request $request, AbbonamentoStripe $stripe): RedirectResponse
    {
        $account = $request->user()?->ente?->account;

        // Stesso verso della pagina e del portale: senza account non c'è nulla
        // da amministrare, e un 403 dichiarerebbe l'esistenza di qualcosa.
        abort_if($account === null, 404);

        Gate::authorize('manage', $account);

        // 🔴 Subito DOPO la Policy, perché è la Policy che qui non basta: vedi
        // il docblock.
        if (app('impersonate')->isImpersonating()) {
            Log::warning('Scelta di un piano richiesta durante un\'impersonazione: rifiutata.', [
                'account_id' => $account->id,
                'impersonato_da' => app('impersonate')->getImpersonatorId(),
            ]);

            return $this->rifiuto('Un piano non si attiva e non si cambia durante un\'impersonazione: l\'acquisto sarebbe a nome del cliente. Esci dall\'impersonazione.');
        }

        // ⚠️ La chiave prima di tutto il resto: senza segreto nessuna chiamata
        // può riuscire. Su Laravel Cloud `optimize` gira in BUILD, quindi una
        // variabile aggiunta dopo resta invisibile — già pagata una volta.
        if (! filled(config('cashier.secret'))) {
            Log::warning('Scelta di un piano richiesta su un ambiente senza STRIPE_SECRET.', [
                'account_id' => $account->id,
                'rimedio' => 'php artisan config:show cashier — se è null, ricostruire: su Laravel Cloud la config è cachata in build.',
            ]);

            return $this->rifiuto('I pagamenti non sono configurati su questo ambiente. Contatta EasyLab.');
        }

        $offerta = PianiAcquistabili::per($account);

        if ($offerta->impedimento !== null) {
            return $this->rifiuto((string) $offerta->messaggioImpedimento());
        }

        $codice = $request->input('piano');

        // Il doppio clic sul cambio: la prima richiesta ha già fatto tutto, e
        // la seconda trova il piano fra quelli non più offerti. Dirle «non
        // disponibile» sarebbe smentire un gesto appena riuscito.
        if ($offerta->eUnCambio() && is_string($codice) && $codice === $account->piano) {
            return redirect()->route('abbonamento.index')
                ->with('esitoAbbonamento', 'Il piano «'.Piani::etichetta($codice).'» è già attivo.');
        }

        // 🔴 La riga che regge la regola di prodotto: gratuito, più economico,
        // archiviato, senza price, inesistente — nessuno di questi è
        // nell'offerta, quindi nessuno arriva a Stripe.
        if (! $offerta->accetta($codice)) {
            return $this->rifiuto('Il piano scelto non è disponibile per questo account.');
        }

        /** @var Piano $piano */
        $piano = $offerta->piano($codice);
        $price = (string) Piani::stripePrice($piano->codice);

        return $offerta->eUnCambio()
            ? $this->cambia($request, $stripe, $account, $offerta, $piano, $price)
            : $this->attiva($request, $stripe, $account, $piano, $price);
    }

    /** Nessun abbonamento in corso: si consegna il browser al Checkout di Stripe. */
    private function attiva(Request $request, AbbonamentoStripe $stripe, Account $account, Piano $piano, string $price): RedirectResponse
    {
        try {
            // ⛔ I due URL di ritorno ESPLICITI: il default di Cashier è
            // `route('home')`, che qui non esiste. Il parametro è un cartello —
            // la pagina non ne deduce nulla sul pagamento.
            $url = $stripe->checkout(
                $account,
                $piano->codice,
                $price,
                route('abbonamento.index', ['checkout' => 'ok']),
                route('abbonamento.index', ['checkout' => 'annullato']),
            );
        } catch (AbbonamentoGiaSuStripe $e) {
            // Lo specchio locale è indietro: se sono secondi, basta aspettare;
            // se sono i webhook a non arrivare, serve una persona — e il
            // tracker interno è il posto in cui la si avvisa.
            report($e);

            return $this->rifiuto('Su Stripe risulta già un abbonamento in corso per questo account: la pagina si aggiorna appena arriva la conferma. Se fra qualche minuto non è cambiata, contatta EasyLab.');
        } catch (Throwable $e) {
            report($e);

            return $this->rifiuto('Non è stato possibile aprire il pagamento. Riprova fra poco.');
        }

        // ADR-026: si traccia l'ATTO, non l'URL — che è un segreto a tempo.
        activity(AuditLog::NAME)
            ->causedBy($request->user())
            ->performedOn($account)
            ->withProperties(['piano' => $piano->codice])
            ->log(self::AUDIT_CHECKOUT);

        return redirect()->away($url);
    }

    /** Abbonamento in corso e sano: si cambia il prezzo di quello che c'è. */
    private function cambia(Request $request, AbbonamentoStripe $stripe, Account $account, OffertaPiani $offerta, Piano $piano, string $price): RedirectResponse
    {
        // ⚠️ Un clic qui impegna il cliente a pagare di più, senza una pagina
        // di Stripe in mezzo a chiedere conferma: la casella è quella pagina.
        if (! $request->boolean('conferma')) {
            return $this->rifiuto('Per cambiare piano spunta la casella di conferma accanto al piano scelto.');
        }

        $da = $account->piano;

        try {
            $stato = $stripe->cambia($offerta->abbonamento, $price);
        } catch (IncompletePayment $e) {
            report($e);

            return $this->rifiuto('Stripe chiede una conferma del pagamento per completare il cambio: aprila dal portale di fatturazione.');
        } catch (Throwable $e) {
            report($e);

            return $this->rifiuto('Non è stato possibile cambiare piano. Riprova fra poco.');
        }

        activity(AuditLog::NAME)
            ->causedBy($request->user())
            ->performedOn($account)
            ->withProperties(['da' => $da, 'a' => $piano->codice])
            ->log(self::AUDIT_CAMBIO);

        // Il piano si scrive solo su un abbonamento sano, come fa il webhook:
        // uno stato diverso significa che Stripe sta ancora aspettando
        // qualcosa, e sarà il suo evento a dire com'è finita.
        if (! in_array($stato, [StripeSubscription::STATUS_ACTIVE, StripeSubscription::STATUS_TRIALING], true)) {
            return redirect()->route('abbonamento.index')
                ->with('esitoAbbonamento', 'Richiesta inviata a Stripe: il piano si aggiorna appena arriva la conferma.');
        }

        $account->cambiaPiano($piano->codice);

        return redirect()->route('abbonamento.index')
            ->with('esitoAbbonamento', "Sei passato al piano «{$piano->etichetta}». La differenza per il periodo già iniziato sarà nella prossima fattura.");
    }

    /**
     * ⚠️ Sempre verso la pagina, mai `back()`: questo POST si offre da un posto
     * solo, e un `Referer` assente o forgiato non deve decidere dove si atterra.
     */
    private function rifiuto(string $messaggio): RedirectResponse
    {
        return redirect()->route('abbonamento.index')->with('erroreAbbonamento', $messaggio);
    }
}
