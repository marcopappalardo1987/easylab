<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Support\Piani;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * Attiva su Stripe l'abbonamento di un Account (ADR-002, ADR-032).
 *
 * È la leva **da console** con cui un piano a pagamento comincia: crea il
 * customer, gli attacca un metodo di pagamento e apre la subscription, poi
 * scrive il piano sull'account.
 *
 * ⚠️ *Non è più l'unico percorso.* Alla nascita lo era; poi sono arrivati il
 * self-signup pubblico (ADR-012, ADR-039) e, dal 3 Ott 2026, l'attivazione e il
 * cambio di piano dalla pagina «Abbonamento» del cliente (ADR-045). Questo
 * comando resta per ciò che una persona decide al posto del cliente: una
 * prova, un price su misura, un rientro concordato.
 *
 * Sta in console e non in una UI per la stessa ragione per cui ci sta
 * `easylab:lockout`: l'amministrazione degli account è cross-tenant per natura
 * e, finché la Dashboard Superadmin di S6 non esiste, la sede è il terminale
 * (ADR-018/032).
 *
 * ⚠️ **Crea oggetti di fatturazione veri.** Da qui `ConfirmableTrait`: in
 * produzione il comando chiede conferma, e `--force` serve a saltarla
 * consapevolmente. Un addebito non è un gesto da lanciare per riflesso.
 *
 * **Tutte le guardie stanno prima di qualunque chiamata di rete**, come la
 * risoluzione dell'account in `easylab:provision-tenant`: chi viene rifiutato
 * non deve lasciare dietro di sé un customer a metà su Stripe, che poi qualcuno
 * dovrà ripulire a mano.
 *
 * **Il percorso felice non ha un test di suite**, ed è dichiarato: mockare
 * `StripeClient` produrrebbe un test che verifica il proprio mock. Si verifica
 * su staging, con le chiavi test — la procedura è in `Setup Repository e
 * Ambienti.md`.
 */
class AbbonaAccount extends Command
{
    use ConfirmableTrait;

    protected $signature = 'easylab:abbona
        {account : ID dell\'account}
        {--piano=saas : Codice del piano (vedi /piattaforma/piani)}
        {--price= : Price id Stripe, se diverso da quello del piano}
        {--trial-giorni= : Giorni di prova prima del primo addebito}
        {--payment-method=pm_card_visa : Metodo di pagamento (default: carta di test)}
        {--force : Salta la conferma in produzione}';

    protected $description = 'Attiva su Stripe l\'abbonamento di un Account (ADR-032)';

    public function handle(): int
    {
        $account = Account::find($this->argument('account'));

        if ($account === null) {
            $this->error("Nessun account con id {$this->argument('account')}.");

            return self::FAILURE;
        }

        $piano = $this->option('piano');

        if (! Piani::esiste($piano)) {
            $this->error("Piano «{$piano}» non a catalogo. Piani validi: ".implode(', ', Piani::codici()).'.');

            return self::FAILURE;
        }

        // Il Free non è un piano «senza prezzo ancora deciso»: è un piano che
        // per definizione non passa da Stripe (ADR-002 — omaggiato a fronte di
        // un contratto di manutenzione fisico, fatturato fuori dal software).
        if (Piani::eGratuito($piano)) {
            $this->error('Il piano «'.Piani::etichetta($piano).'» non ha un abbonamento Stripe: è la sua definizione — nessun customer, nessuna subscription.');
            $this->line('Un account Free si crea con easylab:provision-tenant, oppure si riporta a Free con una disdetta su Stripe.');

            return self::FAILURE;
        }

        $price = $this->option('price') ?: Piani::stripePrice($piano);

        if (! $price) {
            // ⚠️ Il messaggio nominava `STRIPE_PRICE_SAAS`, ed è invecchiato il
            // 27 Ago 2026: dal listino a database (ADR-035) il price id non
            // viene più dall'ambiente ma dalla riga `corrente` di
            // `prezzi_piano`, che si crea sincronizzando il piano da
            // /piattaforma/piani. La variabile resta il **bootstrap** letto
            // dalla migration di backfill, quindi mandare qui chi legge
            // significherebbe mandarlo a modificare un file che non produce più
            // alcun effetto.
            $this->error("Nessun price id per il piano «{$piano}»: il piano non è ancora sincronizzato con Stripe.");
            $this->line('Si rimedia da /piattaforma/piani — «Sincronizza», oppure «Aggancia un price esistente» se il Price su Stripe c\'è già. In alternativa si passa --price= a mano.');

            return self::FAILURE;
        }

        if (! config('cashier.secret')) {
            $this->error('STRIPE_SECRET non configurato.');
            // La forma esatta in cui questo si presenta su Laravel Cloud:
            // `optimize` gira in BUILD, quindi una variabile aggiunta dopo la
            // build resta invisibile e ogni env() torna null. Lo diciamo qui
            // perché è l'errore che il progetto ha già pagato una volta.
            $this->line('Su Laravel Cloud la config è cachata in build: verificare con `php artisan config:show cashier` e, se è null, ricostruire.');

            return self::FAILURE;
        }

        // ⚠️ La guardia è su una subscription **non ancora chiusa**, e le due
        // metà di questa condizione sono state pagate entrambe.
        //
        // NON `subscribed()`: quello passa da `Subscription::valid()`, che coi
        // default di Cashier (`$deactivatePastDue`, `$deactivateIncomplete`)
        // considera non valida una subscription in `past_due` o `incomplete` —
        // cioè lascerebbe passare proprio l'account con un insoluto in corso,
        // che è quando qualcuno mette le mani su questo comando, aprendogli una
        // SECONDA subscription fatturata.
        //
        // Ma nemmeno `exists()` nudo, che era la prima stesura: su staging ha
        // rifiutato di riabbonare un cliente la cui unica subscription era
        // `canceled`. Una subscription chiusa non fattura nulla, quindi non c'è
        // niente da proteggere — e chi disdice e torna è un caso normale, non
        // un errore da bloccare.
        $viva = $account->subscriptions()
            ->where('stripe_status', '!=', StripeSubscription::STATUS_CANCELED)
            ->latest('id')
            ->first();

        if ($viva !== null) {
            $this->error("L'account {$account->id} ha già una subscription attiva ({$viva->stripe_id}, stato «{$viva->stripe_status}»).");
            $this->line('I cambi di piano e le disdette si fanno dalla dashboard Stripe: da lì il webhook riallinea piano e lockout.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $metodo = $this->option('payment-method');
        $trial = $this->option('trial-giorni');

        try {
            $account->createOrGetStripeCustomer();
            $account->updateDefaultPaymentMethod($metodo);

            $subscription = $account->newSubscription('default', $price);

            if ($trial !== null) {
                $subscription->trialDays((int) $trial);
            }

            $subscription->create($metodo);
        } catch (IncompletePayment $e) {
            $this->error('Il pagamento richiede una conferma che questo comando non può dare (3-D Secure).');
            $this->line('⚠️ Customer e subscription possono esistere comunque su Stripe: verificare in dashboard prima di ritentare.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error("Stripe ha rifiutato l'operazione: {$e->getMessage()}");

            return self::FAILURE;
        }

        $account->cambiaPiano($piano);
        $account->refresh();

        // Stessa forma di `easylab:lockout`: il comando stampa la PORTATA del
        // gesto, non solo «fatto». Chi lo lancia deve poter verificare a colpo
        // d'occhio di aver abbonato l'account giusto.
        $this->info("Account {$account->id} — {$account->ragione_sociale}");
        $this->line("  customer Stripe : {$account->stripe_id}");
        $this->line('  subscription    : '.$account->subscription('default')?->stripe_id.' ('.$account->subscription('default')?->stripe_status.')');
        $this->line('  piano           : '.Piani::etichetta($account->piano));
        $this->line('  Enti            : '.$account->enti()->count().' su '.(Piani::maxEnti($account->piano) ?? '∞'));

        return self::SUCCESS;
    }
}
