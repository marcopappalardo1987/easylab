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
 * 4. **casella verificata**, o `NON_VERIFICATA`: la stessa guardia di
 *    `RegistrazionePubblica::versoStripe()`, rifatta qui perché il **webhook**
 *    non passa da là;
 * 5. **piano ancora a catalogo** — `Piani::esiste()`, e non
 *    `PianiRegistrabili`: vedi il paragrafo qui sotto, la differenza è
 *    deliberata;
 * 6. **`ProvisionaEnte`**, che è la transazione «account + Ente + Admin»
 *    esistente e non va riscritta;
 * 7. `stripe_id`, piano, specchio della subscription, timbro e **azzeramento
 *    del segreto**;
 * 8. il benvenuto **fuori** dalla transazione, come l'invito del provisioning:
 *    una mail spedita per una transazione poi rollbackata manda qualcuno su un
 *    prodotto che non esiste;
 * 9. e se un rifiuto arriva **dopo l'incasso**, la sua traccia — fuori dalla
 *    transazione, o il rollback se la porterebbe via. Vedi
 *    `registraIlRifiuto()`.
 *
 * ## 🔴 Perché qui la domanda sul piano è più larga che al checkout
 *
 * `versoStripe()` chiede `PianiRegistrabili::accetta()`, che esclude gli
 * archiviati; qui si chiede soltanto `Piani::esiste()`, che li comprende. **Non
 * è una svista, ed è la differenza fra i due lati del pagamento.**
 *
 * Prima dell'incasso il fail-closed non costa niente a nessuno: il cliente
 * torna alla pagina e sceglie un altro piano. Dopo, rifiutare significherebbe
 * **aver incassato senza consegnare** — e per un gesto compiuto da noi
 * (archiviare un piano da `/piattaforma/piani`) mentre il cliente era sulla
 * pagina di Stripe. Un piano archiviato resta a catalogo per chi ci sta sopra
 * (ADR-035): l'account può nascervi, ed è esattamente ciò che accade a chi
 * l'ha appena pagato. Ciò che qui si rifiuta è il piano **sparito**, cioè un
 * dato ormai corrotto: `Piani::maxEnti()` lancerebbe al primo Ente aggiunto.
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

        try {
            [$account, $appenaCompletata, $avevaPassword] = $this->nasci($registrazione, $esito);
        } catch (RegistrazioneRifiutata $e) {
            // ⛔ **Fuori dalla transazione, o non resterebbe niente**: la
            // scrittura della traccia gira dopo il rollback, altrimenti verrebbe
            // annullata insieme al gesto che sta raccontando.
            $this->registraIlRifiuto($registrazione, $e);

            throw $e;
        }

        // ⚠️ **Il benvenuto solo a chi una password ce l'ha** (ADR-039). Chi
        // arriva da un Payment Link non l'ha scelta: riceve l'**invito**, che è
        // la sola strada con cui entra. Mandargli anche questo messaggio
        // significherebbe due email che si contraddicono — e quella che dice
        // «accedi con la password che hai scelto» sarebbe la più ufficiale delle
        // due, e falsa.
        if ($appenaCompletata && $account !== null && $avevaPassword) {
            $this->dailBenvenuto($registrazione->fresh() ?? $registrazione);
        }

        return $account;
    }

    /**
     * La transazione vera e propria: rilettura sotto lock, guardie, nascita.
     *
     * ⚠️ Il terzo elemento è «la riga aveva una password», letto **sotto lock e
     * prima** di azzerarla: dopo il completamento `password_hash` è `null` per
     * entrambe le strade, quindi il chiamante non potrebbe più distinguerle — ed
     * è ciò che decide se mandare il benvenuto o lasciar parlare l'invito.
     *
     * @return array{0: Account|null, 1: bool, 2: bool}
     */
    private function nasci(Registrazione $registrazione, EsitoCheckout $esito): array
    {
        return DB::transaction(function () use ($registrazione, $esito) {
            // ⚠️ Riletta **dal database e sotto lock**, non usata com'è
            // arrivata: fra il momento in cui il chiamante l'ha caricata e
            // questo istante può essere passato l'altro chiamante.
            $riga = Registrazione::query()->whereKey($registrazione->getKey())->lockForUpdate()->first();

            if ($riga === null) {
                return [null, false, false];
            }

            // L'idempotenza vera: il gesto è già avvenuto, e ripeterlo
            // produrrebbe un secondo Account per un solo pagamento.
            if ($riga->completata()) {
                return [$riga->account, false, false];
            }

            // 🔴 **La verifica della casella, ricontrollata anche qui.** In
            // `RegistrazionePubblica::versoStripe()` questa guardia esiste già,
            // ma quella è la strada del **browser**: il webhook chiama questa
            // azione direttamente e non guarda niente, quindi senza questa riga
            // la regola varrebbe per una delle due consegne dello stesso
            // pagamento. Il caso non è teorico — il modulo pubblico riscrive una
            // riga pendente, e una riga riscritta è una riga da riconfermare.
            //
            // ⚠️ **DENTRO la transazione e sulla riga RILETTA**, non su quella
            // arrivata dal chiamante: fra il caricamento e questo istante può
            // essere passato l'altro chiamante.
            //
            // 🔴 **`password_hash !== null` è la guardia, non un'aggiunta** — e
            // dal 5 Set 2026 (ADR-039) è la sua forma esatta invece che una
            // conseguenza. Ciò che questa riga difende è: *una password già
            // scelta non si onora su una casella mai confermata*, perché nel
            // modulo la password si sceglie **prima** della verifica e un terzo
            // che riscrive una riga pendente si sostituirebbe al legittimo.
            //
            // Nel Payment Link (ADR-039) una password non c'è: l'Admin nasce
            // **invitato**, e l'unica via dentro è il link firmato spedito a
            // quella casella — accettarlo *è* la verifica. Chiedere lì una
            // verifica che non è ancora avvenuta rifiuterebbe un pagamento già
            // incassato, cioè il guasto che tutto questo file esiste per evitare.
            //
            // ⛔ **Fail-closed dove serve**: ogni riga del modulo ha un
            // `password_hash` fino al completamento (dove viene azzerato, ma da
            // lì in poi `completata()` ha già corto-circuitato sopra). E una
            // colonna «origine» sarebbe stata peggio: legherebbe la guardia a
            // *chi dice* di aver creato la riga invece che a *cosa andrebbe
            // storto*, e la spegnerebbe per chiunque sappia scrivere quel
            // valore.
            $avevaPassword = $riga->password_hash !== null;

            if ($avevaPassword && ! $riga->emailVerificata()) {
                throw new RegistrazioneRifiutata(
                    "La registrazione {$riga->getKey()} non ha mai confermato la propria casella: ".
                    'nessun account può nascere da essa.',
                    RegistrazioneRifiutata::NON_VERIFICATA,
                );
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

            return [$account, true, $avevaPassword];
        });
    }

    /**
     * 🔴 **Il rifiuto arriva DOPO l'incasso, quindi deve lasciare una traccia
     * che sopravviva al deploy.**
     *
     * Il difetto che questo metodo chiude: la sola risposta era un `Log::error`
     * nei due chiamanti, cioè una riga su `laravel.log` — disco **effimero e
     * per-replica**, azzerato a ogni deploy e a ogni risveglio da scale-to-zero
     * (lo dice `bootstrap/app.php`). A trenta giorni la potatura si porta via
     * anche la riga pendente, e di un pagamento incassato che non è mai
     * diventato un account non resta **niente**: nessun account, nessuna email,
     * nessuno che lo sappia. Serve un intervento umano, e un intervento umano
     * vuole un posto in cui lo si vede.
     *
     * Due tracce e non una, perché rispondono a due domande diverse:
     *
     * - **`report()`** porta il caso nell'error tracker interno (tabella
     *   `errori`, ADR-017): è la coda di ciò che qualcuno deve **riparare**, ha
     *   una retention e una schermata. È qui e non nei chiamanti perché i
     *   chiamanti sono due e la dimenticanza sarebbe di uno solo — cioè
     *   invisibile.
     * - **la riga di audit** risponde a «cos'è successo a questa
     *   registrazione?», che è la domanda che si farà chi riceve la telefonata
     *   di chi ha pagato. Il soggetto è la `Registrazione` (mappata in
     *   `SoggettiAudit`, che la etichetta col `nome_ente`), perché l'Account —
     *   il soggetto del successo — qui non esiste.
     *
     * ⛔ **Nelle properties non entra il messaggio.** `ProvisioningRifiutato`
     * nomina l'email di chi amministra già un altro account: il registro si
     * legge con `tenants.view_all`, e l'indirizzo di chi non è (ancora) cliente
     * non ci deve arrivare. Il testo per intero vive nel log dei chiamanti e
     * nell'issue del tracker, che sono superfici operative.
     */
    private function registraIlRifiuto(Registrazione $registrazione, RegistrazioneRifiutata $e): void
    {
        report($e);

        activity(AuditLog::NAME)
            ->performedOn($registrazione)
            ->withProperties([
                'registrazione_id' => $registrazione->getKey(),
                'codice' => $e->codice,
                'piano' => $registrazione->piano,
                'stripe_session_id' => $registrazione->stripe_session_id,
            ])
            ->log(self::DESCRIZIONE_RIFIUTO);
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
     * La descrizione della riga che racconta un pagamento **incassato e non
     * diventato un account**. Costante come la sorella, e per la stessa ragione:
     * un test che riscrivesse la stringa a mano resterebbe verde a qualunque
     * cosa la si cambiasse.
     */
    public const DESCRIZIONE_RIFIUTO = 'Registrazione pubblica rifiutata dopo il pagamento';

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
