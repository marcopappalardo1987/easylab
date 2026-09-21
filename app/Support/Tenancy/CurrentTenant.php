<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lab404\Impersonate\Services\ImpersonateManager;
use WeakMap;

/**
 * Risolutore del tenant corrente per il Global Scope multi-tenant (ADR-001/018).
 *
 * Unico punto in cui si legge il contesto: gli scope/trait dipendono da qui,
 * così il livello 2 (sotto-albero Responsabile, unione Tecnico — S2 punto 2)
 * potrà estendere la logica senza toccare i modelli.
 *
 * ADR-018: NESSUN ruolo bypassa lo scope. Ogni utente autenticato è scopato al
 * proprio tenant; l'accesso cross-tenant avviene solo via impersonazione.
 *
 * Impersonation: lab404 sostituisce l'utente autenticato, quindi
 * `Auth::user()` (e il suo `tenant_id`) seguono automaticamente il contesto
 * impersonato.
 *
 * 🔴 **L'unica eccezione, e va letta per intero: la sede scelta durante
 * un'impersonazione vive nella SESSIONE, non sull'utente.**
 *
 * Chi impersona un cliente con più sedi deve poterle vedere tutte — è la
 * ragione per cui impersona. Ma `passaAllEnte()` riscrive `users.tenant_id`,
 * cioè fa una **modifica permanente per conto dell'impersonato**: il cliente si
 * ritroverebbe, al proprio prossimo accesso, in una sede che non ha scelto lui.
 * È la stessa famiglia di gesti per cui il 2FA è chiuso durante
 * un'impersonazione (28 Ago 2026).
 *
 * Quindi lo spostamento è **effimero**: dura quanto l'impersonazione e non
 * tocca una riga di database.
 *
 * ⛔ **Perché non può diventare un varco.** La chiave di sessione porta con sé
 * l'id dell'utente per cui è stata scritta, e si applica solo se **entrambe**
 * le condizioni valgono: si sta impersonando ADESSO, e l'utente autenticato è
 * ancora quello. Una chiave rimasta appesa dopo la fine dell'impersonazione non
 * può quindi scopare nessuno verso l'Ente di un altro: la condizione più
 * stretta la spegne. La legittimità del bersaglio (stesso account, non in
 * lockout) si verifica dove la chiave viene SCRITTA, in `SwitcherEnte::passa()`,
 * e dal 21 Set 2026 anche dove viene LETTA (T2A-2): una query per richiesta,
 * solo durante un'impersonazione.
 */
class CurrentTenant
{
    /**
     * Id del tenant (Ente) dell'utente corrente. Può essere null per un utente
     * autenticato senza tenant (gestito fail-closed da TenantScope) oppure in
     * contesto senza utente (console/seeder/job → nessuno scope).
     */
    /** La chiave di sessione dello spostamento effimero. */
    public const SEDE_IMPERSONATA = 'impersonazione.sede';

    public static function id(): ?int
    {
        $user = self::user();

        if ($user === null) {
            return null;
        }

        $scelta = self::sedeScelta($user);

        return $scelta ?? $user->tenant_id;
    }

    /**
     * La sede scelta a mano da chi impersona, se e solo se è ancora sua.
     *
     * ⛔ Le due condizioni sono in AND e nessuna delle due è ridondante:
     * `isImpersonating()` spegne la chiave appena l'impersonazione finisce, e
     * il confronto sull'id la spegne se nel frattempo si impersona qualcun
     * altro. Fail-closed: al minimo dubbio si torna al `tenant_id` vero.
     */
    protected static function sedeScelta(User $user): ?int
    {
        if (! app(ImpersonateManager::class)->isImpersonating()) {
            return null;
        }

        $scelta = session(self::SEDE_IMPERSONATA);

        if (! is_array($scelta)) {
            return null;
        }

        $ente = ($scelta['utente'] ?? null) === $user->getKey()
            ? ($scelta['ente'] ?? null)
            : null;

        // 🔴 T2A-2: la chiave vive in sessione e sopravvive all'impersonazione
        // che l'ha scritta. Se nel frattempo la persona ha perso l'account di
        // quella sede (o è andato in lockout), la prossima impersonazione la
        // riaprirebbe: la legittimità si riverifica qui, non solo in scrittura.
        if ($ente === null || ! self::sedeAncoraRaggiungibile($user, (int) $ente)) {
            return null;
        }

        return (int) $ente;
    }

    /**
     * Una query per richiesta e per coppia utente/sede: `id()` è chiamato da
     * ogni query scopata, e l'impersonazione è l'unico caso che paga.
     *
     * @var WeakMap<Request, array<string, bool>>|null
     */
    private static ?WeakMap $verifiche = null;

    private static function sedeAncoraRaggiungibile(User $user, int $ente): bool
    {
        $richiesta = app('request');
        self::$verifiche ??= new WeakMap;
        $chiave = $user->getKey().'|'.$ente;

        $memo = self::$verifiche[$richiesta] ?? [];
        if (! array_key_exists($chiave, $memo)) {
            $memo[$chiave] = $user->sediRaggiungibili()->whereKey($ente)->exists();
            self::$verifiche[$richiesta] = $memo;
        }

        return $memo[$chiave];
    }

    /**
     * True quando esiste un utente autenticato: in tal caso lo scope si applica
     * (fail-closed se senza tenant). False solo per i contesti SENZA utente
     * (console/seeder/job/guest), dove il Global Scope non filtra.
     */
    public static function shouldScope(): bool
    {
        return self::user() !== null;
    }

    protected static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
