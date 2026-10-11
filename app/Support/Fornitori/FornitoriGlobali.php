<?php

namespace App\Support\Fornitori;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Fornitore;
use App\Models\Scopes\TenantScope;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * I fornitori di EasyLab, usabili dentro il catalogo di qualunque cliente
 * (🔗 ADR-062; ADR-023 il catalogo per Ente, ADR-046 chi segue più clienti).
 *
 * «Globale» è un fornitore che sta nel catalogo di una sede di EasyLab
 * (l'account `di_piattaforma`). Quando chi lavora per EasyLab lo sceglie sulla
 * macchina di un cliente, **viene copiato** nel catalogo di quel cliente: da lì
 * in poi è una riga del cliente come le altre, che lui vede e la macchina usa.
 *
 * 🔴 **Una copia, non una vista condivisa, ed è la scelta che tiene fermo il
 * confine.** `TenantScope` non impara nessuna eccezione: il cliente continua a
 * leggere il solo proprio catalogo, e ci trova i fornitori che ha inserito e
 * quelli che EasyLab ha usato da lui. Gli altri fornitori di EasyLab non li
 * vede perché nel suo catalogo non ci sono.
 *
 * L'unica lettura fuori dal confine è qui, ed è dichiarata: il catalogo di
 * EasyLab, letto da chi lavora per EasyLab, col `tenant_id` nella query.
 */
final class FornitoriGlobali
{
    /**
     * True per chi attinge ai fornitori di EasyLab: il Superadmin e il Gestore.
     * Non il Tecnico, che lavora anche lui per portafoglio ma non amministra
     * cataloghi.
     */
    public static function perChiGuarda(): bool
    {
        $utente = Auth::user();

        return $utente instanceof User && ($utente->isSuperadmin() || $utente->isGestore());
    }

    /**
     * Le sedi di EasyLab. Query builder e non Eloquent: il Gestore non ha
     * quelle sedi nel proprio perimetro, e qui servono solo gli id.
     *
     * @return list<int>
     */
    public static function sediDiPiattaforma(): array
    {
        return DB::table('unita_organizzativa')
            ->join('accounts', 'accounts.id', '=', 'unita_organizzativa.account_id')
            ->where('accounts.di_piattaforma', true)
            ->whereNull('accounts.deleted_at')
            ->where('unita_organizzativa.tipo', TipoUnitaOrganizzativa::Ente->value)
            ->whereNull('unita_organizzativa.deleted_at')
            ->pluck('unita_organizzativa.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * I fornitori di EasyLab che si possono portare nel catalogo di `$tenantId`:
     * i vivi, meno quelli che lì ci sono già (per copia, o con lo stesso nome).
     *
     * Vuota per chi non lavora per EasyLab, e dentro il catalogo di EasyLab
     * stessa: lì i globali sono già le righe dell'elenco.
     *
     * @return Builder<Fornitore>
     */
    public static function offribiliA(int $tenantId): Builder
    {
        $sedi = self::sediDiPiattaforma();
        $base = Fornitore::withoutGlobalScope(TenantScope::class);

        if (! self::perChiGuarda() || $sedi === [] || in_array($tenantId, $sedi, true)) {
            return $base->whereRaw('1 = 0');
        }

        return $base
            ->whereIn('fornitori.tenant_id', $sedi)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('fornitori as locali')
                ->where('locali.tenant_id', $tenantId)
                ->whereNull('locali.deleted_at')
                ->where(fn ($q) => $q
                    ->whereColumn('locali.globale_id', 'fornitori.id')
                    ->orWhereRaw('LOWER(locali.ragione_sociale) = LOWER(fornitori.ragione_sociale)')))
            ->orderBy('fornitori.ragione_sociale')
            ->orderBy('fornitori.id');
    }

    /**
     * Porta un fornitore di EasyLab nel catalogo di `$tenantId` e torna la riga
     * del cliente. Una copia già cestinata si ripristina invece di farne una
     * seconda.
     *
     * 🔴 L'id arriva dal browser: si rilegge da `offribiliA()`, quindi chi non
     * lavora per EasyLab, un fornitore che non è di EasyLab e un catalogo che
     * non è quello della macchina danno tutti un 404.
     *
     * ⚠️ Si copiano ragione sociale, email e telefono. **Non le note**: sono
     * appunti di EasyLab, e finirebbero sotto gli occhi del cliente.
     */
    public static function portaIn(int $globaleId, int $tenantId): Fornitore
    {
        return DB::transaction(function () use ($globaleId, $tenantId): Fornitore {
            // 🔴 Il catalogo deve essere di una sede che chi scrive ha in vista:
            // passa da `TenantScope`, quindi vale il portafoglio del Gestore e
            // i clienti gestiti del Superadmin. Il Gestore non ha un Ente
            // proprio, e `BelongsToTenant` non gli riscriverebbe niente: senza
            // questa riga scriverebbe nel catalogo di chiunque.
            UnitaOrganizzativa::query()
                ->where('tipo', TipoUnitaOrganizzativa::Ente->value)
                ->findOrFail($tenantId);

            $globale = self::offribiliA($tenantId)->whereKey($globaleId)->firstOrFail();

            $cestinata = Fornitore::onlyTrashed()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('globale_id', $globale->id)
                ->first();

            if ($cestinata !== null) {
                $cestinata->restore();

                return $cestinata;
            }

            $copia = new Fornitore([
                'tenant_id' => $tenantId,
                'ragione_sociale' => $globale->ragione_sociale,
                'email' => $globale->email,
                'telefono' => $globale->telefono,
            ]);
            $copia->globale_id = $globale->id;
            $copia->save();

            // 🔴 `BelongsToTenant` riscrive il tenant quando chi scrive non ha
            // titolo su quella sede (un cliente non gestito, per il Superadmin):
            // la copia nascerebbe nel catalogo sbagliato. Meglio non crearla.
            abort_unless((int) $copia->tenant_id === $tenantId, 403);

            return $copia;
        });
    }
}
