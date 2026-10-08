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
 * Qualcuno ha pianificato un intervento su una macchina del cliente (🔗 ADR-047;
 * ADR-011 le notifiche, ADR-030 chi lavora per il cliente).
 *
 * ## Solo scalari nel costruttore, e non è pigrizia
 *
 * La notifica va in coda e viene resa su un worker, dove i global scope si
 * ritirano e non c'è un utente: un `Intervento` serializzato verrebbe riletto
 * **senza confine**, e ciò che la vista ne stampasse non sarebbe più deciso da
 * chi ha titolo a deciderlo. Chi accoda (`AvvisiIntervento`) legge ciò che
 * serve nel contesto della richiesta e passa qui solo testo. Stessa forma di
 * `PropostaPiano`.
 *
 * ## Marchio del cliente
 *
 * È un'email sul lavoro fatto per quell'Ente, e arriva alle sue persone: porta
 * il suo marchio, risolto dall'**id nel payload** e mai da `auth()` — vedi
 * `DigestScadenze`.
 *
 * ⚠️ Due interruttori, in due posti: quello di piattaforma lo legge chi accoda
 * (`AvvisiIntervento`, e l'email nasce spenta); la rinuncia della persona la
 * legge `via()`.
 */
class InterventoProgrammato extends Notification implements ShouldQueue
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
        public readonly ?string $assegnatario = null,
        public readonly ?string $autore = null,
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
        return InterruttoriEmail::voluta(CatalogoEmail::INTERVENTO_PROGRAMMATO, $notifiable) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Easy Lab · {$this->enteNome}: intervento programmato su {$this->strumentoNome}")
            ->markdown('mail.intervento-programmato', [
                'marchio' => MarchioEmail::perEnte($this->enteId),
                'ente' => $this->enteNome,
                'strumento' => $this->strumentoNome,
                'ubicazione' => $this->ubicazione,
                'tipo' => $this->tipo,
                'descrizione' => $this->descrizione,
                'scadenza' => $this->scadenza,
                'assegnatario' => $this->assegnatario,
                'autore' => $this->autore,
                'url' => route('strumenti.show', $this->strumentoId),
            ]);
    }
}
