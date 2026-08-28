<?php

namespace App\Support\Registrazione;

use App\Models\Account;
use App\Models\Registrazione;
use App\Notifications\BenvenutoRegistrazione;
use App\Support\AuditLog;
use App\Support\Piani;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Provisioning\ProvisioningRifiutato;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 Il cuore del self-signup: **l'account nasce solo se il pagamento è andato
 * a buon fine** (🔗 ADR-012 il provisioning, ADR-032 l'Account intestatario,
 * ADR-002 il rapporto commerciale).
 *
 * ## Perché una classe e non due metodi nei due chiamanti
 *
 * I chiamanti sono **due** e arrivano da due mondi diversi: il ritorno del
 * browser da Stripe (`registrazione.completata`, che legge la sessione via
 * `PortaleCheckout`) e il webhook `checkout.session.completed` (che legge lo
 * stesso oggetto dal payload firmato, senza toccare la rete). Il secondo esiste
 * perché il primo **non è garantito**: chi chiude la scheda dopo aver pagato
 * non torna mai, e senza il webhook avrebbe pagato per niente. Due strade per
 * un unico gesto vogliono una sola implementazione, o al primo cambiamento
 * diventano due gesti diversi — e uno dei due sarebbe quello che nessuno
 * guarda.
 *
 * ## L'ordine dei gesti, che non è arbitrario
 *
 * 1. **niente esito pagato → niente**, e prima di qualunque lettura: una
 *    sessione `open` o `unpaid` non deve nemmeno aprire una transazione;
 * 2. **rilettura della riga sotto lock, DENTRO la transazione**: è qui che si
 *    decide se il gesto è già avvenuto;
 * 3. **`completata_at !== null` → no-op**, che è l'idempotenza vera;
 * 4. **piano ancora a listino** — ricontrollato, vedi `PianiRegistrabili`;
 * 5. **`ProvisionaEnte`**, che è la transazione «account + Ente + Admin»
 *    esistente e non va riscritta;
 * 6. `stripe_id`, piano, specchio della subscription, timbro e **azzeramento
 *    del segreto**;
 * 7. il benvenuto **fuori** dalla transazione, come l'invito del provisioning:
 *    una mail spedita per una transazione poi rollbackata manda qualcuno su un
 *    prodotto che non esiste.
 *
 * ## ⚠️ `lockForUpdate()` è un NO-OP su SQLite, dove gira la suite
 *
 * Quindi l'idempotenza **non** si regge su di lui: si regge sul controllo di
 * `completata_at` dentro la transazione e sull'indice UNIQUE di
 * `registrazioni.stripe_session_id`. Il lock protegge in produzione (Postgres),
 * dove il ritorno del browser e il webhook possono davvero arrivare nello
 * stesso istante. Non esiste un test che «dimostra» il lock: dimostrerebbe
 * SQLite.
 *
 * ## 🔴 `esigiAccountNuovo: true`, che è la guardia più importante di questo file
 *
 * Senza, un'email che appartiene già a un amministratore di un altro account —
 * **compreso l'account di piattaforma** — farebbe atterrare l'Ente appena
 * pagato sul contratto di un terzo. È il difetto che quel flag esiste per
 * chiudere, ed è anche la rete naturale contro la doppia esecuzione: la seconda
 * passata trova l'utente già amministratore e lancia
 * `ProvisioningRifiutato::GIA_AMMINISTRA`. (Non è però l'idempotenza: quella
 * risponde *prima*, al punto 3, senza far nascere un'eccezione per un caso
 * normale.)
 *
 * ## ⚠️ La subscription si rispecchia a mano, e non è ridondanza
 *
 * Stripe non garantisce l'**ordine** di consegna dei webhook:
 * `customer.subscription.created` può arrivare **prima** che l'Account esista,
 * e `StripeWebhookController::accountDa()` risponde allora 200 loggando
 * «customer sconosciuto» — corretto per non far ritentare Stripe per giorni, ma
 * la riga locale `subscriptions` non verrebbe scritta **mai più** e nessuno se
 * ne accorgerebbe: `/abbonamento` mostrerebbe un cliente pagante senza
 * abbonamento. L'`updateOrCreate` su `stripe_id` è idempotente: se il webhook
 * l'ha già scritta, non duplica.
 *
 * ## ⛔ Nessun auto-login
 *
 * Chi ha pagato non viene autenticato da qui. È la stessa scelta di
 * `ImpostaPasswordInvito`: l'Admin è un ruolo `two_factor_required`, e la
 * catena giusta è pagamento → login → 2FA. Autenticare qui salterebbe il
 * passaggio in cui quella catena si chiude — e questo metodo gira **anche** dal
 * webhook, dove non c'è nessuna sessione da autenticare.
 */
final class CompletaRegistrazione
{
    /**
     * Fa nascere l'account, o non fa niente.
     *
     * @return Account|null l'account (anche se esisteva già da una passata
     *                      precedente), oppure `null` se il pagamento non è
     *                      avvenuto o la riga non è completabile
     *
     * @throws RegistrazioneRifiutata prima di qualunque scrittura di dominio
     */
    public function esegui(Registrazione $registrazione, EsitoCheckout $esito): ?Account
    {
        // 🔴 La domanda che decide tutto, e la si fa per prima: una sessione
        // `open` è un checkout aperto e mai concluso, una `complete` con
        // `payment_status` diverso da `paid` è un pagamento non incassato.
        // Vedi `EsitoCheckout`, dove le due condizioni vivono insieme.
        if (! $esito->pagato) {
            return null;
        }

        [$account, $appenaCompletata] = DB::transaction(function () use ($registrazione, $esito) {
            // ⚠️ Riletta **dal database e sotto lock**, non usata com'è
            // arrivata: fra il momento in cui il chiamante l'ha caricata e
            // questo istante può essere passato l'altro chiamante.
            $riga = Registrazione::query()->whereKey($registrazione->getKey())->lockForUpdate()->first();

            if ($riga === null) {
                return [null, false];
            }

            // L'idempotenza vera: il gesto è già avvenuto, e ripeterlo
            // produrrebbe un secondo Account per un solo pagamento.
            if ($riga->completata()) {
                return [$riga->account, false];
            }

            if (! Piani::esiste($riga->piano)) {
                throw new RegistrazioneRifiutata(
                    "La registrazione {$riga->getKey()} è su un piano che non è più a listino («{$riga->piano}»): ".
                    'nessun account può nascere su di esso.',
                    RegistrazioneRifiutata::PIANO_FUORI_CATALOGO,
                );
            }

            try {
                $esitoProvisioning = (new ProvisionaEnte(
                    nome: $riga->nome_ente,
                    adminEmail: $riga->email,
                    adminName: $riga->nome_referente,
                    // Nessuna password in chiaro: quella scelta al modulo vive
                    // già hashata, e rihasharla la distruggerebbe.
                    passwordEsplicita: null,
                    accountId: null,
                    esigiAccountNuovo: true,
                    passwordHash: $riga->password_hash,
                ))->esegui();
            } catch (ProvisioningRifiutato $e) {
                // Si **traduce** invece di propagare: i due chiamanti di questo
                // file sanno raccontare un `RegistrazioneRifiutata` (una pagina
                // rassicurante e un 200 muto con una riga di log), e non devono
                // conoscere il vocabolario del provisioning. Il messaggio
                // originale sopravvive, perché è quello che finisce nel log.
                throw new RegistrazioneRifiutata($e->getMessage(), RegistrazioneRifiutata::PROVISIONING_RIFIUTATO);
            }

            $account = $esitoProvisioning->account;

            // `stripe_id` è fuori da `$fillable` di Account, come `piano` e le
            // colonne di lockout: si scrive per nome. Il customer lo ha creato
            // Stripe al pagamento — `Checkout::guest()` apre la sessione senza
            // owner, perché il Billable non esisteva ancora.
            if ($esito->customerId !== null) {
                $account->forceFill(['stripe_id' => $esito->customerId])->save();
            }

            // ⚠️ **DOPO `esegui()` e non prima.** `verificaLimiteDiPiano()`
            // gira su un `new Account` (piano `free`, `max_enti` 1) perché
            // l'account non esiste ancora: con zero Enti passa comunque, ma
            // l'ordine resta quello giusto — il piano è ciò che il cliente ha
            // appena pagato, non una condizione del provisioning.
            //
            // 🔴 E il piano viene da `registrazioni.piano`, **mai** da
            // `$esito->piano`: quello arriva dai metadata, cioè da un payload
            // che ha viaggiato su un dominio di terzi.
            $account->cambiaPiano($riga->piano);

            $this->rispecchiaSubscription($account, $riga, $esito);

            $riga->forceFill([
                'completata_at' => now(),
                'account_id' => $account->getKey(),
                // 🔴 Il segreto muore qui, nella stessa transazione in cui
                // `users.password` nasce: conservarlo sarebbe una seconda copia
                // di una credenziale in una tabella che nessuna Policy protegge.
                'password_hash' => null,
            ])->save();

            // ⛔ La riga di audit si scrive **da qui** e non dal model
            // (`SoggettiAuditTest` legge il sorgente dei model cercando
            // `activity(`), e il soggetto è l'**Account** — che è già mappato
            // fra i soggetti mostrabili. Senza `causedBy()`: non c'è nessuna
            // persona identificata dietro questo gesto, e attribuirlo a chi
            // passava di lì sarebbe un dato falso in un registro di sicurezza.
            activity(AuditLog::NAME)
                ->performedOn($account)
                ->withProperties([
                    'registrazione_id' => $riga->getKey(),
                    'piano' => $riga->piano,
                    'stripe_session_id' => $riga->stripe_session_id,
                    'stripe_customer_id' => $esito->customerId,
                ])
                ->log(self::DESCRIZIONE_AUDIT);

            return [$account, true];
        });

        if ($appenaCompletata && $account !== null) {
            $this->dailBenvenuto($registrazione->fresh() ?? $registrazione);
        }

        return $account;
    }

    /**
     * La descrizione della riga di audit, come costante: i test contano
     * **queste** righe e non tutte quelle dell'account (il trait
     * `AuditsDomainWrites` ne scrive già una, «Creazione account»), e un test
     * che riscrive la stringa a mano resterebbe verde a qualunque cosa la si
     * cambiasse.
     */
    public const DESCRIZIONE_AUDIT = 'Account creato da registrazione pubblica';

    /**
     * Lo specchio locale della subscription, per il caso in cui il webhook che
     * la scriverebbe sia arrivato **prima** dell'account. Vedi il docblock di
     * classe: senza, `/abbonamento` mostrerebbe un cliente pagante senza
     * abbonamento, per sempre e in silenzio.
     *
     * ⚠️ `updateOrCreate` sullo `stripe_id`, che è UNIQUE: una seconda passata
     * — o il webhook arrivato nel frattempo — non duplica.
     */
    private function rispecchiaSubscription(Account $account, Registrazione $riga, EsitoCheckout $esito): void
    {
        if ($esito->subscriptionId === null) {
            return;
        }

        $account->subscriptions()->updateOrCreate(
            ['stripe_id' => $esito->subscriptionId],
            [
                'type' => 'default',
                'stripe_status' => 'active',
                // Il price del **nostro** listino, non uno letto dal payload:
                // è la stessa disciplina del piano. Se il webhook della
                // subscription arriverà con un price diverso, sarà lui a
                // correggere questa riga — è il suo mestiere.
                'stripe_price' => Piani::stripePrice($riga->piano),
                'quantity' => 1,
            ],
        );
    }

    /**
     * Il benvenuto, **fuori dalla transazione** e una volta sola.
     *
     * ⚠️ Il chiamante non può dire «inviata»: la notifica è `ShouldQueue`, come
     * `InvitoUtente`, quindi ciò che si sa è che è **in consegna**. Un
     * fallimento lo racconta il suo `failed()`, che scrive destinatario e
     * motivo sul registro di audit e su `Log::error`.
     *
     * ⚠️ **`rescue()`**: l'account esiste ed è pagato. Un SMTP giù non deve
     * trasformare un pagamento riuscito in una pagina di errore per chi ha
     * appena messo la carta — e con la coda attiva questo `try` non vedrebbe
     * comunque il guasto vero.
     */
    private function dailBenvenuto(Registrazione $riga): void
    {
        rescue(fn () => $riga->notify(new BenvenutoRegistrazione(
            enteNome: $riga->nome_ente,
            destinatario: $riga->email,
        )));
    }
}
