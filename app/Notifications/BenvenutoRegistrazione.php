<?php

namespace App\Notifications;

use App\Support\AuditLog;
use App\Support\Mail\MarchioEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Il pagamento è andato a buon fine e l'account esiste (🔗 ADR-012, ADR-032).
 *
 * ⛔ **Nessun link firmato qui dentro, e non è un'omissione.** L'utente esiste,
 * ha `email_verified_at` valorizzato e conosce la password che ha scelto al
 * modulo: l'unica porta è `/login`, cioè la stessa di tutti. Un link che
 * autentica sarebbe una seconda strada d'accesso — più debole di quella
 * ordinaria — a un account che ha il 2FA obbligatorio per ruolo.
 *
 * ⚠️ **Al primo accesso `two-factor.enforce` porta su `/settings/security`**, e
 * l'email lo dice: il ruolo `Admin` è fra i `two_factor_required_roles`, quindi
 * chi entra non atterra sulla dashboard. È il comportamento voluto, ma senza
 * questa riga somiglia a un guasto.
 *
 * **Marchio di piattaforma** e non dell'Ente appena nato: la prima email dopo
 * il pagamento arriva da Easy Lab, e il marchio del cliente si carica dopo, da
 * `/anagrafica/marchio` (ADR-033) — a quel punto le email successive lo
 * portano. Passare `null` a `MarchioEmail::perEnte()` darebbe lo stesso esito;
 * dirlo per nome evita di far credere che sia un ripiego.
 */
class BenvenutoRegistrazione extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    public function __construct(
        public readonly string $enteNome,
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
            ->subject("Easy Lab · {$this->enteNome} è attivo")
            ->markdown('mail.benvenuto-registrazione', [
                'marchio' => MarchioEmail::piattaforma(),
                'ente' => $this->enteNome,
                'email' => $this->destinatario,
                'url' => Route::has('login') ? route('login') : config('app.url'),
            ]);
    }

    /**
     * ⚠️ **Il fallimento qui è peggiore di quello dell'invito**, perché il
     * cliente ha già pagato: l'account c'è, e lui non sa che c'è. La riga di
     * audit e il `Log::error` portano quindi il destinatario, che è l'unica
     * cosa che rende azionabile il guasto.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Benvenuto di registrazione non consegnato', [
            'destinatario' => $this->destinatario,
            'ente' => $this->enteNome,
            'errore' => $e->getMessage(),
        ]);

        activity(AuditLog::NAME)
            ->withProperties([
                'destinatario' => $this->destinatario,
                'ente' => $this->enteNome,
                'errore' => $e->getMessage(),
                'tentativi' => $this->tries,
            ])
            ->log('Benvenuto di registrazione NON consegnato');
    }
}
