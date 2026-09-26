<?php

namespace App\Notifications;

use App\Support\AuditLog;
use App\Support\Mail\MarchioEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * L'invito a impostare la password (ADR-012, metà provisioning).
 *
 * Tre differenze deliberate rispetto a `DigestScadenze`, che è l'altra
 * notifica del progetto:
 *
 * 1. **`ShouldQueue`, dal 21 Ago 2026 — e prima era il contrario.** Questa
 *    riga diceva «niente `ShouldQueue`», con l'argomento che un worker può non
 *    esserci e una notifica accodata resterebbe ferma mentre il chiamante dice
 *    «inviato». L'argomento **resta vero**, e per questo la decisione è arrivata
 *    con l'infrastruttura: una **managed queue** su Cloud, che il chiamante non
 *    deve indovinare. Ciò che l'ha ribaltata è un fatto che quel ragionamento
 *    non aveva: l'**HTTP timeout dell'ambiente è di 20 secondi**, e da S6 il
 *    provisioning si fa **da una pagina web**, non più solo da console. La
 *    transazione committa *prima* dell'invio, quindi un handshake SMTP lento
 *    produce un cliente creato e una risposta persa: l'operatore non sa se
 *    ripetere il gesto. In coda, l'SMTP non sta più nel percorso della
 *    richiesta.
 *
 *    ⚠️ **Il prezzo è dichiarato e pagato altrove**: chi accoda non può più
 *    vedere il fallimento, quindi chi chiama non deve più dire «inviato» ma
 *    «in consegna» — e questa classe si prende il carico di rendere visibile un
 *    fallimento che nessuno guarderebbe, con `failed()` qui sotto.
 *
 *    *Nei **test** `QUEUE_CONNECTION` è `sync` (forzato da `phpunit.xml`),
 *    quindi il comportamento resta sincrono e i test del provisioning
 *    continuano a esercitare l'invio vero.*
 *
 *    ⚠️ **In locale dipende da `.env`, e la differenza non è accademica.** Con
 *    `QUEUE_CONNECTION=redis` e nessun worker acceso — che era la
 *    configurazione di sviluppo fino al 21 Ago 2026 — il push **riesce**,
 *    `notify()` torna senza errore, la cabina scrive «Invito in consegna» e la
 *    mail resta in Redis per sempre. `failed()` non scatta: il job non gira
 *    affatto. È esattamente lo scenario che questo docblock descriveva per
 *    rifiutare `ShouldQueue`, e si è ripresentato dal lato dello sviluppatore
 *    invece che da quello della console. Il `.env` di sviluppo è quindi su
 *    `sync`; chi vuole `redis` in locale deve tenere un worker acceso.
 * 2. **`via()` è `['mail']` secco.** È una mail transazionale, non una
 *    comunicazione ricorrente: non passa dall'opt-out `riceve_email_scadenze`,
 *    che è il diritto di opposizione sul digest (registro T4). Senza QUESTA
 *    mail l'utente non esiste operativamente, e negarla non protegge nessuno.
 * 3. **Nessun canale `database`**: la campanella la vedrebbe solo un utente
 *    che è già dentro, e l'invitato è per definizione ancora fuori.
 *
 * Il link si costruisce **qui e non nel comando**: ogni invio — primo o
 * reinvio — produce una firma fresca di 7 giorni, e il comando non ha bisogno
 * di sapere come si firma un URL.
 */
class InvitoUtente extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Tre tentativi con attesa crescente, **dichiarati qui e non nel pannello**.
     *
     * Le stesse opzioni esistono come flag del worker (`--tries`, `--backoff`),
     * ma lì vivrebbero solo nella console di Cloud: non starebbero in nessun
     * file, non passerebbero da una revisione, e nessuno leggendo questa classe
     * saprebbe quante volte viene ritentata. È la stessa forma di problema che
     * il progetto ha già pagato due volte — le migration non applicate al DB di
     * sviluppo, `config/rbac.php` non riseminato: *ciò che non vive nel
     * repository non viene allineato da niente*. Sul job, inoltre, vincono
     * queste sui flag del worker: sono la parola definitiva, non un default.
     *
     * Un minuto e poi cinque: un SMTP che rifiuta per throttling si riprende in
     * quell'ordine di grandezza, e tre tentativi in sei minuti non intasano una
     * coda che serve anche il digest.
     *
     * @var int
     */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    /**
     * ⚠️ **Il destinatario si porta dentro la notifica**, e non è ridondante.
     * `failed()` riceve solo l'eccezione — **non** il notifiable — quindi senza
     * questo campo la riga di audit direbbe «un invito per Clinica Aurora è
     * morto» senza dire **a chi** vada rimandato: inutile con due Admin sullo
     * stesso Ente, o dopo due giri di provisioning. Una traccia che non si può
     * usare non è una traccia.
     */
    /**
     * ⚠️ **`$enteId` è il terzo parametro, opzionale e in CODA**, e la posizione
     * è parte della decisione: i chiamanti storici — `ProvisionaEnte`, i test
     * del provisioning, `TimbroImpersonazioneAuditTest` — costruiscono l'invito
     * con due argomenti, e un parametro obbligatorio (o inserito in mezzo) li
     * romperebbe tutti in una volta.
     *
     * Serve perché `enteNome` è una **stringa**: dice come si chiama l'Ente, non
     * quale sia, e da una stringa non si leggono né il logo né il colore. La via
     * alternativa — risalire all'Ente da `auth()` dentro `toMail()` — è
     * esattamente la fuga che 🔗 `MarchioEmail` esiste per impedire: questo
     * metodo gira sul worker, dove il tenant corrente non è quello dell'invito.
     * Un `int` nel payload si serializza, attraversa la coda e non porta con sé
     * un solo byte del logo.
     */
    public function __construct(
        public readonly string $enteNome,
        public readonly string $destinatario = '',
        public readonly ?int $enteId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'invito.mostra',
            now()->addDays(self::GIORNI_VALIDITA),
            ['user' => $notifiable->id],
        );

        return (new MailMessage)
            ->subject("Easy Lab · Invito per {$this->enteNome}")
            ->markdown('mail.invito-utente', [
                'marchio' => MarchioEmail::perEnte($this->enteId),
                'destinatario' => $notifiable,
                'ente' => $this->enteNome,
                'url' => $url,
                'giorni' => self::GIORNI_VALIDITA,
            ]);
    }

    /**
     * Sette giorni: il broker di reset di Fortify scade in 60 minuti (ed è il
     * motivo per cui l'invito non lo riusa), ma un invito deve sopravvivere a
     * un fine settimana e a una casella aperta il lunedì.
     */
    public const GIORNI_VALIDITA = 7;

    /**
     * L'invito non è partito, e nessuno lo sta guardando.
     *
     * ⚠️ **È il pezzo che ripaga il passaggio in coda.** Da sincrono, il
     * fallimento tornava al chiamante e finiva sotto gli occhi dell'operatore;
     * accodato, finirebbe in `failed_jobs` — una tabella che su un progetto a
     * un solo sviluppatore non guarda nessuno, e il sintomo sarebbe un cliente
     * che «non ha ricevuto niente» settimane dopo.
     *
     * Quindi si scrive **dove qualcuno guarda**: nel log dell'audit, sullo
     * stesso canale degli altri eventi di sicurezza, e nel log applicativo —
     * entrambi con **destinatario**, ente e motivo, perché è l'indirizzo la sola
     * cosa che rende la riga azionabile.
     *
     * ⚠️ **Gap dichiarato**: la vista Audit è ancora da costruire (S6 la
     * elenca, non è stata fatta). Finché non c'è, `activity_log` è nella stessa
     * condizione di `failed_jobs` — una tabella senza lettore — e il canale
     * davvero consultabile è il `Log::error`, che su Cloud finisce nei log
     * dell'ambiente. È il motivo per cui il destinatario sta in **entrambi**.
     *
     * Laravel chiama questo metodo se esiste sulla notifica, dopo l'ultimo
     * tentativo fallito.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Invito non consegnato', [
            'destinatario' => $this->destinatario,
            'ente' => $this->enteNome,
            'errore' => $e->getMessage(),
        ]);

        // ⚠️ Senza `causedBy()`: nel worker non c'è un utente autenticato, e un
        // `causer` nullo è la verità — l'alternativa sarebbe attribuire la riga
        // a nessuno fingendo di sapere chi fosse. Chi ha fatto il gesto sta già
        // nella riga di creazione dell'account, a pochi secondi di distanza.
        activity(AuditLog::NAME)
            ->withProperties([
                'destinatario' => $this->destinatario,
                'ente' => $this->enteNome,
                'errore' => $e->getMessage(),
                'tentativi' => $this->tries,
            ])
            ->log('Invito NON consegnato');
    }
}
