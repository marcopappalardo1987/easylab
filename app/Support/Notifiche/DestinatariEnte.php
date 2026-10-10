<?php

namespace App\Support\Notifiche;

use App\Models\Strumento;
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
     * Chi riceve un'email che parla di **questa macchina**, meno chi sta
     * agendo: è la domanda delle email che seguono un gesto (🔗 ADR-047), e dal
     * 10 Ott 2026 ha due risposte (🔗 ADR-054).
     *
     * **Le persone del cliente** che la seguono. `nodi === null` è «tutto
     * l'Ente»; una lista è il sotto-albero del Responsabile, e la macchina deve
     * starci dentro — o l'email diventerebbe il canale che scavalca
     * `DepartmentScope`.
     *
     * **Il referente della macchina**, se la scheda ne porta l'indirizzo: una
     * casella, non una persona di Easy Lab. Si aggiunge, non sostituisce
     * nessuno.
     *
     * 🔴 **Mai due volte alla stessa casella, e mai a chi ha compiuto il
     * gesto.** Il referente il cui indirizzo è quello di una persona del
     * cliente è *quella persona*: riceve l'email una volta sola, da persona, e
     * quindi con le proprie preferenze — anche se è un Responsabile e la
     * macchina sta fuori dai suoi reparti. È il solo caso in cui il
     * sotto-albero non decide, e lo decide chi ha scritto quell'indirizzo sulla
     * scheda: indicare qualcuno come referente di una macchina **è** dirgli che
     * la segue.
     *
     * @param  ?User  $attore  chi compie il gesto: lo sa già.
     * @return array{persone: list<User>, referente: ?Referente}
     */
    public static function dellaMacchina(Strumento $strumento, ?User $attore = null): array
    {
        $referente = Referente::di($strumento);
        $unitaId = (int) $strumento->unita_organizzativa_id;
        $persone = [];
        $ePersonaDelCliente = false;

        foreach (self::perEnte((int) $strumento->tenant_id) as ['utente' => $utente, 'nodi' => $nodi]) {
            $eIlReferente = $referente !== null && $referente->corrispondeA($utente->email);
            $ePersonaDelCliente = $ePersonaDelCliente || $eIlReferente;

            if ($utente->id === $attore?->id) {
                continue;
            }

            if ($nodi !== null && ! in_array($unitaId, $nodi, true) && ! $eIlReferente) {
                continue;
            }

            $persone[] = $utente;
        }

        if ($ePersonaDelCliente || ($attore !== null && $referente?->corrispondeA($attore->email))) {
            $referente = null;
        }

        return ['persone' => $persone, 'referente' => $referente];
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
