<?php

namespace App\Notifications;

use App\Support\Mail\MarchioEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'account è stato bloccato (🔗 ADR-047; ADR-013 il lockout a due sorgenti).
 *
 * ## ⛔ Il motivo non esce, e non è un'omissione
 *
 * `locked_reason` e `stripe_lock_reason` sono annotazioni **interne** (ADR-013):
 * nemmeno `/bloccato` le mostra. L'email dice che l'accesso è sospeso, che i
 * dati restano al loro posto e dove andare — non perché.
 *
 * Marchio di piattaforma: chi sospende è Easy Lab, non l'Ente. Nessuna
 * preferenza personale: non è un'informazione a cui si rinuncia.
 */
class AccountBloccato extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    public function __construct(public readonly string $ragioneSociale) {}

    /**
     * Solo email. Nessuna domanda da fare qui: l'interruttore di piattaforma lo
     * legge `AvvisiAccount`, che è l'unico a costruire questa notifica, e una
     * preferenza personale non c'è — non è un'informazione a cui si rinuncia.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Easy Lab · L'accesso di {$this->ragioneSociale} è sospeso")
            ->markdown('mail.account-bloccato', [
                'marchio' => MarchioEmail::piattaforma(),
                'ragioneSociale' => $this->ragioneSociale,
                'url' => route('abbonamento.index'),
            ]);
    }
}
