<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * La pagina di stato del lockout (ADR-013): dove il middleware
 * `account.lockout` manda gli utenti di un account in insoluto.
 *
 * Vive FUORI dal gruppo protetto (routes/web.php, gruppo impersonation) — la
 * sicurezza è posizionale, non un'esclusione `routeIs` che sugli update
 * Livewire non varrebbe. E per lo stesso motivo la pagina non contiene alcun
 * componente Livewire: solo Blade e form POST classici.
 *
 * ⚠️ **Dal 27 Ago 2026 la pagina ha anche una via d'uscita commerciale.**
 * ADR-013 chiama il lockout «leva di pagamento forte», e una leva ha bisogno di
 * uno scatto di rilascio: se l'unico modo di aggiornare una carta scaduta
 * stesse *dietro* il blocco, la leva sarebbe una porta murata. Il moroso vede
 * solo questa pagina, quindi il bottone verso il Billing Portal sta qui — e le
 * due rotte dell'abbonamento stanno fuori dal gruppo protetto proprio per
 * questo.
 *
 * Il gate si calcola **qui e non nella Blade**: così è provabile con un test, e
 * la vista resta muta. Le tre condizioni sono in AND e nessuna è ridondante —
 * un customer che non c'è (piano Free, ADR-002) e un ambiente senza chiavi
 * darebbero un bottone che porta a un errore, e chi non amministra il contratto
 * (Responsabile, Tenant, Tecnico) non deve nemmeno vederlo.
 */
class PaginaBloccato extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        // URL digitata a mano, o arrivo dopo lo sblocco: qui non c'è nulla.
        if (! $user->ente?->account?->is_locked) {
            return redirect()->route('dashboard');
        }

        $account = $user->ente->account;

        return view('bloccato', [
            'nomeEnte' => $user->ente->nome,
            'sedi' => $user->sediRaggiungibili()->orderBy('nome')->get(['id', 'nome']),
            // ⚠️ `manage` e non il permesso nudo: con `teams = false` i permessi
            // di spatie sono globali, quindi il permesso da solo direbbe di sì
            // sul contratto di qualunque cliente. La Policy restringe con
            // l'appartenenza ad `account_user` (ADR-032).
            // 🔴 E non durante un'impersonazione: `Gate::forUser($user)`
            // risponde sull'IMPERSONATO, quindi direbbe di sì, e il bottone
            // porterebbe a un rifiuto del controller (che è dove sta la
            // guardia vera, `AperturaPortaleStripe`). Offrire ciò che si
            // rifiuta è un vicolo cieco.
            'puoPagare' => $account->hasStripeId()
                && filled(config('cashier.secret'))
                && ! app('impersonate')->isImpersonating()
                && Gate::forUser($user)->allows('manage', $account),
        ]);
    }
}
