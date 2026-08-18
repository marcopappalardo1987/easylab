<?php

use App\Models\AvvisoScadenza;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Lo scheduler dell'«email del futuro» (ADR-011).
 *
 * Non serve alcun crontab da documentare: Laravel Cloud (🔗 ADR-025) fornisce
 * scheduler e worker di coda come parte della piattaforma — va solo attivato
 * nell'ambiente.
 *
 * **06:00 sul fuso di Roma** e non «all'alba UTC»: il digest parla di giorni
 * (scaduto ieri, scade fra trenta giorni) e l'applicazione calcola `today()` in
 * UTC. Alle 06:00 italiane le due date coincidono sempre, in ora solare come
 * legale, mentre un orario a cavallo della mezzanotte le farebbe divergere per
 * un'ora l'anno — cioè un giorno in cui il confine «scade oggi» direbbe una cosa
 * all'email e un'altra alla schermata.
 *
 * `withoutOverlapping` e `onOneServer` sono cinture doppie e volute: se un
 * giorno l'esecuzione durasse più di ventiquattr'ore, o se Laravel Cloud
 * scalasse a due istanze, il lock eviterebbe il doppio giro. L'idempotenza però
 * NON dipende da loro — vive in `avvisi_scadenza` — perché una garanzia di
 * consegna basata su un lock è una garanzia che cade insieme alla cache.
 */
Schedule::command('easylab:notifica-scadenze')
    ->dailyAt('06:00')
    ->timezone('Europe/Rome')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Rotazione dei dati di notifica: è la «rotazione» che il registro dei
 * trattamenti dichiara per T4, resa un fatto invece che un'intenzione.
 *
 * Due orizzonti diversi perché rispondono a due domande diverse: gli avvisi (24
 * mesi) servono al comando per non ripetersi, le notifiche in-app (12 mesi) sono
 * posta letta che nessuno riapre dopo un anno.
 */
Schedule::command('model:prune', ['--model' => [AvvisoScadenza::class]])
    ->dailyAt('03:30')
    ->timezone('Europe/Rome')
    ->onOneServer();

Schedule::call(fn () => DB::table('notifications')
    ->where('created_at', '<', now()->subMonths(12))
    ->delete())
    ->name('notifiche-rotazione')
    ->dailyAt('03:35')
    ->timezone('Europe/Rome')
    ->onOneServer();
