<?php

namespace App\Support\Tenancy;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Scopes\DepartmentScope;
use App\Models\UnitaOrganizzativa;
use Illuminate\Support\Collection;

/**
 * Le sedi su cui lavora chi guarda, quando possono essere **più di una**
 * (🔗 ADR-046; ADR-030 il portafoglio, ADR-018 il confine).
 *
 * Fino al 6 Ott 2026 «in scope» e «del mio Ente» erano la stessa cosa per
 * chiunque potesse scrivere: il solo ruolo con più Enti in vista, il Tecnico,
 * non creava né spostava nulla. Con il Gestore e con il Superadmin sui clienti
 * gestiti non è più vero, e ogni pagina che sceglie un **secondo** oggetto
 * (un reparto di destinazione, la sede di un fornitore nuovo) deve chiedersi di
 * quale cliente è.
 *
 * ⚠️ Non calcola un perimetro: lo legge. Le sedi sono i nodi Ente che
 * `TenantScope` lascia passare, quindi qui non c'è una seconda copia della
 * regola che possa divergere dalla prima.
 */
final class SediSeguite
{
    /**
     * True per chi **può** avere in vista righe di più clienti: chi lavora per
     * portafoglio e il Superadmin. Per ruolo e senza query — serve a decidere
     * se una lista mostra la colonna «Sede».
     */
    public static function piuClienti(): bool
    {
        return AccessoTecnico::siApplica() || ClientiGestiti::siApplica();
    }

    /**
     * True per chi ha **dati su cui lavorare**: un Ente proprio, o un ruolo
     * che segue dei clienti.
     *
     * È la domanda del menù e dell'ingresso: a chi risponde no — il Developer
     * senza Ente — le pagine operative mostrerebbero solo liste vuote.
     *
     * ⚠️ **Per ruolo, e senza query**: gira nel layout di ogni pagina. Conta
     * quindi anche un portafoglio vuoto e un Superadmin senza clienti gestiti:
     * le loro pagine dicono che non c'è ancora nulla, che è la verità.
     */
    public static function haDoveLavorare(): bool
    {
        return CurrentTenant::id() !== null || self::piuClienti();
    }

    /**
     * Il nome della sede di chi guarda, **se le righe che vede sono di quella
     * sola**; altrimenti `null`.
     *
     * Serve ai titoli che nominano una sede — il PDF dell'archivio documentale
     * — e che col Superadmin sui clienti gestiti direbbero «EasyLab» sopra un
     * elenco che contiene i documenti di altri. Un foglio ritrovato fra un anno
     * deve dire di cosa parla, o non dire niente.
     *
     * ⚠️ `CurrentTenant::id()` e non `users.tenant_id`: impersonando, lo
     * switcher sposta la sede in sessione (D-T2-3).
     */
    public static function sedeUnica(): ?string
    {
        $corrente = CurrentTenant::id();

        if ($corrente === null || (ClientiGestiti::siApplica() && ClientiGestiti::esistono())) {
            return null;
        }

        return UnitaOrganizzativa::withoutGlobalScope(DepartmentScope::class)->whereKey($corrente)->value('nome');
    }

    /**
     * I nodi Ente in vista, col cliente che li intesta.
     *
     * ⚠️ Per un Responsabile Reparto è vuota (`DepartmentScope` gli toglie il
     * nodo Ente): chi la usa deve trattare lo zero come «la sede è la sua», non
     * come «non ha sedi».
     *
     * @return Collection<int,UnitaOrganizzativa>
     */
    public static function elenco(): Collection
    {
        return UnitaOrganizzativa::query()
            ->where('tipo', TipoUnitaOrganizzativa::Ente->value)
            ->with('account:id,ragione_sociale')
            // Tie-break sull'id: a parità di nome l'ordine è del motore.
            ->orderBy('nome')
            ->orderBy('id')
            ->get();
    }

    /**
     * «Cliente — sede», o la sola sede quando coincidono: l'etichetta con cui
     * una riga dice di chi è.
     */
    public static function etichetta(UnitaOrganizzativa $sede): string
    {
        $cliente = $sede->account?->ragione_sociale;

        return blank($cliente) || $cliente === $sede->nome ? $sede->nome : "{$cliente} — {$sede->nome}";
    }
}
