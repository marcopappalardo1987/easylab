<?php

use App\Support\Retention;
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
 * 🔴 **La potatura, ed è QUI che una retention smette di essere un'intenzione.**
 *
 * È la «rotazione» che il registro dei trattamenti dichiara per T4 (avvisi) e
 * T8 (error tracker), resa un fatto. L'elenco dei modelli non si scrive a mano
 * su questa riga: arriva da `App\Support\Retention::MODELLI`, e la ragione è
 * che così il legame fra «un model dichiara `prunable()`» e «qualcuno lo pota
 * davvero» diventa **verificabile** — `tests/Feature/RetentionTest.php` legge
 * `app(Schedule::class)->events()` e pretende che questa riga li nomini tutti.
 *
 * ⚠️ **Nessun comando `errors:prune` nuovo**, benché il piano S1 ne volesse uno
 * «da schedulare al deploy»: sarebbe stata la forma esatta del difetto T6 —
 * `clean_after_days => 365` dichiarato in `config/activitylog.php` e
 * `activitylog:clean` mai schedulato, cioè una retention **inerte**. Il
 * `model:prune` di Laravel gira già: i modelli si aggiungono a lui.
 *
 * Gli orizzonti stanno **sui model** (`prunable()`), non qui, perché sono
 * decisioni di dominio e vanno lette accanto ai dati che riguardano: 24 mesi per
 * gli avvisi (servono al comando per non ripetersi), 90 giorni per le occorrenze
 * e per le issue chiuse, 180 per quelle aperte — e `ignorato` mai.
 *
 * Le notifiche in-app (12 mesi qui sotto) non passano di lì: non sono un model,
 * sono posta letta che nessuno riapre dopo un anno.
 */
Schedule::command('model:prune', ['--model' => Retention::MODELLI])
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
