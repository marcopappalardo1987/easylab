<?php

namespace App\Notifications;

use App\Support\Mail\MarchioEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Il comodato d'uso di questo cliente scade fra trenta giorni» (🔗 ADR-058).
 *
 * La stessa email va a due lettori diversi, e le righe che cambiano lo dicono:
 * al **cliente** (chi amministra il suo contratto) si dice di sentire EasyLab
 * per il rinnovo; a **chi governa la piattaforma** si dice di quale cliente si
 * tratta e dove si sposta la scadenza.
 *
 * Solo scalari nel costruttore, come ogni notifica in coda: l'account si legge
 * dove la si accoda (`easylab:notifica-comodato`), mai sul worker.
 *
 * ⚠️ Non scrive mai le tre parole di ADR-050: il piano è «in comodato d'uso».
 */
class ComodatoInScadenza extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    /**
     * @param  string  $scadeIl  la data come si legge, `d/m/Y`
     * @param  int  $giorni  quanti giorni mancano; 0 = scade oggi
     * @param  bool  $perPiattaforma  `true` per la copia dei Superadmin
     */
    public function __construct(
        public readonly string $ragioneSociale,
        public readonly string $piano,
        public readonly string $scadeIl,
        public readonly int $giorni,
        public readonly bool $perPiattaforma = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Easy Lab · Il comodato d'uso di {$this->ragioneSociale} scade il {$this->scadeIl}")
            ->markdown('mail.comodato-in-scadenza', [
                'marchio' => MarchioEmail::piattaforma(),
                'ragioneSociale' => $this->ragioneSociale,
                'piano' => $this->piano,
                'scadeIl' => $this->scadeIl,
                'giorni' => $this->giorni,
                'perPiattaforma' => $this->perPiattaforma,
                'url' => $this->perPiattaforma ? route('piattaforma.index') : route('abbonamento.index'),
            ]);
    }
}
