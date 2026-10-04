<?php

namespace App\Support\Provisioning;

use App\Support\Piani;

/**
 * I piani fra cui la cabina sceglie quando fa nascere un **cliente nuovo**
 * (🔗 ADR-045, ADR-012 il provisioning, ADR-002 il rapporto commerciale,
 * ADR-035 il listino).
 *
 * Fino al 3 Ott 2026 la modale non offriva nessuna scelta, e il docblock di
 * `ProvisionaCliente` diceva perché: «il select arriva col flusso di
 * sottoscrizione, non col listino». Il flusso ora c'è — il cliente attiva il
 * piano dalla propria pagina «Abbonamento» — e con lui arriva il select.
 *
 * ## 🔴 Due famiglie, che fanno due cose diverse
 *
 * - **Gratuiti** — il cliente nasce **su** quel piano, subito. Non c'è nessun
 *   pagamento da aspettare: è un piano che si concede, e a concederlo è una
 *   persona che risponde di quel contratto (ADR-002).
 * - **A pagamento** — il cliente nasce sul piano **predefinito** e il piano
 *   scelto resta una **proposta** (`accounts.piano_proposto`). Diventa il suo
 *   piano quando Stripe conferma l'incasso, mai prima: un account marcato
 *   pagante senza subscription è un cliente che risulta pagante e non paga.
 *
 * ⚠️ **A pagamento vuol dire comprabile adesso**: `Piani::vendibili()` (attivo,
 * non gratuito, con un price su Stripe) e un importo positivo. Proporre un
 * piano che il cliente poi non può pagare lo manderebbe su una pagina vuota.
 *
 * ⚠️ **Il predefinito c'è sempre, anche se archiviato.** È il piano con cui un
 * account nasce comunque (`Piani::predefinito()`), e toglierlo dalla lista non
 * impedirebbe la nascita: la renderebbe solo non dichiarata.
 */
final class PianiDiNascita
{
    /**
     * Le voci del select, nell'ordine in cui si leggono: il predefinito, gli
     * altri gratuiti, poi quelli a pagamento nell'ordine del listino.
     *
     * `predefinito` marca la voce che nella tendina vale «non scelto»: è un
     * flag e non «la prima della lista», perché se il predefinito mancasse dal
     * catalogo la prima sarebbe un altro piano — e sceglierla significherebbe
     * ottenere il predefinito credendo di aver scelto lei.
     *
     * @return list<array{codice: string, etichetta: string, gratuito: bool, predefinito: bool, importoCent: int|null}>
     */
    public static function opzioni(): array
    {
        $predefinito = Piani::predefinito();
        $gratuiti = [];
        $aPagamento = [];

        if (Piani::esiste($predefinito)) {
            $gratuiti[] = $predefinito;
        }

        foreach (Piani::offribili() as $codice) {
            if (Piani::eGratuito($codice) && $codice !== $predefinito) {
                $gratuiti[] = $codice;
            }
        }

        foreach (Piani::vendibili() as $codice) {
            if ((int) Piani::importoCorrenteCent($codice) > 0) {
                $aPagamento[] = $codice;
            }
        }

        return array_map(fn (string $codice) => [
            'codice' => $codice,
            'etichetta' => Piani::etichetta($codice),
            'gratuito' => Piani::eGratuito($codice),
            'predefinito' => $codice === $predefinito,
            'importoCent' => Piani::importoCorrenteCent($codice),
        ], [...$gratuiti, ...$aPagamento]);
    }

    /** @return list<string> */
    public static function codici(): array
    {
        return array_column(self::opzioni(), 'codice');
    }
}
