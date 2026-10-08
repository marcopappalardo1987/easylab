<?php

namespace App\Support\Notifiche;

use App\Models\User;
use App\Support\Tenancy\AccessibleNodes;

/**
 * Chi, dentro un Ente, riceve gli avvisi programmati — e **fin dove arriva il
 * suo sguardo** (decisione di prodotto del 18/25 Ago 2026, 🔗 ADR-011 · ADR-006).
 *
 * Estratta il 27 Ago 2026 da `NotificaScadenze::destinatari()` perché
 * l'obsolescenza sceglie i propri destinatari **con lo stesso identico
 * criterio**: Admin e Tenant tutto l'Ente, Responsabile Reparto il proprio
 * sotto-albero, chiunque altro niente. Riscriverlo nel comando nuovo avrebbe
 * significato tenere in due posti la regola che decide *cosa esce
 * dall'applicazione* — e il giorno in cui i due divergessero, uno dei due canali
 * sarebbe quello che scavalca lo scope.
 *
 * 🔴 **La risposta è «chi» + «quali nodi», non «quali righe».** Il filtro delle
 * righe resta al chiamante, perché le righe dei due comandi sono di tipi
 * diversi (`RigaAvviso` e `RigaObsolescenza`) e perché il digest ha in più un
 * filtro suo — le garanzie ricambio nascoste al Tenant (ADR-029) — che
 * l'obsolescenza non ha e non deve ereditare per distrazione. `nodi === null`
 * significa **tutto l'Ente**; una lista significa «solo queste unità»; la lista
 * vuota significa «niente», ed è il fail-safe del Responsabile senza
 * assegnazioni (`AccessibleNodes::forUser()` torna `[]`, mai `null`, per lui).
 *
 * ⚠️ **L'ordine dei rami è semantico e non si riordina.** Admin viene per primo:
 * un Admin che fosse *anche* Responsabile riceve tutto l'Ente, non il proprio
 * reparto. Il `?? []` su `AccessibleNodes::forUser()` copre il caso
 * teoricamente impossibile in cui un utente `isDepartmentScoped()` non abbia
 * nodi risolvibili, e la direzione della salvaguardia è «non vede nulla».
 *
 * ⚠️ **Il Tecnico esterno NON è qui**, e la sua assenza è voluta: non ha
 * `tenant_id` (🔗 ADR-030), quindi una query filtrata sul tenant non lo
 * troverebbe mai. Entra fra i destinatari del digest per un canale suo — gli
 * interventi che gli sono assegnati — che vive in `NotificaScadenze`. Per
 * l'obsolescenza non esiste affatto: una macchina vecchia non ha un
 * assegnatario.
 */
final class DestinatariEnte
{
    /**
     * True se questa persona è fra chi riceve le email del proprio Ente: la
     * stessa domanda di `perEnte()`, posta a una persona sola.
     *
     * Serve alla pagina delle preferenze (🔗 ADR-047), che mostra un
     * interruttore solo a chi quell'email potrebbe davvero riceverla — e lo
     * chiede qui, invece di riscrivere l'elenco dei ruoli in un secondo posto.
     */
    public static function riguarda(User $utente): bool
    {
        return $utente->hasRole('Admin') || $utente->hasRole(User::TENANT_ROLE) || $utente->isDepartmentScoped();
    }

    /**
     * @return list<array{utente: User, nodi: list<int>|null}>
     */
    public static function perEnte(int $tenantId): array
    {
        $destinatari = [];

        foreach (User::query()->where('tenant_id', $tenantId)->get() as $utente) {
            if ($utente->hasRole('Admin') || $utente->hasRole(User::TENANT_ROLE)) {
                $destinatari[] = ['utente' => $utente, 'nodi' => null];

                continue;
            }

            if ($utente->isDepartmentScoped()) {
                $destinatari[] = ['utente' => $utente, 'nodi' => AccessibleNodes::forUser($utente) ?? []];
            }
        }

        return $destinatari;
    }
}
