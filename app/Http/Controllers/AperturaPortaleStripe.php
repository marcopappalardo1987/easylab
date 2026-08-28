<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AuditLog;
use App\Support\Billing\PortaleStripe;
use App\Support\Piani;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Apre il Billing Portal ospitato di Stripe per l'Account del richiedente
 * (🔗 ADR-032 — l'intestatario è l'Account; ADR-013 — ci si arriva anche da
 * bloccati; ADR-002 — il piano Free non ha customer Stripe).
 *
 * ## 🔴 L'autorizzazione è QUI, e si RISCRIVE
 *
 * La rotta `abbonamento.portale` vive nel gruppo `auth` **nudo**, fuori da
 * `account.lockout` e da `two-factor.enforce`: un account bloccato per insoluto
 * deve poter pagare, o la leva di ADR-013 non ha scatto di rilascio. Non c'è
 * quindi nessun `can:` di rotta — e nemmeno potrebbe esserci, perché il
 * permesso nudo `billing.…` con `teams = false` concederebbe su OGNI account
 * della piattaforma e non c'è un route-model binding su cui appendere
 * `can:manage,account`.
 *
 * ⚠️ **La guardia si riscrive invece di ereditarla dalla pagina.** La sicurezza
 * di questo progetto è per-URL, e un POST arriva senza passare dalla pagina che
 * lo offre: chi non è membro dell'account non vede il bottone, ma può comunque
 * costruire la richiesta. Il bottone non è la guardia. Un test negativo copre
 * esattamente questo POST — non basta che lo copra la GET.
 *
 * ## ⛔ Tutte le guardie PRIMA di qualunque chiamata di rete
 *
 * È la disciplina di `easylab:abbona`, che esiste per non lasciare oggetti a
 * metà su Stripe. In particolare `billingPortalUrl()` apre con
 * `assertCustomerExists()`, che lancia su `stripe_id` null: un account Free non
 * ha customer **per definizione**, quindi è un caso normalissimo da intercettare
 * con `hasStripeId()`, non un'anomalia da lasciar esplodere in faccia a chi ha
 * cliccato.
 *
 * ## 🔴 In IMPERSONAZIONE il portale non si apre
 *
 * `Gate::authorize('manage', …)` risponde sull'utente della guard, e lab404
 * **sostituisce** quell'utente (`ImpersonateManager::quietLogin`): dentro
 * un'impersonazione la guardia risponde sull'IMPERSONATO, quindi dice di sì —
 * e la sessione di portale si aprirebbe sul customer del **cliente**.
 * Riprodotto il 28 Ago 2026: un Superadmin di un altro Account, impersonando
 * l'Admin di «Cliente Moroso», otteneva un 302 verso una sessione di portale
 * intestata a quel customer. Con la disdetta abilitata nel portale (27 Ago
 * 2026) quella sessione permette di **disdire l'abbonamento del cliente** e di
 * cambiargli il metodo di pagamento.
 *
 * La specifica dice «nessun accesso al portale altrui per il Superadmin da
 * questa feature»: qui è dove diventa vera. Il gesto di assistenza commerciale
 * si compie dalla dashboard di Stripe, che è già il posto in cui vivono le
 * chiavi e i log del fornitore; l'esenzione di ADR-013 sull'impersonazione
 * riguarda l'**assistenza e l'export** in lockout, non l'intestazione di un
 * rapporto commerciale.
 *
 * ⚠️ **E l'apertura riuscita lascia una riga di audit.** Nel progetto anche il
 * download di un documento la lascia (`ScaricaDocumento`); aprire una sessione
 * da cui si può disdire un abbonamento non può essere l'unico gesto muto. Il
 * registro dice CHI l'ha aperta e su quale Account, non l'URL — che è un
 * segreto a uso singolo.
 *
 * ⚠️ **Un controller invokable esegue sempre il proprio corpo**: la trappola
 * `skipRender()` che rende fragili le guardie scritte in un `render()` Livewire
 * qui non esiste. È metà della ragione per cui questo pezzo non è un'azione
 * Livewire; l'altra metà è che il progetto vieta di chiamare la rete da un ciclo
 * di render.
 */
class AperturaPortaleStripe extends Controller
{
    public function __invoke(Request $request, PortaleStripe $portale): RedirectResponse
    {
        $account = $request->user()?->ente?->account;

        // Stesso verso della pagina: senza account non c'è nulla da
        // amministrare, e un 403 dichiarerebbe l'esistenza di qualcosa.
        abort_if($account === null, 404);

        Gate::authorize('manage', $account);

        // 🔴 Subito DOPO la Policy, perché è la Policy che qui non basta:
        // risponde sull'utente della guard, e in impersonazione quell'utente è
        // il cliente. Vedi il docblock: senza questa riga il portale di un
        // ALTRO Account si apre, e da lì se ne disdice l'abbonamento.
        if (app('impersonate')->isImpersonating()) {
            Log::warning('Portale di fatturazione richiesto durante un\'impersonazione: rifiutato.', [
                'account_id' => $account->id,
                'impersonato_da' => app('impersonate')->getImpersonatorId(),
            ]);

            return back()->with('erroreAbbonamento', 'Il portale di fatturazione non si apre durante un\'impersonazione: la sessione sarebbe intestata al customer del cliente. Esci dall\'impersonazione, oppure usa la dashboard di Stripe.');
        }

        // ⚠️ La chiave prima del customer: senza segreto nessuna chiamata può
        // riuscire, e la forma in cui questo si presenta su Laravel Cloud è
        // insidiosa — `optimize` gira in BUILD, quindi una variabile aggiunta
        // dopo la build resta invisibile e ogni `env()` torna null. Il progetto
        // l'ha già pagata una volta.
        if (! filled(config('cashier.secret'))) {
            Log::warning('Portale di fatturazione richiesto su un ambiente senza STRIPE_SECRET.', [
                'account_id' => $account->id,
                'rimedio' => 'php artisan config:show cashier — se è null, ricostruire: su Laravel Cloud la config è cachata in build.',
            ]);

            return back()->with('erroreAbbonamento', 'Il portale di fatturazione non è configurato su questo ambiente. Contatta EasyLab.');
        }

        if (! $account->hasStripeId()) {
            return back()->with('erroreAbbonamento', $this->messaggioSenzaCustomer($account));
        }

        try {
            // ⛔ `route('abbonamento.index')` ESPLICITO e mai omesso: il default
            // di Cashier è `route('home')`, e in questa applicazione non esiste
            // nessuna rotta con quel nome (`config/fortify.php` dichiara
            // `'home' => '/dashboard'`, che è un PERCORSO). Ometterlo darebbe
            // `RouteNotFoundException`, cioè un 500 sul bottone principale
            // della feature. Un test verifica che il returnUrl arrivi.
            $url = $portale->url($account, route('abbonamento.index'));
        } catch (Throwable $e) {
            // `report()` e non un catch muto: l'error tracker interno è
            // agganciato in `bootstrap/app.php`, quindi il guasto finisce in
            // /piattaforma/errori invece di sparire. Un 500 nudo sarebbe una
            // pagina bianca su un bottone che il cliente clicca proprio quando
            // è in difficoltà.
            report($e);

            return back()->with('erroreAbbonamento', 'Non è stato possibile aprire il portale di fatturazione. Riprova fra poco.');
        }

        // ADR-026: si traccia l'ATTO, non l'URL — che è un segreto valido una
        // volta sola e non ha nulla da fare in un registro consultabile.
        activity(AuditLog::NAME)
            ->causedBy($request->user())
            ->performedOn($account)
            ->log('Portale di fatturazione aperto');

        return redirect()->away($url);
    }

    /**
     * Due situazioni diverse dietro lo stesso `stripe_id` mancante, e vanno
     * dette diversamente (è la stessa distinzione che `Piani::eGratuito()`
     * esiste per fare):
     *
     * - piano **gratuito** → non è un guasto, è la definizione del Free
     *   (ADR-002: omaggiato a fronte di un contratto fisico, fatturato fuori
     *   dal software). Nessun log: non c'è niente da riparare;
     * - piano **a pagamento senza customer** → è un'ANOMALIA vera, e il cliente
     *   non deve leggerne la diagnosi: messaggio neutro a lui, riga di log per
     *   chi può rimediare.
     *
     * ⚠️ `Piani::esiste()` davanti a `eGratuito()`: i getter del catalogo
     * **lanciano** su un piano fuori catalogo, e `accounts.piano` è una stringa
     * senza CHECK a DB (ADR-035). Un piano ignoto cade nel ramo anomalia, che è
     * il verso giusto.
     */
    private function messaggioSenzaCustomer(Account $account): string
    {
        if (Piani::esiste($account->piano) && Piani::eGratuito($account->piano)) {
            return 'Il piano Free non ha un portale di fatturazione: è la sua definizione.';
        }

        Log::warning('Account su piano a pagamento senza customer Stripe: portale non apribile.', [
            'account_id' => $account->id,
            'piano' => $account->piano,
        ]);

        return 'Il portale di fatturazione non è disponibile per questo account. Contatta EasyLab.';
    }
}
