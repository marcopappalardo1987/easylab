<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * La pagina di stato del lockout (ADR-013): dove il middleware
 * `account.lockout` manda gli utenti di un account in insoluto.
 *
 * Vive FUORI dal gruppo protetto (routes/web.php, gruppo impersonation) — la
 * sicurezza è posizionale, non un'esclusione `routeIs` che sugli update
 * Livewire non varrebbe. E per lo stesso motivo la pagina non contiene alcun
 * componente Livewire: solo Blade e form POST classici.
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

        return view('bloccato', [
            'nomeEnte' => $user->ente->nome,
            'sedi' => $user->sediRaggiungibili()->orderBy('nome')->get(['id', 'nome']),
        ]);
    }
}
