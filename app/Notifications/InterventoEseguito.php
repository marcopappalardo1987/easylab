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
 * Un intervento è stato chiuso come eseguito (🔗 ADR-047; ADR-011, ADR-030).
 *
 * Stesse tre regole di `InterventoProgrammato`, e per le stesse ragioni: solo
 * scalari nel costruttore, marchio del cliente risolto dall'id nel payload,
 * interruttore di piattaforma a chi accoda e rinuncia della persona in `via()`.
 *
 * ⚠️ **Il report di fine lavoro non esce.** È testo libero lungo fino a
 * cinquemila caratteri, scritto per chi apre la scheda: in un'email resterebbe
 * in una casella e verrebbe inoltrato a chi decide il destinatario. L'email
 * dice che c'è, e porta alla scheda dove si legge dietro il login.
 *
 * `prossima` è la data della taratura successiva, quando chi chiude la
 * pianifica nello stesso gesto: si dice qui, invece di mandare una seconda
 * email «programmato» un secondo dopo.
 */
class InterventoEseguito extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    /**
     * `true` per la copia che va al referente della macchina (🔗 ADR-054).
     *
     * ⚠️ Proprietà **con un default**, e non un parametro promosso come le
     * altre: una notifica accodata prima del rilascio non la porta nel proprio
     * payload, `unserialize` non passa dal costruttore, e una proprietà
     * tipizzata senza default resterebbe non inizializzata — l'email in coda
     * in quel momento fallirebbe leggendola.
     */
    public bool $alReferente = false;

    /**
     * @param  bool  $alReferente  `true` per la copia che va al referente della
     *                             macchina (🔗 ADR-054): non è una persona di
     *                             Easy Lab, e l'ultima riga glielo dice invece
     *                             di mandarlo a delle preferenze che non ha.
     */
    public function __construct(
        public readonly int $enteId,
        public readonly string $enteNome,
        public readonly int $strumentoId,
        public readonly string $strumentoNome,
        public readonly string $ubicazione,
        public readonly string $tipo,
        public readonly string $descrizione,
        public readonly string $eseguitoIl,
        public readonly ?string $autore = null,
        public readonly bool $conReport = false,
        public readonly ?string $prossima = null,
        bool $alReferente = false,
    ) {
        $this->alReferente = $alReferente;
    }

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
        return InterruttoriEmail::voluta(CatalogoEmail::INTERVENTO_ESEGUITO, $notifiable) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Easy Lab · {$this->enteNome}: intervento eseguito su {$this->strumentoNome}")
            ->markdown('mail.intervento-eseguito', [
                'marchio' => MarchioEmail::perEnte($this->enteId),
                'ente' => $this->enteNome,
                'strumento' => $this->strumentoNome,
                'ubicazione' => $this->ubicazione,
                'tipo' => $this->tipo,
                'descrizione' => $this->descrizione,
                'eseguitoIl' => $this->eseguitoIl,
                'autore' => $this->autore,
                'conReport' => $this->conReport,
                'prossima' => $this->prossima,
                'alReferente' => $this->alReferente,
                'url' => route('strumenti.show', $this->strumentoId),
            ]);
    }
}
