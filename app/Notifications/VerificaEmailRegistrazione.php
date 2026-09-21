<?php

namespace App\Notifications;

use App\Models\Registrazione;
use App\Support\AuditLog;
use App\Support\Mail\MarchioEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Il link che verifica la casella di chi si sta registrando (🔗 ADR-012).
 *
 * ⚠️ **È la sola prova che quell'indirizzo è suo**, ed è per questo che sta
 * *prima* del pagamento e non dopo: il checkout preriempie l'email sul customer
 * di Stripe, e aprirlo su una casella non verificata significherebbe far
 * nascere un account intestato a qualcuno che non ha mai chiesto niente. La
 * guardia meccanica è in `RegistrazionePubblica::versoStripe()`, che rifiuta il
 * POST con `email_verificata_at` nullo **anche a firma valida**: la verifica è
 * obbligatoria, non soltanto cronologicamente prima.
 *
 * **Marchio di piattaforma, sempre**: qui un Ente non esiste ancora — è
 * precisamente ciò che deve nascere — quindi non c'è nessun logo di cliente da
 * mettere in testata. Vedi `MarchioEmail`, dove il fail-closed *è* Easy Lab.
 *
 * ⚠️ **Notifica on-demand**: non c'è nessun `User` a cui appendere `notify()` —
 * la persona non esiste nel dominio. Il notifiable è la riga `Registrazione`,
 * che porta `Notifiable` e `routeNotificationForMail()`.
 *
 * `ShouldQueue`, `$tries`, `$backoff` e `failed()` copiati da `InvitoUtente` e
 * per le stesse ragioni, che là sono scritte per esteso: l'HTTP timeout
 * dell'ambiente è di 20 secondi e questo invio parte da una **pagina web**, e
 * un fallimento accodato non lo vedrebbe nessuno. Da qui la regola che i
 * chiamanti rispettano: si dice «in consegna», mai «inviata».
 */
class VerificaEmailRegistrazione extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var list<int> */
    public $backoff = [60, 300];

    /**
     * ⚠️ **Un'ora, non sette giorni come l'invito.** Le due scadenze rispondono
     * a due domande diverse: l'invito deve sopravvivere a un fine settimana
     * perché l'account **esiste già** e il link è l'unica strada per entrarci;
     * qui il link non apre niente che esista — apre un pagamento — e chi si è
     * appena registrato ha la casella davanti. Una finestra corta riduce la
     * vita di un URL che, se intercettato, porta a un checkout intestato a
     * un'altra persona. Chi la lascia scadere ripete il modulo: il gesto è
     * ripetibile per costruzione (la riga pendente si **riusa**), e ripeterlo
     * costa un minuto.
     */
    public const ORE_VALIDITA = 1;

    public function __construct(
        public readonly string $destinatario = '',
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // `mail` secco: è una mail transazionale e non una comunicazione
        // ricorrente, quindi non passa dall'opt-out del digest (registro T4).
        // Nessun canale `database`: la campanella la vedrebbe solo chi è già
        // dentro, e qui nessuno lo è.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var Registrazione $notifiable */
        $url = URL::temporarySignedRoute(
            'registrazione.verifica',
            now()->addHours(self::ORE_VALIDITA),
            // L'impronta cambia a ogni riscrittura della riga: un nuovo invio
            // del modulo spegne i link spediti prima (🔗 Registrazione::improntaVerifica).
            ['registrazione' => $notifiable->getKey(), 'impronta' => $notifiable->improntaVerifica()],
        );

        return (new MailMessage)
            ->subject('Easy Lab · Conferma il tuo indirizzo email')
            ->markdown('mail.verifica-registrazione', [
                'marchio' => MarchioEmail::piattaforma(),
                'nome' => $notifiable->nome_referente,
                'ente' => $notifiable->nome_ente,
                'url' => $url,
                'ore' => self::ORE_VALIDITA,
            ]);
    }

    /**
     * Il link non è partito, e nessuno lo sta guardando: stessa forma di
     * `InvitoUtente::failed()`, e per la stessa ragione — `failed_jobs` è una
     * tabella senza lettore.
     *
     * ⚠️ **Il destinatario si porta dentro la notifica**: `failed()` riceve
     * solo l'eccezione, non il notifiable, quindi senza questo campo la riga
     * direbbe «una verifica è morta» senza dire a chi.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Verifica email di registrazione non consegnata', [
            'destinatario' => $this->destinatario,
            'errore' => $e->getMessage(),
        ]);

        activity(AuditLog::NAME)
            ->withProperties([
                'destinatario' => $this->destinatario,
                'errore' => $e->getMessage(),
                'tentativi' => $this->tries,
            ])
            ->log('Verifica email di registrazione NON consegnata');
    }
}
