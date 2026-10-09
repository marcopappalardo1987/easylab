<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Il veicolo delle prove di invio da Piattaforma → Email (🔗 ADR-047).
 *
 * Porta un `MailMessage` **già composto** dalla notifica vera, con dati di
 * esempio: così la prova passa dallo stesso canale, dallo stesso tema e dalla
 * stessa vista dell'email reale, invece che da una copia che le somiglia.
 *
 * ⚠️ **Non è `ShouldQueue`, e non deve diventarlo**: chi preme «Invia prova»
 * vuole sapere subito se il server di posta ha accettato il messaggio, e una
 * coda risponderebbe «in consegna» a un test il cui scopo è l'esito.
 */
class EmailDiProva extends Notification
{
    public function __construct(private readonly MailMessage $messaggio) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->messaggio;
    }
}
