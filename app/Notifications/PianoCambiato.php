<?php

namespace App\Notifications;

use App\Support\Mail\MarchioEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Il piano di un account è cambiato (🔗 ADR-047; ADR-045 chi lo cambia, ADR-032
 * l'Account intestatario).
 *
 * Parte da `Account::cambiaPiano()`, quindi da **ogni** strada che lo cambia
 * davvero: l'attivazione confermata da Stripe, il cambio dalla pagina
 * «Abbonamento», il ritorno al piano gratuito dopo una disdetta, il comando di
 * console. Non parte alla nascita dell'account, dove il piano non «cambia»: lo
 * dicono già l'invito o il benvenuto.
 *
 * ⚠️ Le etichette arrivano già risolte: sul worker il listino si potrebbe
 * rileggere, ma un piano archiviato fra l'accodamento e la consegna
 * cambierebbe il testo di un fatto già avvenuto.
 *
 * La ricevuta del pagamento resta di Stripe: questa email dice cosa è cambiato
 * in Easy Lab, non quanto è stato addebitato.
 */
class PianoCambiato extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    public function __construct(
        public readonly string $ragioneSociale,
        public readonly string $da,
        public readonly string $a,
    ) {}

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
            ->subject("Easy Lab · Il piano di {$this->ragioneSociale} è cambiato")
            ->markdown('mail.piano-cambiato', [
                'marchio' => MarchioEmail::piattaforma(),
                'ragioneSociale' => $this->ragioneSociale,
                'da' => $this->da,
                'a' => $this->a,
                'url' => route('abbonamento.index'),
            ]);
    }
}
