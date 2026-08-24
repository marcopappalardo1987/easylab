<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 🔴 L'**allerta** dell'error tracker interno (S6 — 🔗 ADR-017;
 * Privacy §3 e riga T8).
 *
 * Parte da `App\Support\Errori\CatturaErrori` su **issue nuova** o **riapertura
 * automatica**, e va a `config('easylab.errori.alert_email')`. È il canale da
 * cui in uso quotidiano si scopre che qualcosa si è rotto: nessuno apre
 * `/piattaforma/errori` a caso.
 *
 * ## 🔴 Ciò che questa email NON contiene, ed è la ragione per cui esiste così
 *
 * **Classe, `file:riga`, conteggio, e il link. Nient'altro.** Niente messaggio,
 * niente stack trace, niente input della richiesta, niente id dell'utente che
 * ha subito l'errore.
 *
 * La regola non nasce qui: è già scritta in Privacy §3 per il digest delle
 * scadenze — «un'email esce dal perimetro dell'applicazione, resta in una
 * casella e viene inoltrata a lettori che nessuno ha autorizzato». Qui pesa il
 * doppio, perché la pagina che quei dati li mostra sta dietro
 * **`system.logs.view`**, il gate più stretto del progetto (solo Developer, e
 * nel set bloccato): un'email con lo stack trace dentro **scavalcherebbe** quel
 * gate per posta, e il destinatario è una casella — che non ha né permesso né
 * registro di audit.
 *
 * ⚠️ **Il messaggio è escluso anche se sembrerebbe il dato più utile**: è
 * *interpolato* («Utente 42 non trovato») e porta identificativi reali. È lo
 * stesso confine per cui l'etichetta del soggetto nel registro di audit è la
 * `classe` e mai il `messaggio` (🔗 `Errore::cambiaStato()`).
 *
 * La conseguenza pratica è voluta: l'email dice **che** guardare e **dove**
 * cliccare, e il dettaglio si legge dentro l'applicazione, dietro il permesso.
 *
 * ## Perché in coda, e cosa resta vero e cosa no
 *
 * `ShouldQueue` come `InvitoUtente` (21 Ago 2026), e qui la ragione è più
 * stringente che là: questo invio parte **dentro il gestore delle eccezioni**,
 * cioè su una richiesta che sta già andando male. Un handshake SMTP lento
 * aggiungerebbe secondi a una risposta d'errore — con l'HTTP timeout
 * dell'ambiente a 20 secondi — e un SMTP irraggiungibile trasformerebbe un
 * errore in due.
 *
 * ⚠️ **In locale e in test `QUEUE_CONNECTION` è `sync`** (`tests/TestCase.php`
 * lo forza), quindi un `ShouldQueue` gira **sincrono**: la falsificabilità del
 * «va in coda» è limitata, e i test asseriscono sul **contratto** — che la
 * classe sia `ShouldQueue` e che `SendQueuedNotifications` **riceva** questi
 * `tries`/`backoff` — non sul comportamento. È scritto qui perché chi legge un
 * test verde su questa riga sappia che cosa ha provato.
 *
 * ⚠️ **Niente `onQueue()`.** La managed queue di Cloud processa **una sola**
 * coda (`default`): un nome diverso qui sarebbe un job accodato che nessun
 * worker prende in carico — cioè un alert perso in silenzio, che è peggio di
 * nessun alert.
 */
/**
 * ⚠️ **NON è `ShouldQueue`, e non è una dimenticanza.** L'accodamento vive in
 * `InviaAllertaErrore`, che avvolge l'invio in un `try/catch`: se la notifica
 * si accodasse a sua volta, l'invio avverrebbe in un **secondo** job e quel
 * `try` non coprirebbe nulla — l'eccezione uscirebbe di nuovo, e il worker la
 * riporterebbe, che è precisamente il giro che il job esiste per chiudere.
 */
class NuovoErrore extends Notification
{
    /**
     * Tre tentativi con attesa crescente, **dichiarati qui e non nel pannello**
     * del worker — stessa ragione di `InvitoUtente`: ciò che vive solo nella
     * console di Cloud non sta in nessun file, non passa da una revisione e non
     * si legge accanto alla classe che riguarda.
     *
     * ⚠️ **E vanno scritte con questi nomi esatti.** Laravel non le legge dalla
     * notifica per magia: `SendQueuedNotifications::__construct()` fa
     * `getAttributeValue($notification, Tries::class, 'tries')` e `backoff()`
     * fa lo stesso con `Backoff::class`. Una property battezzata `$maxTries`
     * sarebbe **ignorata in silenzio** e il worker userebbe i default — la
     * cicatrice è di `InvitoUtente`, dove il primo test asseriva sulla property
     * della notifica e sarebbe rimasto verde con il nome sbagliato.
     *
     * @var int
     */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    /**
     * ⚠️ **Scalari, mai il model `Errore`.** Due ragioni, e la seconda è quella
     * che conta: un model finirebbe nel payload del job serializzato (per
     * identificativo, ma poi **rifetchato** dal worker con *tutte* le colonne,
     * `messaggio` compreso), e la disciplina di questa classe è che il
     * messaggio non entri mai nel percorso dell'email. La prima è banale: una
     * issue potata fra l'accodamento e la consegna farebbe fallire il job
     * invece di consegnare un alert che è ancora vero.
     *
     * `$posizione` è già `file:riga` **relativo** alla radice del progetto: su
     * Cloud la directory di deploy cambia a ogni release, e un percorso
     * assoluto in un'email è illeggibile il giorno dopo.
     */
    public function __construct(
        public readonly string $classe,
        public readonly string $posizione,
        public readonly int $occorrenze,
        public readonly int $erroreId,
        public readonly bool $riaperto = false,
    ) {}

    /**
     * `['mail']` secco.
     *
     * Niente canale `database`: la campanella è dei destinatari dell'app, e qui
     * il destinatario è **una casella**, non un utente — `AnonymousNotifiable`
     * non ha nemmeno dove scriverla. E niente opt-out `riceve_email_scadenze`:
     * quello è il diritto di opposizione sul digest (T4), che è una
     * comunicazione ricorrente a un interessato; questa è posta di servizio a
     * un indirizzo tecnico.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $titolo = $this->riaperto ? 'Errore riaperto' : 'Errore nuovo';

        return (new MailMessage)
            ->subject("Easy Lab · {$titolo}: {$this->classe}")
            ->greeting($titolo)
            ->line($this->riaperto
                ? 'Un errore che risultava risolto è tornato a succedere.'
                : 'Un errore mai visto prima è stato registrato.')
            ->line("Classe: {$this->classe}")
            ->line("Origine: {$this->posizione}")
            ->line("Occorrenze registrate finora: {$this->occorrenze}")
            // ⚠️ **Etichetta e URL sullo stesso elemento**: `->action()` rende
            // un pulsante che porta il testo *e* l'href, quindi un test che
            // asserisce sul testo non può essere verde con il link sparito.
            // Un `->line('Apri…')` seguito da un `->line($url)` sarebbe la
            // forma in cui i due si separano senza che nulla lo dica.
            //
            // ⚠️ **E l'etichetta non porta apostrofi** («Apri la scheda», non
            // «Apri la scheda dell'errore»): il template markdown la stampa con
            // `{{ }}`, quindi un apostrofo esce come `&#039;` e il test che lega
            // etichetta e link dovrebbe cercare l'entità HTML — cioè un
            // marcatore che chi legge il test non riconosce.
            ->action('Apri la scheda', route('piattaforma.errori.mostra', $this->erroreId))
            ->line('Il dettaglio tecnico non è in questa email di proposito: si legge nella scheda, dietro il permesso system.logs.view.');
    }

    /**
     * 🔴 **L'alert non è partito, e non deve diventare un secondo errore.**
     *
     * Solo `Log::error()`. Niente riga nel **tracker** — che è il punto: questo
     * codice gira sulla strada di un errore già avvenuto, e scrivere in
     * `errori` il fallimento dell'alert *di* un errore è la ricorsione scritta
     * come diligenza. La guardia di rientranza di `CatturaErrori` non basta a
     * escluderlo: sotto una coda vera `failed()` gira nel worker, dove quel
     * flag non è sullo stack.
     *
     * ⚠️ **E niente riga di audit, a differenza di `InvitoUtente::failed()`**,
     * che invece ne scrive una. La differenza non è di stile: là il fallimento
     * riguarda **una persona** (un cliente che non riceve l'invito) e il
     * registro è il posto in cui qualcuno lo ritrova; qui riguarda una casella
     * tecnica, e `activity_log` è un'altra **scrittura a database** proprio
     * quando la sola cosa certa è che qualcosa nell'applicazione non funziona.
     * Il canale che resta è il log dell'ambiente, che è anche l'unico che non
     * dipende dal database.
     *
     * 🔴 Nel messaggio non entra nulla dell'errore oltre alla classe e alla
     * posizione: `laravel.log` su Cloud si legge con una credenziale
     * d'infrastruttura, non col permesso, ed è lo stesso perimetro dell'email.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Alert errore non consegnato', [
            'classe' => $this->classe,
            'posizione' => $this->posizione,
            'errore_id' => $this->erroreId,
            'motivo' => $e->getMessage(),
            'tentativi' => $this->tries,
        ]);
    }
}
