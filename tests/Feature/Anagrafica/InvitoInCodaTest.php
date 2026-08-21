<?php

use App\Models\User;
use App\Notifications\InvitoUtente;
use App\Support\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * L'invito viaggia in coda (21 Ago 2026 — ADR-011, ADR-012).
 *
 * Il passaggio è deliberato e ribalta ciò che il docblock della notifica
 * diceva: l'**HTTP timeout dell'ambiente è 20 secondi**, e da S6 il provisioning
 * si fa da una pagina web. La transazione committa **prima** dell'invio, quindi
 * un handshake SMTP lento produrrebbe un cliente creato e una risposta persa —
 * e l'operatore non saprebbe se ripetere il gesto.
 *
 * Il prezzo è che chi accoda non vede più il fallimento. Questi test tengono
 * insieme le due metà: che sia davvero in coda, e che un fallimento resti
 * visibile a qualcuno.
 */
it('hands the invitation to the queue instead of the request', function () {
    Queue::fake();

    $utente = User::factory()->create();
    $utente->notify(new InvitoUtente('Ospedale San Giovanni'));

    Queue::assertPushed(SendQueuedNotifications::class);
});

it('hands its retry policy to the job Laravel actually runs', function () {
    // ⚠️ **Asserito sul job, non sulla notifica.** La prima stesura leggeva
    // `$invito->tries` e `$invito->backoff`: una tautologia: se avessi scritto
    // `$maxTries` — nome che Laravel ignora — il test sarebbe stato identico e
    // sarebbe passato mentre il worker usava i default. La domanda vera è se il
    // job li **riceva**, ed è `SendQueuedNotifications` a girare.
    $job = new SendQueuedNotifications(User::factory()->create(), new InvitoUtente('Ospedale San Giovanni'));

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300]);
});

it('declares its retries in the repository, not in the Cloud panel', function () {
    // ⚠️ Le stesse opzioni esistono come flag del worker (`--tries`,
    // `--backoff`), ma lì vivrebbero **solo** nella console di Cloud: fuori da
    // ogni file, fuori da ogni revisione, invisibili a chi legge questa classe.
    // È la stessa forma di problema delle migration non applicate al DB di
    // sviluppo e di `config/rbac.php` non riseminato — ciò che non vive nel
    // repository non viene allineato da niente. Sul job, inoltre, queste
    // vincono sui flag del worker.
    $invito = new InvitoUtente('Ospedale San Giovanni');

    expect($invito)->toBeInstanceOf(ShouldQueue::class)
        ->and($invito->tries)->toBe(3)
        ->and($invito->backoff)->toBe([60, 300]);
});

it('leaves a trail where somebody actually looks when the invitation dies', function () {
    // 🔴 Il pezzo che ripaga il passaggio in coda. Da sincrono, il fallimento
    // tornava al chiamante e finiva sotto gli occhi dell'operatore; accodato,
    // finirebbe in `failed_jobs` — che su un progetto a un solo sviluppatore
    // non guarda nessuno, e il sintomo sarebbe un cliente che «non ha ricevuto
    // niente» settimane dopo.
    // ⚠️ Passa da `SendQueuedNotifications`, che è **la strada vera**: è quel
    // job a chiamare `failed()` sulla notifica, e solo se il metodo esiste
    // (`method_exists`). Chiamandolo a mano, il test resterebbe verde anche se
    // il metodo si chiamasse `fallito()` e Laravel non lo trovasse mai.
    $utente = User::factory()->create();

    (new SendQueuedNotifications($utente, new InvitoUtente('Ospedale San Giovanni', 'anna@aurora.test')))
        ->failed(new RuntimeException('SMTP irraggiungibile'));

    $riga = Activity::where('log_name', AuditLog::NAME)->first();

    expect($riga)->not->toBeNull()
        ->and($riga->description)->toBe('Invito NON consegnato')
        ->and($riga->properties['ente'])->toBe('Ospedale San Giovanni')
        ->and($riga->properties['errore'])->toBe('SMTP irraggiungibile')
        ->and($riga->properties['tentativi'])->toBe(3)
        // L'indirizzo è la sola cosa che rende la riga **azionabile**: senza,
        // si sa che un invito è morto ma non a chi vada rimandato.
        ->and($riga->properties['destinatario'])->toBe('anna@aurora.test');
});

it('still builds a real mail, signed at delivery time and not at queueing time', function () {
    // Da sincrono, una vista mail rotta esplodeva davanti all'operatore; ora
    // esploderebbe sul worker. E la firma si costruisce dentro `toMail()`, cioè
    // **quando il job gira**: i sette giorni partono dall'invio vero, quindi un
    // ritardo di coda o un retry a +5 minuti non erodono la finestra
    // dell'invitato.
    $utente = User::factory()->create();

    $mail = (new InvitoUtente('Ospedale San Giovanni', $utente->email))->toMail($utente);

    expect($mail->subject)->toBe('Easy Lab · Invito per Ospedale San Giovanni')
        ->and($mail->viewData['url'])->toContain('expires=')
        ->and($mail->viewData['giorni'])->toBe(InvitoUtente::GIORNI_VALIDITA);
});
