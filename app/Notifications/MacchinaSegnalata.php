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
 * Qualcuno ha segnalato una macchina: il semaforo è stato portato a mano su
 * «Azione richiesta» o «Non idoneo» (🔗 ADR-047, aggiunta del 9 Ott 2026;
 * ADR-005 la forzatura del semaforo).
 *
 * È l'avviso «questa macchina ha bisogno di un intervento» che non dipende dal
 * calendario: una scadenza che si avvicina la racconta il riepilogo delle
 * 06:00, un guasto dichiarato oggi non poteva aspettarlo — e fino al 9 Ott
 * 2026 non lo raccontava nessuno.
 *
 * Stesse regole delle email sugli interventi, e per le stesse ragioni: solo
 * scalari nel costruttore (va in coda, e sul worker un model verrebbe riletto
 * senza confine), marchio del cliente risolto dall'id nel payload,
 * interruttore di piattaforma a chi accoda (`AvvisiStrumento`) e rinuncia
 * della persona in `via()`.
 *
 * ⚠️ **Il motivo esce**, a differenza del report di fine lavoro: è una riga
 * scritta apposta per dire agli altri cosa non va, ed è la sola cosa che rende
 * l'avviso utilizzabile. Lo vedono le stesse persone che lo leggono già sulla
 * scheda della macchina.
 */
class MacchinaSegnalata extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    /**
     * @param  bool  $nonIdonea  `true` per il rosso («Non idoneo»), `false` per
     *                           l'arancione («Azione richiesta»): cambia
     *                           l'oggetto e la prima riga.
     */
    public function __construct(
        public readonly int $enteId,
        public readonly string $enteNome,
        public readonly int $strumentoId,
        public readonly string $strumentoNome,
        public readonly string $ubicazione,
        public readonly string $stato,
        public readonly bool $nonIdonea,
        public readonly ?string $motivo = null,
        public readonly ?string $autore = null,
    ) {}

    /**
     * Solo email, e solo a chi non vi ha rinunciato. L'interruttore di
     * piattaforma lo legge `AvvisiStrumento`, che è l'unico a costruirla.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return InterruttoriEmail::voluta(CatalogoEmail::MACCHINA_SEGNALATA, $notifiable) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $cosa = $this->nonIdonea ? 'non è idonea' : 'richiede un intervento';

        return (new MailMessage)
            ->subject("Easy Lab · {$this->enteNome}: {$this->strumentoNome} {$cosa}")
            ->markdown('mail.macchina-segnalata', [
                'marchio' => MarchioEmail::perEnte($this->enteId),
                'ente' => $this->enteNome,
                'strumento' => $this->strumentoNome,
                'ubicazione' => $this->ubicazione,
                'stato' => $this->stato,
                'nonIdonea' => $this->nonIdonea,
                'motivo' => $this->motivo,
                'autore' => $this->autore,
                'url' => route('strumenti.show', $this->strumentoId),
            ]);
    }
}
