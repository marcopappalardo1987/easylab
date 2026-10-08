<?php

namespace App\Support\Billing;

use App\Models\Account;
use App\Support\Piani;
use Laravel\Cashier\Subscription;
use Stripe\Subscription as StripeSubscription;

/**
 * Quali piani un account può comprare da sé, e per quale strada (🔗 ADR-045,
 * ADR-032 l'Account intestatario, ADR-035 il listino, ADR-013 il lockout).
 *
 * Non è un terzo listino: è il listino visto da **un account**, cioè da qualcuno
 * che ha già un piano e forse un abbonamento. Due regole di prodotto, e
 * ciascuna è una riga di questo file.
 *
 * ## 🔴 1. Si compra solo ciò che è in vendita — mai un piano gratuito
 *
 * La base è `Piani::vendibili()`: offribili, a pagamento, con un price su
 * Stripe. Un piano gratuito non ha checkout da superare, quindi «comprarlo»
 * sarebbe assegnarselo da soli — e un cliente pagante che lo vedesse fra le
 * scelte avrebbe davanti la porta per smettere di pagare tenendosi l'accesso.
 * In più l'importo deve essere **positivo**: un piano a pagamento a 0 € non è
 * un acquisto, e il suo checkout si chiuderebbe «senza pagamento».
 *
 * ## 🔴 2. Non si torna indietro a un piano che costa meno
 *
 * Ogni offerta deve costare **almeno la soglia** dell'account, che è il più
 * alto di tre importi:
 *
 * - quanto costa oggi il **piano che ha** (0 se è gratuito);
 * - quanto **paga davvero** sull'ultimo abbonamento, che può essere un prezzo
 *   storico rimasto più alto del listino;
 * - quanto costa oggi il piano **di quell'abbonamento**, che può essere salito
 *   mentre lui è rimasto al prezzo vecchio.
 *
 * Con un abbonamento in corso il confronto è **stretto** e il proprio piano è
 * escluso: si offre solo un passo avanti. Senza, è **largo**: chi ha disdetto
 * può riattivare lo stesso piano che aveva, non uno più piccolo.
 *
 * ⚠️ **L'ultimo abbonamento conta anche se è chiuso**, ed è il buco che questa
 * riga chiude: alla disdetta il piano decade a quello predefinito (gratuito),
 * e guardando il solo piano attuale chi disdice e riattiva sceglierebbe il più
 * economico — cioè scenderebbe, con un giro in più.
 *
 * ## ⛔ Quando la soglia non si può stabilire, non si offre niente
 *
 * Un piano fuori catalogo o un price che il listino non conosce (creato a mano
 * in dashboard, per un accordo su misura) non hanno un importo che questo
 * repository sappia leggere senza chiamare Stripe. Indovinare vorrebbe dire
 * offrire un «upgrade» che magari costa meno: si chiude, e il cliente passa da
 * una persona. È lo stesso verso di `Piani`, i cui getter lanciano invece di
 * ripiegare su un default.
 *
 * ## ZERO rete
 *
 * Tutto da colonne locali — `accounts`, `subscriptions`, il listino in memo —
 * perché questa domanda si fa dentro il `render()` di `/abbonamento`, dove il
 * progetto vieta di chiamare Stripe.
 */
final class PianiAcquistabili
{
    /** Gli stati da cui una subscription di Stripe non torna più. */
    private const STATI_CHIUSI = [
        StripeSubscription::STATUS_CANCELED,
        StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
    ];

    /** Gli stati in cui cambiare prezzo non lascia un incasso a metà. */
    private const STATI_SANI = [
        StripeSubscription::STATUS_ACTIVE,
        StripeSubscription::STATUS_TRIALING,
    ];

    public static function per(Account $account): OffertaPiani
    {
        // L'account di piattaforma è EasyLab stessa: non compra da sé stessa.
        if ($account->di_piattaforma) {
            return new OffertaPiani(OffertaPiani::ATTIVAZIONE);
        }

        $abbonamenti = $account->subscriptions->sortByDesc('id')->values();

        /** @var Subscription|null $inCorso */
        $inCorso = $abbonamenti->first(
            fn (Subscription $s) => ! in_array($s->stripe_status, self::STATI_CHIUSI, true)
        );

        // 🔴 Il blocco disposto a mano non si riapre pagando (ADR-013): vendere
        // qui sarebbe incassare da chi resta chiuso fuori.
        if ($account->locked_at !== null) {
            return OffertaPiani::impedita(OffertaPiani::NON_DISPONIBILE, $inCorso);
        }

        // I due impedimenti che il cliente può rimuovere da solo vengono
        // PRIMA della soglia: a chi ha un insoluto su un prezzo fuori listino
        // va detto «salda», che è una cosa che può fare, non «contatta EasyLab».
        if ($inCorso !== null) {
            // `past_due`, `unpaid`, `incomplete`, `paused`: c'è un incasso che
            // Stripe sta ancora inseguendo, e cambiare prezzo ora lo
            // mescolerebbe a uno nuovo.
            if (! in_array($inCorso->stripe_status, self::STATI_SANI, true)) {
                return OffertaPiani::impedita(OffertaPiani::PAGAMENTO_IN_SOSPESO, $inCorso);
            }

            // ⚠️ Cashier, cambiando prezzo, manda `cancel_at_period_end: false`:
            // la disdetta verrebbe annullata come effetto collaterale di un
            // clic che parlava d'altro.
            if ($inCorso->ends_at !== null) {
                return OffertaPiani::impedita(OffertaPiani::DISDETTA_PROGRAMMATA, $inCorso);
            }
        }

        // ⚠️ Per la soglia conta l'ultimo abbonamento che il cliente ha
        // **avuto**: uno scaduto prima del primo incasso (`incomplete_expired`)
        // è un tentativo fallito, non un piano che ha pagato. Cashier ne
        // cancella la riga quando arriva il webhook; se non arriva, non deve
        // diventare il gradino sotto cui non si può più scendere.
        $ultimo = $inCorso ?? $abbonamenti->first(
            fn (Subscription $s) => $s->stripe_status !== StripeSubscription::STATUS_INCOMPLETE_EXPIRED
        );

        $soglia = self::soglia($account, $ultimo);

        if ($soglia === null) {
            return OffertaPiani::impedita(OffertaPiani::NON_DISPONIBILE, $inCorso);
        }

        $piani = [];

        foreach (Piani::vendibili() as $codice) {
            $importo = Piani::importoCorrenteCent($codice);

            if ($importo === null || $importo <= 0) {
                continue;
            }

            // Stretto sul cambio, largo sulla riattivazione. Il proprio piano
            // non serve escluderlo per nome: costa quanto la soglia o meno, e
            // il confronto stretto lo lascia fuori da sé.
            $passa = $inCorso !== null ? $importo > $soglia : $importo >= $soglia;

            if ($passa) {
                $piani[] = Piani::modello($codice);
            }
        }

        return new OffertaPiani(
            modo: $inCorso !== null ? OffertaPiani::CAMBIO : OffertaPiani::ATTIVAZIONE,
            piani: $piani,
            abbonamento: $inCorso,
        );
    }

    /**
     * L'importo sotto il quale un piano sarebbe un passo indietro, in
     * centesimi; `null` se non si può stabilire. Vedi il docblock di classe.
     */
    private static function soglia(Account $account, ?Subscription $ultimo): ?int
    {
        if (! Piani::esiste($account->piano)) {
            return null;
        }

        $soglia = self::costo($account->piano);

        if ($ultimo === null) {
            return $soglia;
        }

        $pagato = Piani::importoDelPrice($ultimo->stripe_price);
        $pianoPagato = Piani::perPrice($ultimo->stripe_price);

        if ($pagato === null || $pianoPagato === null) {
            return null;
        }

        return max($soglia, $pagato, self::costo($pianoPagato));
    }

    /**
     * Quanto costa oggi un piano: 0 se è gratuito, altrimenti l'importo del suo
     * price corrente — e il listino solo se un price ancora non c'è.
     */
    private static function costo(string $piano): int
    {
        if (Piani::eGratuito($piano)) {
            return 0;
        }

        return Piani::importoCorrenteCent($piano) ?? Piani::prezzoMensileCent($piano);
    }
}
