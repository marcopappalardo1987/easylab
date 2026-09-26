<?php

namespace App\Jobs;

use App\Notifications\NuovoErrore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * 🔴 Il job che porta fuori l'allerta, e che **non lancia mai** (S6, ADR-017).
 *
 * ## Perché esiste, invece di una notifica `ShouldQueue`
 *
 * La prima stesura accodava direttamente `NuovoErrore` come notifica in coda, e
 * si difendeva dalla ricorsione dentro `failed()`. Non basta, e la ragione è
 * meccanica: **non è `failed()` a scrivere nel tracker, è il worker**.
 *
 * `NotificationSender::sendToNotifiable()` cattura la `Throwable` dell'invio,
 * emette `NotificationFailed` e poi **rilancia** (vendor, `throw $exception;`).
 * L'eccezione arriva a `SendQueuedNotifications`, esce dal job, e
 * `Worker::runJob()` fa `if (static::$reportJobExceptions) { $this->exceptions->report($e); }`
 * — cioè chiama proprio l'aggancio di `CatturaErrori`, in un processo dove la
 * guardia di rientranza non è sullo stack.
 *
 * Riprodotto con una coda `database` e un `queue:work --once` vero: alert
 * fallito → **riga nuova in `errori`** → issue nuova → **secondo alert**.
 * Converge (la seconda issue esiste già e non ri-allerta), ma la frase «un
 * alert fallito non diventa un secondo errore» era **falsa proprio in
 * produzione** — l'unico ambiente in cui la coda è vera.
 *
 * ⚠️ E una correzione tentata nel posto sbagliato, che vale la pena ricordare:
 * si era prima messo un `send()` sulla notifica. **Codice morto**: il mittente
 * di Laravel chiama `$manager->driver($canale)->send(...)`, mai
 * `$notifica->send()`. Il test che lo copriva invocava quel metodo a mano,
 * cioè verificava sé stesso.
 *
 * Qui l'eccezione non esce: si registra su `laravel.log` — il canale primario,
 * che su Cloud si legge con una credenziale d'infrastruttura — e si tace verso
 * il tracker. Il worker non ha nulla da riportare, quindi non riporta.
 */
class InviaAllertaErrore implements ShouldQueue
{
    use FoundationQueueable, Queueable;

    /**
     * ⚠️ `$tries` e `$backoff` **sulla classe**, non nel pannello di Cloud: la
     * managed queue processa una coda sola e i suoi parametri non sono
     * versionati. Stessa disciplina di `InvitoUtente` (21 Ago 2026).
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly string $destinatario,
        private readonly NuovoErrore $allerta,
    ) {}

    public function handle(): void
    {
        try {
            Notification::route('mail', $this->destinatario)->notify($this->allerta);
        } catch (Throwable $guasto) {
            // ⚠️ **Mai `report()` e mai `activity()`**: la prima riaprirebbe il
            // giro che questo job esiste per chiudere, la seconda scriverebbe a
            // database proprio quando l'unica certezza è che qualcosa non
            // funziona. `Log::error` e basta.
            $this->allerta->failed($guasto);
        }
    }
}
