<?php

namespace App\Notifications;

use App\Support\AuditLog;
use App\Support\Mail\MarchioEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La cabina ha creato un cliente e gli propone un piano a pagamento: questa è
 * l'email che glielo dice, e che lo porta a pagarlo (🔗 ADR-045, ADR-012 il
 * provisioning, ADR-032 l'Account intestatario).
 *
 * ## ⛔ Nessun link di pagamento qui dentro, e non è un'omissione
 *
 * Una sessione di Checkout su Stripe vive **un giorno**: spedita in un'email
 * sarebbe morta lunedì mattina. E un link firmato che apre un pagamento senza
 * login sarebbe una superficie pubblica nuova, per un gesto che il cliente può
 * compiere dalla propria pagina. Il bottone porta a `/abbonamento`: chi non è
 * ancora entrato passa dal login, e Fortify lo riporta lì.
 *
 * ⚠️ **È una SECONDA email, accanto all'invito**, e lo dice: chi nasce dalla
 * cabina non ha ancora una password, quindi la frase nomina l'invito come
 * primo passo. Tenerle separate lascia `InvitoUtente` e `ProvisionaEnte` come
 * sono — e arriva anche a chi un accesso lo aveva già, e l'invito non lo riceve.
 *
 * ⚠️ **La cifra è l'importo del Price corrente** al momento della proposta, non
 * il listino: è ciò che il checkout addebiterà. Se il prezzo cambia prima che
 * il cliente paghi, la pagina mostra quello nuovo — per questo la mail dice
 * «oggi».
 *
 * **Marchio di piattaforma**: chi propone un piano è Easy Lab, non l'Ente
 * appena nato.
 *
 * `ShouldQueue` come `InvitoUtente`, per la stessa ragione: il gesto in cabina
 * non deve aspettare un SMTP.
 */
class PropostaPiano extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    public function __construct(
        public readonly string $ragioneSociale,
        public readonly string $etichettaPiano,
        public readonly int $importoCent,
        public readonly string $destinatario = '',
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Easy Lab · Piano da attivare per {$this->ragioneSociale}")
            ->markdown('mail.proposta-piano', [
                'marchio' => MarchioEmail::piattaforma(),
                'ragioneSociale' => $this->ragioneSociale,
                'piano' => $this->etichettaPiano,
                'prezzo' => number_format($this->importoCent / 100, 2, ',', '.'),
                'url' => route('abbonamento.index'),
            ]);
    }

    /**
     * La proposta non è partita, e nessuno la sta guardando: stessa forma di
     * `InvitoUtente::failed()`. Il cliente è nato e il piano proposto è scritto
     * sull'account — manca solo che qualcuno glielo dica, e il registro è il
     * posto in cui la cabina se ne accorge.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Proposta di piano non consegnata', [
            'destinatario' => $this->destinatario,
            'cliente' => $this->ragioneSociale,
            'piano' => $this->etichettaPiano,
            'errore' => $e->getMessage(),
        ]);

        // ⚠️ Senza `causedBy()`: nel worker non c'è un utente autenticato.
        activity(AuditLog::NAME)
            ->withProperties([
                'destinatario' => $this->destinatario,
                'cliente' => $this->ragioneSociale,
                'piano' => $this->etichettaPiano,
                'errore' => $e->getMessage(),
                'tentativi' => $this->tries,
            ])
            ->log('Proposta di piano NON consegnata');
    }
}
