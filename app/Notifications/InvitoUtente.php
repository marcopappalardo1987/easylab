<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * L'invito a impostare la password (ADR-012, metà provisioning).
 *
 * Tre differenze deliberate rispetto a `DigestScadenze`, che è l'altra
 * notifica del progetto:
 *
 * 1. **Niente `ShouldQueue`.** Il provisioning gira in console, dove un worker
 *    di coda può non esserci: una notifica accodata resterebbe in Redis e il
 *    comando direbbe «invito inviato» a un invito che non è partito. Qui
 *    l'invio è sincrono, così il fallimento è visibile al momento — ed è la
 *    ragione per cui il comando lo racchiude in un try/catch.
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
class InvitoUtente extends Notification
{
    public function __construct(
        public readonly string $enteNome,
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
}
