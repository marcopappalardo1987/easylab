<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\Registrazione;
use App\Models\User;
use App\Notifications\AccountGiaEsistente;
use App\Notifications\VerificaEmailRegistrazione;
use App\Support\Registrazione\CompletaRegistrazione;
use App\Support\Registrazione\PianiRegistrabili;
use App\Support\Registrazione\PortaleCheckout;
use App\Support\Registrazione\RegistrazioneRifiutata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * 🔴 Il self-signup pubblico: modulo → verifica email → Stripe → account
 * (🔗 ADR-012 il provisioning, ADR-032 l'Account intestatario, ADR-002 il
 * rapporto commerciale).
 *
 * **Area rossa** della Policy di Code Review, e per la ragione più forte che il
 * progetto abbia incontrato: è l'**unica superficie pubblica e non autenticata
 * che scrive**. Ogni altra rotta aperta o legge soltanto (`/q/{token}` traduce e
 * rimanda) o agisce su una riga che qualcuno ha già creato dall'interno
 * (`/invito/{user}`).
 *
 * ## Perché un controller e NON un componente Livewire
 *
 * 🔴 `throttle` è un middleware **di rotta**, e ogni update Livewire passa da
 * `/livewire/update`: un form Livewire avrebbe il rate limiting sul solo GET
 * iniziale, cioè non l'avrebbe. Il progetto ha già pagato questa forma con
 * `two-factor.enforce`, che ADR-018 documenta come «non persistente sugli update
 * Livewire». Controller + Blade + `@csrf`, come `ImpostaPasswordInvito`.
 *
 * ## Le quattro difese di questa superficie
 *
 * 1. **Anti-enumerazione.** Un'email che appartiene già a un utente produce
 *    **la stessa identica risposta** di una nuova — stesso redirect, stesso
 *    messaggio — e nessuna riga a database. Cambia solo cosa arriva nella
 *    casella, che è raggiungibile dal solo proprietario: vedi
 *    `AccountGiaEsistente`. Dire «esiste già» sarebbe pubblicare l'anagrafica
 *    commerciale di Easy Lab un indirizzo alla volta.
 * 2. **Rate limiting** su due chiavi, IP **ed** email (limiter `registrazione`,
 *    dichiarato in `AppServiceProvider`): la sola chiave IP si aggira con un
 *    proxy, la sola chiave email cambiando indirizzo.
 * 3. **Honeypot**: un campo che un essere umano non vede e non compila. Se
 *    arriva pieno la risposta è di **successo apparente** e non accade niente —
 *    un rifiuto esplicito insegnerebbe al bot come passare.
 * 4. **URL firmati** su tutti i passi intermedi: la firma copre id e scadenza e
 *    cade **prima** del route-model binding, quindi da qui non si enumerano le
 *    registrazioni pendenti. È il precedente dell'invito (ADR-012) e del QR
 *    (ADR-003).
 *
 * ## ⛔ Nessuna riga di audit per l'avvio e per la verifica
 *
 * E non è una dimenticanza. Quelle due righe non avrebbero nessun **causer**
 * (chi compila il modulo non esiste nel dominio) e sarebbero il registro di un
 * gesto che non è ancora successo niente: per essere utili dovrebbero portare
 * l'**email** di chi potrebbe non diventare mai cliente, cioè un dato personale
 * in una tabella che una retention non ce l'ha (T6, aperta col legale). La
 * traccia dell'avvio **è la riga `registrazioni`**, che si pota da sola a 30
 * giorni.
 *
 * Ciò che merita il registro sono i due **esiti**, e li scrive entrambi
 * `CompletaRegistrazione`: l'account nato (`performedOn($account)`) e — da qui
 * la voce `Registrazione` in `SoggettiAudit` — il pagamento incassato che un
 * rifiuto non ha fatto diventare un account, dove l'Account da nominare non
 * esiste. Quella voce etichetta col `nome_ente` e non con l'email, apposta.
 *
 * ## L'interruttore, e da che parte sta chiuso
 *
 * `config('easylab.registrazione.aperta')` **spegne solo l'ingresso** — GET e
 * POST di `/registrati` danno 404, non 403: un 403 dichiarerebbe che la pagina
 * c'è. I passi successivi restano aperti di proposito: chi ha già pagato deve
 * poter completare, e chiudergli la porta significherebbe aver incassato senza
 * consegnare.
 */
class RegistrazionePubblica extends Controller
{
    use PasswordValidationRules;

    /**
     * ⚠️ **Il nome del campo trappola non dice «trappola».** `sito_web` è un
     * campo plausibile per un modulo aziendale, quindi un bot generico lo
     * riempie; `honeypot` no. Nel markup è nascosto e fuori dal flusso di
     * tabulazione, e porta `autocomplete="off"` — o il gestore di password del
     * browser lo compilerebbe al posto di un utente vero, bloccandolo.
     */
    private const CAMPO_TRAPPOLA = 'sito_web';

    /**
     * ⚠️ **Un giorno, per success e cancel.** Chi resta quaranta minuti sulla
     * pagina di Stripe — un cambio di carta, una telefonata — deve poter
     * tornare: una scadenza corta qui è un 403 su un pagamento riuscito.
     */
    private const ORE_RITORNO_DA_STRIPE = 24;

    /** La finestra del passo interno modulo → checkout, che si percorre subito. */
    private const ORE_PASSO_INTERNO = 2;

    public function __construct(
        private readonly PortaleCheckout $portale,
        private readonly CompletaRegistrazione $completamento,
    ) {}

    // ── 1. Il modulo ─────────────────────────────────────────────────────────

    public function mostra(Request $request)
    {
        $this->esigiIngressoAperto();

        // `?piano=` preseleziona la radio: è ciò che rende condivisibile il link
        // di pagamento della cabina. Passa da `accetta()` — la stessa domanda
        // del POST — quindi un codice archiviato, gratuito o inventato viene
        // ignorato in silenzio invece di mettere in vetrina un piano che il
        // passo successivo rifiuterebbe.
        $preselezionato = $request->query('piano');
        $preselezionato = is_string($preselezionato) && PianiRegistrabili::accetta($preselezionato)
            ? $preselezionato
            : null;

        return $this->paginaDelModulo($preselezionato);
    }

    public function avvia(Request $request)
    {
        $this->esigiIngressoAperto();

        // Guasto di configurazione, non piano gratuito: nessun piano vendibile
        // significa che il price di Stripe non è configurato su questo
        // ambiente. Si dice, e non si regala un account. (🔗 PianiRegistrabili)
        if (! PianiRegistrabili::ceQualcosaDaVendere()) {
            return redirect()->route('registrazione.mostra');
        }

        $validati = $request->validate([
            'nome_ente' => ['required', 'string', 'max:255'],
            'nome_referente' => ['required', 'string', 'max:255'],
            // `email:filter` e non il solo `email`: la regola nuda accetta
            // indirizzi che nessun MTA consegnerebbe, e qui la casella è
            // l'unica prova d'identità dell'intero percorso.
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            'piano' => ['required', 'string', Rule::in(PianiRegistrabili::codici())],
            // Le regole di robustezza vengono dal trait di Fortify: la
            // definizione resta UNA, la stessa del reset e dell'invito.
            'password' => $this->passwordRules(),
        ]);

        // ⚠️ Normalizzazione **prima** di ogni confronto: è lo stesso difetto
        // già chiuso in `ProvisionaCliente::creaCliente()`. Senza,
        // «Mario@Studio.it » e «mario@studio.it» sono due persone per il
        // controllo di esistenza e una sola per l'indice unique di `users` —
        // cioè un'eccezione al posto di un messaggio, dopo il pagamento.
        $email = Str::lower(trim($validati['email']));

        // 🔴 L'honeypot si legge DOPO la validazione, non prima: un bot che
        // sbaglia anche gli altri campi deve vedere gli stessi errori di un
        // umano distratto, o la risposta gli direbbe che esiste un secondo
        // controllo. Il ritorno è quello del percorso felice, identico.
        if (filled($request->input(self::CAMPO_TRAPPOLA))) {
            return $this->allaCasella($email);
        }

        // 🔴 Il ramo anti-enumerazione. Nessuna riga, nessuna differenza
        // visibile: solo un'email diversa nella casella di chi la possiede.
        // ⚠️ `withTrashed()`: da 🔗 ADR-038 `users.email` è unique **senza
        // condizione**, quindi una persona cestinata continua a occupare
        // l'indirizzo. Senza questa lettura la registrazione proseguirebbe fino
        // al provisioning e morirebbe sull'unique — un 500 su un percorso
        // pubblico e non autenticato. La risposta resta identica a quella del
        // percorso felice: il ramo è anti-enumerazione, e deve restarlo.
        $utente = User::withTrashed()->where('email', $email)->first();

        if ($utente !== null) {
            // ⚠️ L'avviso parte **solo per chi un accesso ce l'ha davvero**:
            // scrivere «hai già un account» a una persona cestinata sarebbe
            // dirle una cosa falsa. Chi guarda da fuori non distingue i due
            // casi — la risposta HTTP è la stessa e nessuna riga viene scritta.
            if (! $utente->trashed()) {
                $this->avvisaChiEsisteGia($email);
            }

            return $this->allaCasella($email);
        }

        $registrazione = $this->rigaPendentePer($email) ?? new Registrazione;

        // ⛔ `forceFill` e non `create`: `$fillable` è vuoto apposta su questo
        // model (vedi il suo docblock), perché l'unico chiamante riceve input
        // da una superficie pubblica. Le colonne si nominano una per una.
        $registrazione->forceFill([
            'nome_ente' => trim($validati['nome_ente']),
            'nome_referente' => trim($validati['nome_referente']),
            'email' => $email,
            'password_hash' => Hash::make($validati['password']),
            'piano' => $validati['piano'],
            // ⚠️ **Difesa in profondità, e per una riga che oggi le ha già a
            // null.** `rigaPendentePer()` restituisce solo righe *non
            // verificate*, quindi queste due assegnazioni non cambiano nulla —
            // finché quella query resta com'è. Il giorno in cui qualcuno
            // allargasse il riuso, il peggio possibile diventerebbe «la vittima
            // deve verificare di nuovo» invece di «un terzo ha sostituito la
            // password»: si perde un passo, non un account.
            'email_verificata_at' => null,
            'stripe_session_id' => null,
        ])->save();

        // ⚠️ **In consegna, mai «inviata»**: la notifica è `ShouldQueue`, e chi
        // accoda non può sapere se è partita. Un fallimento lo racconta il suo
        // `failed()`. Il `rescue()` copre il caso in cui sia la consegna alla
        // coda a rompersi: la riga è già salvata, e ripetere il modulo la
        // riusa — «reinviare = ripetere lo stesso gesto» (ADR-012).
        rescue(fn () => $registrazione->notify(new VerificaEmailRegistrazione($email)));

        return $this->allaCasella($email);
    }

    public function controllaEmail(Request $request)
    {
        // Nessuna guardia sull'interruttore: questa pagina non crea niente ed è
        // il punto d'arrivo di chi ha appena compilato il modulo — chiuderla
        // insieme all'ingresso lascerebbe qualcuno su un 404 subito dopo un
        // gesto riuscito.
        return view('auth.registrazione-controlla-email', [
            'email' => $request->session()->get('registrazione.email'),
        ]);
    }

    // ── 2. La verifica della casella ─────────────────────────────────────────

    public function verifica(Request $request, Registrazione $registrazione)
    {
        if ($registrazione->completata()) {
            return $this->allaLogin('Il tuo account è già attivo: accedi con la password che hai scelto.');
        }

        // 🔴 La firma copre l'id, l'impronta copre il **contenuto**: un link
        // spedito prima che qualcuno riscrivesse la riga (stesso indirizzo,
        // altra password) non vale più. 403 come una firma scaduta: chi ha
        // ripetuto il modulo ha nella casella il link più recente.
        $impronta = $request->query('impronta');
        abort_unless(is_string($impronta) && hash_equals($registrazione->improntaVerifica(), $impronta), 403);

        // ⚠️ **Idempotente.** Riaprire il link non riscrive il timbro: la data
        // della verifica è un fatto, e sovrascriverla la sposterebbe in avanti
        // ogni volta che qualcuno rilegge la posta.
        if (! $registrazione->emailVerificata()) {
            $registrazione->forceFill(['email_verificata_at' => now()])->save();
        }

        return redirect()->to($this->urlFirmata('registrazione.pagamento', $registrazione, self::ORE_RITORNO_DA_STRIPE));
    }

    // ── 3. Il pagamento ──────────────────────────────────────────────────────

    public function pagamento(Request $request, Registrazione $registrazione)
    {
        if ($registrazione->completata()) {
            return $this->allaLogin('Il tuo account è già attivo: accedi con la password che hai scelto.');
        }

        $this->esigiCasellaVerificata($registrazione);

        return view('auth.registrazione-pagamento', [
            'registrazione' => $registrazione,
            'piano' => $registrazione->piano,
            // L'action del form è una URL firmata a sé: la firma che ha aperto
            // questa pagina vale per il GET, non per il POST.
            'azione' => $this->urlFirmata('registrazione.verso-stripe', $registrazione, self::ORE_PASSO_INTERNO),
            'vendibile' => PianiRegistrabili::accetta($registrazione->piano),
        ]);
    }

    public function versoStripe(Request $request, Registrazione $registrazione)
    {
        if ($registrazione->completata()) {
            return $this->allaLogin('Il tuo account è già attivo: accedi con la password che hai scelto.');
        }

        // 🔴 **La guardia che rende la verifica email davvero obbligatoria**
        // invece che soltanto cronologicamente prima. Senza di lei, chiunque
        // ottenesse una firma valida per questa rotta — per esempio chi ha
        // compilato il modulo con la casella di un altro e ha ricevuto la
        // pagina di ritorno — aprirebbe un checkout intestato a un indirizzo
        // che nessuno ha confermato.
        $this->esigiCasellaVerificata($registrazione);

        // Ricontrollato **qui** e non solo alla validazione del modulo: fra i
        // due momenti `/piattaforma/piani` può aver archiviato il piano.
        if (! PianiRegistrabili::accetta($registrazione->piano)) {
            return redirect()->to($this->urlFirmata('registrazione.pagamento', $registrazione, self::ORE_PASSO_INTERNO));
        }

        $sessione = $this->portale->apri(
            $registrazione,
            successUrl: $this->urlFirmata('registrazione.completata', $registrazione, self::ORE_RITORNO_DA_STRIPE),
            cancelUrl: $this->urlFirmata('registrazione.pagamento', $registrazione, self::ORE_RITORNO_DA_STRIPE),
        );

        // ⛔ **Scritto PRIMA del redirect**, ed è l'unica copia dell'id che
        // avremo: il `success_url` non può portarlo (la firma di Laravel copre
        // la query string), e leggerlo dalla nostra riga invece che dall'URL è
        // anche l'unico modo di non fidarsi del browser.
        $registrazione->forceFill(['stripe_session_id' => $sessione->id])->save();

        // `away()`: la destinazione è un dominio di terzi, e un `redirect()`
        // ordinario la tratterebbe come una rotta interna.
        return redirect()->away($sessione->url);
    }

    // ── 4. Il ritorno da Stripe ──────────────────────────────────────────────

    /**
     * ⚠️ **Fuori dal gruppo `guest`, e non è una dimenticanza.** Stripe rimanda
     * qui il browser: un visitatore già autenticato con un altro account
     * verrebbe sbattuto sulla dashboard **perdendo il completamento**, mentre il
     * pagamento è già avvenuto. La firma è il gate, l'azione è idempotente e
     * non autentica nessuno. Il webhook resta comunque la rete che chiude il
     * caso «scheda chiusa prima di tornare».
     */
    public function completata(Request $request, Registrazione $registrazione)
    {
        if ($registrazione->completata()) {
            return $this->paginaCompletata($registrazione, true);
        }

        // Nessuna sessione sulla riga significa che il checkout non è mai stato
        // aperto: non c'è niente da rileggere, e non si inventa un esito.
        if (blank($registrazione->stripe_session_id)) {
            return $this->paginaCompletata($registrazione, false);
        }

        try {
            $this->completamento->esegui(
                $registrazione,
                $this->portale->esito($registrazione->stripe_session_id),
            );
        } catch (RegistrazioneRifiutata $e) {
            // ⛔ **Il messaggio non si mostra mai a chi si è registrato**: dice
            // che un'email appartiene già a un amministratore, o che un piano è
            // sparito dal listino. Sono informazioni operative, e questa è una
            // superficie pubblica. Chi ha pagato legge «stiamo completando».
            //
            // ⚠️ **Nessun `report()` qui, e non è una dimenticanza**: la traccia
            // durevole — l'issue nel tracker interno e la riga di audit — la
            // scrive `CompletaRegistrazione::registraIlRifiuto()`, cioè un posto
            // solo per **entrambi** i chiamanti. Ripeterla da qui darebbe due
            // occorrenze per un fatto, e la dimenticanza di uno dei due
            // chiamanti sarebbe invisibile. Questa riga resta perché il
            // **messaggio** per esteso (che nel registro non entra, per privacy)
            // vive nel log.
            Log::error('Registrazione pubblica rifiutata al completamento', [
                'registrazione_id' => $registrazione->getKey(),
                'codice' => $e->codice,
                'motivo' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            // Il pagamento è **già incassato**: nessuna eccezione qui deve
            // diventare una pagina di errore per chi ha appena messo la carta.
            // Il webhook riproverà lo stesso gesto, e `report()` porta la
            // diagnosi nel tracker interno.
            report($e);
        }

        $registrazione = $registrazione->fresh() ?? $registrazione;

        return $this->paginaCompletata($registrazione, $registrazione->completata());
    }

    // ── Gesti condivisi ──────────────────────────────────────────────────────

    /**
     * ⛔ **404 e non 403**: un 403 dichiarerebbe che la pagina esiste e che
     * qualcuno la può aprire, cioè un invito a bussare. Stessa scelta del banco
     * dei componenti in `routes/web.php`.
     */
    private function esigiIngressoAperto(): void
    {
        abort_unless((bool) config('easylab.registrazione.aperta', false), 404);
    }

    /**
     * ⚠️ **403 e non un redirect gentile.** Chi arriva qui con una firma valida
     * e la casella non verificata non è un utente confuso: è qualcuno che ha
     * l'URL di un passo che non gli spetta ancora. Un redirect gli direbbe
     * dove sta il passo mancante; il 403 no.
     */
    private function esigiCasellaVerificata(Registrazione $registrazione): void
    {
        abort_unless($registrazione->emailVerificata(), 403);
    }

    /**
     * La riga pendente da riusare, se c'è.
     *
     * ⚠️ **Si riusa invece di crearne una seconda**: «reinviare = ripetere lo
     * stesso gesto» (ADR-012). Senza, chi compila il modulo tre volte lascerebbe
     * tre righe con tre hash della stessa password, e il link ricevuto per
     * primo resterebbe valido su una riga che nessuno completerà.
     *
     * 🔴 **E si riusa SOLO finché nessuno ha verificato la casella**, che è la
     * guardia di sicurezza di questo controller. Questo POST è pubblico e
     * l'unico dato che serve a raggiungere una riga altrui è **l'indirizzo**:
     * senza `whereNull('email_verificata_at')` un terzo che lo conosce ripete il
     * modulo con la propria password, `password_hash` viene sovrascritto mentre
     * il timbro di verifica — messo dalla vittima, per un contenuto diverso —
     * resta valido, e l'account che la vittima sta pagando nasce con la
     * credenziale dell'attaccante. La verifica della casella è ciò che lega una
     * riga a una persona: da quel momento la riga non è più riusabile da chi
     * quella casella non la legge.
     *
     * ⚠️ **Filtrare qui invece di azzerare il timbro al riuso**, che era l'altra
     * strada possibile: azzerarlo chiuderebbe il furto ma aprirebbe un
     * dispetto — chiunque conosca l'indirizzo potrebbe revocare la verifica a
     * qualcuno che è **già sulla pagina di Stripe**, e un pagamento incassato
     * resterebbe senza account. Una riga nuova non tocca chi sta pagando, e chi
     * non legge quella casella non può verificarla.
     *
     * ⚠️ `orderByDesc('id')` e non `latest()`: `created_at` non è unico, e con
     * due righe nate nello stesso secondo l'ordine dipenderebbe dal motore —
     * SQLite in locale e Postgres in CI danno risposte diverse (CLAUDE.md).
     */
    private function rigaPendentePer(string $email): ?Registrazione
    {
        return Registrazione::query()
            ->where('email', $email)
            ->whereNull('completata_at')
            ->whereNull('email_verificata_at')
            // ⛔ **Una riga che è già stata a Stripe non è una bozza** (ADR-039).
            // Dal Payment Link nasce una riga sintetizzata che porta un session
            // id vero e può restare pendente (un rifiuto dopo l'incasso).
            // Riusarla qui la riscriverebbe azzerando `stripe_session_id`, cioè
            // cancellando l'unico legame fra un pagamento incassato e la sua
            // traccia — e lasciando l'audit di quel rifiuto a puntare a un id
            // che non esiste più.
            ->whereNull('stripe_session_id')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * ⚠️ **Sincrona e dentro un `rescue()`.** Vedi `AccountGiaEsistente`: se
     * fosse accodata, un mittente ostile che prova mille indirizzi riempirebbe
     * la coda; e un SMTP giù non deve produrre una risposta **diversa** da
     * quella del percorso felice — che è tutto ciò che questa difesa è.
     */
    private function avvisaChiEsisteGia(string $email): void
    {
        rescue(fn () => Notification::route('mail', $email)->notify(new AccountGiaEsistente));
    }

    /**
     * 🔴 **L'unica risposta del modulo**, e il fatto che sia una sola funzione
     * è la difesa: due `return` scritti a mano nei due rami divergerebbero al
     * primo ritocco del messaggio, e la divergenza sarebbe di nuovo un modo di
     * sapere se un indirizzo è già cliente.
     */
    private function allaCasella(string $email)
    {
        return redirect()
            ->route('registrazione.controlla-email')
            ->with('registrazione.email', $email);
    }

    private function allaLogin(string $messaggio)
    {
        return redirect()->route('login')->with('status', $messaggio);
    }

    private function urlFirmata(string $rotta, Registrazione $registrazione, int $ore): string
    {
        return URL::temporarySignedRoute($rotta, now()->addHours($ore), ['registrazione' => $registrazione->getKey()]);
    }

    private function paginaDelModulo(?string $preselezionato = null)
    {
        return view('auth.registrati', [
            'piani' => PianiRegistrabili::modelli(),
            'campoTrappola' => self::CAMPO_TRAPPOLA,
            'pianoPreselezionato' => $preselezionato,
        ]);
    }

    private function paginaCompletata(Registrazione $registrazione, bool $riuscita)
    {
        return view('auth.registrazione-completata', [
            'registrazione' => $registrazione,
            'riuscita' => $riuscita,
        ]);
    }
}
