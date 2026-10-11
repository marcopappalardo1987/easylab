<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Mail\MarchioEmail;
use App\Support\Notifiche\RigaObsolescenza;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Queste macchine hanno superato la soglia di età del tuo Ente» (🔗 ADR-014).
 *
 * **Una notifica per Ente con l'elenco intero, non una per macchina.** Un
 * abbassamento di soglia può far attraversare la linea a decine di macchine
 * nello stesso istante: altrettante email nello stesso minuto sarebbero il modo
 * più veloce per far filtrare il mittente. È la stessa scelta di
 * `DigestScadenze`, e per la stessa ragione.
 *
 * **Notifica separata dal digest, e non un suo terzo blocco.** L'obsolescenza
 * non è una scadenza: non ha «imminente» e non ha «scaduto», non blocca la
 * manutenzione e non tocca il semaforo (ADR-024). Fonderla nel digest avrebbe
 * richiesto un quarto caso in `TipoMotivoSemaforo`, cioè un `UnhandledMatchError`
 * dentro il corpo di un'email già spedita.
 *
 * ⚠️ **Nessuna lettura di permessi o ruoli qui dentro**: questa classe gira nel
 * worker ed è sorvegliata da `PermessiInCodaGuardrailTest`, che tiene vera
 * l'affermazione di ADR-016 «nessun job legge la matrice». La scelta dei
 * destinatari è già stata fatta a monte, in `AvvisiObsolescenza`, che gira
 * sincrono apposta.
 *
 * Nessun dato protetto viaggia qui: nome della macchina, data di installazione
 * ed età. Nessuna garanzia e nessun ricambio, quindi **ADR-029 non entra in
 * gioco** — non c'è un filtro da ricordarsi perché non c'è un dato da nascondere.
 */
class AvvisoObsolescenza extends Notification implements ShouldQueue
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
     * @param  list<RigaObsolescenza>  $righe
     * @param  bool  $alReferente  `true` per l'avviso che va al referente delle
     *                             macchine (🔗 ADR-054), alla casella scritta
     *                             sulla loro scheda.
     * @param  ?string  $nomeReferente  come salutarlo, se la scheda lo dice.
     */
    public function __construct(
        public readonly int $enteId,
        public readonly string $enteNome,
        public readonly int $soglia,
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
     * **Riusa `riceve_email_scadenze` invece di introdurre una preferenza
     * nuova**, e non per risparmiare una migration: quel flag è il diritto di
     * opposizione alle «email programmate operative», e questa è un'email
     * programmata operativa. Mandarla a chi le ha spente rispetterebbe la
     * lettera della preferenza violandone la sostanza.
     *
     * La preferenza si legge **qui**, cioè sul worker al momento dell'invio, e
     * non nel servizio che accoda: fra l'accodamento e la consegna può passare
     * tempo, e chi ha spento le email nel frattempo ha diritto che valga.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        // Dal 9 Ott 2026 (ADR-047) le domande sono due: la piattaforma l'ha
        // accesa, e la persona non vi ha rinunciato. La campanella resta in
        // entrambi i casi: spegnere un'email non spegne ciò che si vede entrando.
        $email = InterruttoriEmail::parte(CatalogoEmail::AVVISO_OBSOLESCENZA, $notifiable);

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
        return (new MailMessage)
            ->subject($this->oggetto())
            ->markdown('mail.avviso-obsolescenza', [
                // Stessa regola del digest: l'id dal payload, mai il tenant di
                // chi capita di essere autenticato quando il job gira.
                'marchio' => MarchioEmail::perEnte($this->enteId),
                'ente' => $this->enteNome,
                'soglia' => $this->soglia,
                'righe' => $this->righe,
                'destinatario' => $notifiable,
                'alReferente' => $this->alReferente,
                'nomeReferente' => $this->nomeReferente,
            ]);
    }

    /**
     * Payload della notifica in-app.
     *
     * Porta `ente_id` per la stessa ragione di `DigestScadenze`: la riga
     * `notifications` non ha `tenant_id`, quindi il contesto Ente vive nel
     * payload. La chiave **`obsoleti`** è quella che la campanella legge per
     * distinguere questo avviso dal digest, che porta `scadute`/`imminenti`.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ente_id' => $this->enteId,
            'ente_nome' => $this->enteNome,
            'soglia' => $this->soglia,
            'obsoleti' => count($this->righe),
            'righe' => array_map(fn (RigaObsolescenza $riga) => $riga->toArray(), $this->righe),
        ];
    }

    /**
     * L'oggetto dice quante macchine e su quale soglia: è ciò che si legge
     * nell'anteprima del telefono, e deve bastare a decidere se aprire.
     */
    private function oggetto(): string
    {
        $quante = count($this->righe);

        $parte = $quante === 1
            ? '1 macchina oltre i '
            : "{$quante} macchine oltre i ";

        return "Easy Lab · {$this->enteNome}: ".$parte."{$this->soglia} anni";
    }
}
