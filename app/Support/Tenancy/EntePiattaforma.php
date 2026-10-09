<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * L'Ente di piattaforma: la sede di EasyLab stessa, cioè l'Ente dell'account
 * `di_piattaforma` (🔗 ADR-048; ADR-018 la piattaforma, ADR-032 l'Account).
 *
 * Serve a una cosa sola: dire **di quale Ente amministra le persone** chi
 * governa la piattaforma e non ne ha uno proprio. Il Developer nasce senza
 * Ente, e fino al 9 Ott 2026 la pagina Persone gli rispondeva 403: per
 * promuovere qualcuno a Superadmin doveva impersonare un Superadmin, cioè
 * compiere il gesto più delicato dell'applicazione **a nome di un altro**.
 *
 * ## ⛔ Non è un bypass della tenancy
 *
 * Restituisce un id, e quell'id è sempre e solo l'Ente di EasyLab: mai quello
 * di un cliente, e mai «nessun confine». Chi lo riceve continua a filtrare per
 * quell'Ente come farebbe col proprio. E risponde solo a chi è Superadmin o
 * Developer **per ruolo**: un tecnico o un gestore senza Ente restano dove
 * erano, senza persone da amministrare.
 *
 * Query builder e non Eloquent, come `ClientiGestiti`: nessun global scope da
 * togliere, quindi nessun bypass da giustificare.
 */
final class EntePiattaforma
{
    /**
     * L'id dell'Ente di piattaforma, o `null` se la piattaforma non ne ha uno.
     *
     * Se l'account di piattaforma avesse più sedi vale la prima per id: è
     * quella con cui il seeder fa nascere il Superadmin.
     *
     * Nessun filtro sul `tipo`: `account_id` vive solo sui nodi Ente, e a
     * tenerlo fermo è `UnitaOrganizzativa` al salvataggio (🔗 ADR-032).
     */
    public static function id(): ?int
    {
        $id = DB::table('unita_organizzativa')
            ->whereNull('deleted_at')
            ->whereIn('account_id', DB::table('accounts')
                ->where('di_piattaforma', true)
                ->whereNull('deleted_at')
                ->select('id'))
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * L'Ente di cui questa persona amministra le persone **in mancanza di un
     * Ente proprio**: quello di piattaforma per Superadmin e Developer, nessuno
     * per chiunque altro.
     */
    public static function perChiGoverna(?User $utente): ?int
    {
        if ($utente === null || ! $utente->hasAnyRole([User::SUPERADMIN_ROLE, 'Developer'])) {
            return null;
        }

        return self::id();
    }
}
