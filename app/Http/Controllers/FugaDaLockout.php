<?php

namespace App\Http\Controllers;

use App\Models\UnitaOrganizzativa;
use Illuminate\Http\Request;

/**
 * La via di fuga dal lockout (ADR-013): l'utente di un account bloccato passa
 * a una sede di un ALTRO suo account non bloccato.
 *
 * Il controller non ripete NESSUNA guardia: destinazione-ente, appartenenza,
 * lockout della destinazione e impersonazione vivono in `User::passaAllEnte()`
 * (ADR-032), che audita da sé. Stessa divisione di lavoro dello switcher: la
 * pagina offre, il dominio decide.
 *
 * `{ente}` è un id nudo, NIENTE route-model binding: il binding implicito
 * passerebbe dal TenantScope dell'utente bloccato — fail-closed → 404
 * sistematico verso qualunque altra sede. Si risolve a mano, come fa lo
 * switcher.
 */
class FugaDaLockout extends Controller
{
    public function __invoke(Request $request, int $ente)
    {
        $nodo = UnitaOrganizzativa::withoutGlobalScopes()->find($ente);

        if ($nodo === null || ! $request->user()->passaAllEnte($nodo)) {
            return redirect()->route('bloccato'); // fail-closed silenzioso
        }

        return redirect()->route('dashboard');
    }
}
