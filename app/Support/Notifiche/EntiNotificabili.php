<?php

namespace App\Support\Notifiche;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gli Enti a cui uno scheduler di notifiche può parlare (🔗 ADR-011 · ADR-013).
 *
 * Estratta il 27 Ago 2026 da `NotificaScadenze::handle()`, dove viveva inline,
 * quando `easylab:notifica-obsolescenza` ha avuto bisogno della stessa identica
 * lista. Due copie della stessa query sarebbero state due posti liberi di
 * divergere sul punto più delicato che hanno in comune: **chi resta fuori**.
 *
 * **Gli Enti degli account in lockout restano fuori** (🔗 ADR-013): il blocco
 * per insoluto è *totale*, e mandare a un cliente a cui abbiamo chiuso la porta
 * un promemoria operativo — con un link che lo sbatte su `/bloccato` — la
 * contraddirebbe due volte, dicendogli «fai la manutenzione» e «non puoi
 * entrare» nello stesso minuto. Gli avvisi non si perdono: `avvisi_scadenza`
 * non viene scritto per loro, quindi allo sblocco ciò che è ancora aperto torna
 * a essere una novità.
 *
 * `whereDoesntHave` e **NON** `whereNotIn(...)`: su un Ente con `account_id`
 * NULL — legittimo, fail-open come nel middleware — il `NOT IN` darebbe UNKNOWN
 * e lo escluderebbe in silenzio.
 *
 * ⚠️ **Query cross-tenant SENZA `VistaPiattaforma` (S6), e deve restare così**:
 * quella porta chiede `Gate::authorize`, che senza utente nega sempre — farla
 * passare di qui manderebbe lo scheduler notturno in `AuthorizationException`, e
 * il sintomo sarebbe un digest che non arriva. In console il confine non c'è
 * già: `TenantScope` non si applica quando manca un utente, quindi la query
 * nuda è la forma corretta.
 *
 * ⚠️ **Il `select` è ALLARGATO rispetto alla stesura originale (`['id','nome']`),
 * ed è la parte che va letta prima di stringerlo di nuovo.** Senza
 * `soglia_obsolescenza_anni` `AvvisiObsolescenza` leggerebbe `null`, ricadrebbe
 * sul default di 10 anni e sbaglierebbe la linea per ogni Ente che l'ha
 * personalizzata — senza errori e senza test rossi, perché in fixture la soglia
 * è quasi sempre 10. Senza `account_id`, `$ente->account` tornerebbe null e il
 * controllo di lockout *dentro* il servizio passerebbe fail-open, cioè
 * l'opposto di ciò che questa classe garantisce qui sopra.
 */
final class EntiNotificabili
{
    /** @return Collection<int, UnitaOrganizzativa> */
    public static function tutti(): Collection
    {
        return UnitaOrganizzativa::query()
            ->where('tipo', TipoUnitaOrganizzativa::Ente->value)
            ->whereDoesntHave('account', fn ($query) => $query->where('is_locked', true))
            ->orderBy('id')
            ->get(['id', 'nome', 'tipo', 'tenant_id', 'account_id', 'soglia_obsolescenza_anni']);
    }
}
