<?php

namespace App\Notifications;

use App\Enums\TransizioneAvviso;
use App\Models\User;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Mail\MarchioEmail;
use App\Support\Notifiche\RigaAvviso;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'«email del futuro» (ADR-011): il riepilogo giornaliero delle scadenze che
 * hanno appena cambiato stato in un Ente.
 *
 * **Digest e non una email per scadenza.** Un Ente con venti garanzie in
 * scadenza produrrebbe venti email nello stesso minuto, che è il modo più veloce
 * per far filtrare il mittente. Il digest raccoglie i soli *cambi di stato* del
 * giorno — ciò che è diventato imminente, ciò che è scaduto — quindi non ripete
 * mai ciò che ha già detto: la memoria è in `avvisi_scadenza`, non qui.
 *
 * **Un digest per Ente, anche per chi ha più Enti.** Il tecnico esterno lavora
 * per più laboratori (🔗 ADR-030) e in un giorno può avere scadenze in due:
 * riceve due email, ciascuna col proprio Ente nell'oggetto. Un digest unico
 * mescolerebbe in un solo documento dati di clienti diversi — cioè farebbe con
 * la posta esattamente ciò che il global scope impedisce nell'applicazione.
 *
 * `ShouldQueue`: l'invio va sulle code Redis (ADR-011), così lo scheduler chiude
 * il proprio giro senza aspettare il server SMTP.
 */
class DigestScadenze extends Notification implements ShouldQueue
{
    use Queueable;

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

    /** Come salutare il referente, se la scheda lo dice. Stessa nota qui sopra. */
    public ?string $nomeReferente = null;

    /**
     * @param  list<RigaAvviso>  $righe
     * @param  bool  $alReferente  `true` per il riepilogo che va al referente
     *                             delle macchine (🔗 ADR-054), alla casella
     *                             scritta sulla loro scheda.
     * @param  ?string  $nomeReferente  come salutarlo, se la scheda lo dice.
     */
    public function __construct(
        public readonly int $enteId,
        public readonly string $enteNome,
        public readonly array $righe,
        bool $alReferente = false,
        ?string $nomeReferente = null,
    ) {
        $this->alReferente = $alReferente;
        $this->nomeReferente = $nomeReferente;
    }

    /**
     * In-app sempre, email solo se l'utente non si è opposto (registro
     * trattamenti T4).
     *
     * La preferenza si legge **qui**, cioè sul worker al momento dell'invio, e
     * non nel comando che accoda: fra l'accodamento e la consegna può passare
     * tempo, e chi ha spento le email nel frattempo ha diritto che valga.
     *
     * La notifica in-app resta sempre: è la copia di ciò che l'utente vedrebbe
     * comunque entrando in applicazione, non un invio verso l'esterno.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        // Dal 9 Ott 2026 (ADR-047) le domande sono due: la piattaforma l'ha
        // accesa, e la persona non vi ha rinunciato. La campanella resta in
        // entrambi i casi: spegnere un'email non spegne ciò che si vede entrando.
        $email = InterruttoriEmail::parte(CatalogoEmail::RIEPILOGO_SCADENZE, $notifiable);

        // 🔗 ADR-054: il referente è una casella, non una persona di Easy Lab.
        // Non ha una campanella, e il canale `database` su un destinatario
        // senza account non ha dove scrivere: farebbe fallire l'invio.
        if (! $notifiable instanceof User) {
            return $email ? ['mail'] : [];
        }

        return $email ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $scadute = $this->righeCon(TransizioneAvviso::Scaduta);
        $imminenti = $this->righeCon(TransizioneAvviso::Imminente);

        return (new MailMessage)
            ->subject($this->oggetto(count($scadute), count($imminenti)))
            ->markdown('mail.digest-scadenze', [
                // 🔴 Il marchio si risolve dall'**id nel payload**, mai da
                // `auth()` o da `CurrentTenant`: questo metodo gira sul worker,
                // dove i global scope si ritirano e dove l'utente autenticato —
                // se per caso ce n'è uno — non ha niente a che vedere con l'Ente
                // di questo digest. L'id è l'unica difesa (🔗 MarchioEmail).
                'marchio' => MarchioEmail::perEnte($this->enteId),
                'ente' => $this->enteNome,
                'scadute' => $scadute,
                'imminenti' => $imminenti,
                'destinatario' => $notifiable,
                'alReferente' => $this->alReferente,
                'nomeReferente' => $this->nomeReferente,
            ]);
    }

    /**
     * Payload della notifica in-app.
     *
     * Porta `ente_id` perché la riga `notifications` non ha `tenant_id`: il
     * contesto Ente vive nel payload, ed è ciò che permetterà alla campanella di
     * restare corretta quando 🔗 ADR-032 introdurrà lo switcher fra Enti.
     *
     * Gli enum si serializzano col loro `value` — è la ragione per cui
     * `TipoMotivoSemaforo` è string-backed fin da S4.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ente_id' => $this->enteId,
            'ente_nome' => $this->enteNome,
            'scadute' => count($this->righeCon(TransizioneAvviso::Scaduta)),
            'imminenti' => count($this->righeCon(TransizioneAvviso::Imminente)),
            'righe' => array_map(fn (RigaAvviso $riga) => $riga->toArray(), $this->righe),
        ];
    }

    /**
     * L'oggetto dice il numero e l'Ente, non «hai nuove notifiche»: è ciò che si
     * legge nell'anteprima del telefono, e deve bastare a decidere se aprire.
     */
    private function oggetto(int $scadute, int $imminenti): string
    {
        $parti = [];
        if ($scadute > 0) {
            $parti[] = $scadute === 1 ? '1 scadenza superata' : "{$scadute} scadenze superate";
        }
        if ($imminenti > 0) {
            $parti[] = $imminenti === 1 ? '1 in arrivo' : "{$imminenti} in arrivo";
        }

        return "Easy Lab · {$this->enteNome}: ".implode(', ', $parti);
    }

    /** @return list<RigaAvviso> */
    private function righeCon(TransizioneAvviso $transizione): array
    {
        return array_values(array_filter(
            $this->righe,
            fn (RigaAvviso $riga) => $riga->transizione === $transizione,
        ));
    }
}
