<?php

namespace App\Notifications;

use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Mail\MarchioEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Un intervento è stato assegnato a questa persona (🔗 ADR-047; ADR-030 il
 * secondo canale del tecnico, ADR-038 chi è assegnabile).
 *
 * È l'email che mancava fra «te l'ho assegnato» e il riepilogo delle 06:00,
 * che arriva solo quando la scadenza si avvicina: fino al 9 Ott 2026 un
 * tecnico scopriva un lavoro nuovo aprendo «Campo».
 *
 * Stesse regole delle altre due: solo scalari, marchio dell'Ente dall'id nel
 * payload (il tecnico di EasyLab lavora per più clienti, e ogni email dice per
 * quale), interruttore di piattaforma a chi accoda e rinuncia della persona in
 * `via()`.
 */
class InterventoAssegnato extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    public function __construct(
        public readonly int $enteId,
        public readonly string $enteNome,
        public readonly int $strumentoId,
        public readonly string $strumentoNome,
        public readonly string $ubicazione,
        public readonly string $tipo,
        public readonly string $descrizione,
        public readonly string $scadenza,
        public readonly ?string $assegnatoDa = null,
    ) {}

    /**
     * Solo email, e solo a chi non vi ha rinunciato dalle proprie preferenze.
     *
     * ⚠️ L'interruttore di **piattaforma** non si chiede qui: lo decide
     * `AvvisiIntervento`, che è l'unico a costruire questa notifica e che,
     * finché l'email è spenta, non cerca nemmeno i destinatari. Due posti per
     * la stessa domanda sarebbero due posti liberi di rispondere diversamente.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return InterruttoriEmail::voluta(CatalogoEmail::INTERVENTO_ASSEGNATO, $notifiable) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Easy Lab · {$this->enteNome}: ti è stato assegnato un intervento su {$this->strumentoNome}")
            ->markdown('mail.intervento-assegnato', [
                'marchio' => MarchioEmail::perEnte($this->enteId),
                'ente' => $this->enteNome,
                'strumento' => $this->strumentoNome,
                'ubicazione' => $this->ubicazione,
                'tipo' => $this->tipo,
                'descrizione' => $this->descrizione,
                'scadenza' => $this->scadenza,
                'assegnatoDa' => $this->assegnatoDa,
                'url' => route('strumenti.show', $this->strumentoId),
            ]);
    }
}
