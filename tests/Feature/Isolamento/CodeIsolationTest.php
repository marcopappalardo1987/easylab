<?php

use App\Models\AvvisoScadenza;
use App\Models\User;
use App\Notifications\DigestScadenze;
use App\Notifications\InvitoUtente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Isolamento\Support\MondoDueEnti;

/**
 * Code e job (ADR-001/011). Il worker non ha un utente, quindi nessun global
 * scope: il contesto dell'Ente NON viaggia come «tenant corrente» ma come id
 * nel payload (`enteId`), e le righe del digest sono una fotografia presa
 * all'accodamento. Qui si prova che quel contratto regge nei due casi ostili:
 * il job eseguito senza nessun contesto, e il job eseguito mentre il contesto
 * corrente è quello dell'Ente B (worker che ha appena servito B, o comando
 * lanciato da una richiesta web di B).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();

    // Il mondo nasce con gli avvisi già registrati: si svuota il registro
    // perché il comando abbia qualcosa da dire a entrambi gli Enti.
    AvvisoScadenza::withoutGlobalScopes()->delete();

    $this->adminA = $this->mondo->riga('A', 'admin');
    $this->adminB = $this->mondo->riga('B', 'admin');
});

/** Il digest che il comando ha accodato per un utente (fotografia del payload). */
function digestAccodatoPer(User $utente): DigestScadenze
{
    $inviate = Notification::sent($utente, DigestScadenze::class);
    expect($inviate)->toHaveCount(1);

    return $inviate->first();
}

/**
 * Serializza la notifica come fa la coda, la deserializza come fa il worker e
 * la esegue col contesto dato: `null` = nessun utente, altrimenti quell'utente.
 *
 * @return string HTML dell'email davvero consegnata al mailer
 */
function eseguiSulWorker(User $destinatario, object $notifica, ?User $contesto): string
{
    $job = unserialize(serialize(new SendQueuedNotifications($destinatario, $notifica, ['mail'])));

    Auth::logout();
    if ($contesto !== null) {
        test()->actingAs($contesto);
    }

    $trasporto = app('mailer')->getSymfonyTransport();
    $trasporto->flush();

    $job->handle(new ChannelManager(app()));

    $messaggi = $trasporto->messages();
    expect($messaggi)->toHaveCount(1);

    return (string) $messaggi->first()->getOriginalMessage()->getHtmlBody();
}

it('builds each digest with the rows of its own Ente only, in the console context of the scheduler', function () {
    Notification::fake();

    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();

    $digestA = digestAccodatoPer($this->adminA);
    $digestB = digestAccodatoPer($this->adminB);

    expect($digestA->enteId)->toBe($this->mondo->riga('A', 'ente')->id)
        ->and(collect($digestA->righe)->pluck('strumentoNome')->unique()->values()->all())
        ->toEqualCanonicalizing(['Strumento-SEGRETO-A', 'StrumentoNonAssegnato-A'])
        ->and($digestB->enteId)->toBe($this->mondo->riga('B', 'ente')->id)
        ->and(serialize($digestA))->not->toContain('SEGRETO-B')
        ->and(serialize($digestB))->not->toContain('SEGRETO-A');
});

it('narrows, and never widens, when the command runs with the context of B still authenticated', function () {
    // Il comando è pensato per la console (nessun utente, nessuno scope). Se un
    // giorno partisse con un utente ambientale — un Artisan::call da una
    // richiesta web, un job che fa login — i global scope si riaccendono sul
    // tenant di quell'utente: gli altri Enti restano senza digest (fail-closed),
    // ma nessun digest riceve righe di un Ente diverso dal proprio.
    Notification::fake();
    $this->actingAs($this->adminB);

    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();

    Notification::assertNotSentTo($this->adminA, DigestScadenze::class);
    $digestB = digestAccodatoPer($this->adminB);
    expect($digestB->enteId)->toBe($this->mondo->riga('B', 'ente')->id)
        ->and(serialize($digestB))->not->toContain('SEGRETO-A');
});

it('delivers the digest of A with the brand and rows of A, whatever the context on the worker', function (string $contesto) {
    Notification::fake();
    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();
    $digestA = digestAccodatoPer($this->adminA);

    $html = eseguiSulWorker($this->adminA, $digestA, $contesto === 'B' ? $this->adminB : null);

    expect($html)->toContain('Strumento-SEGRETO-A')
        ->and($html)->toContain('Ente-SEGRETO-A');
    foreach (MondoDueEnti::marcatori('B') as $marcatore) {
        expect($html)->not->toContain($marcatore);
    }
})->with(['senza contesto' => 'nessuno', 'col contesto di B' => 'B']);

it('writes the in-app copy of the digest with the Ente of the payload, not of the worker context', function () {
    Notification::fake();
    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();
    $digestA = digestAccodatoPer($this->adminA);

    $job = unserialize(serialize(new SendQueuedNotifications($this->adminA, $digestA, ['database'])));
    $this->actingAs($this->adminB);
    $job->handle(new ChannelManager(app()));

    $riga = $this->adminA->notifications()->sole();
    expect($riga->data['ente_id'])->toBe($this->mondo->riga('A', 'ente')->id)
        ->and(json_encode($riga->data))->not->toContain('SEGRETO-B');
    expect($this->adminB->notifications()->count())->toBe(0);
});

it('delivers an invitation queued by A with the brand of A, whatever the context on the worker', function (string $contesto) {
    $enteA = $this->mondo->riga('A', 'ente');
    $invitato = User::factory()->unverified()->create(['name' => 'Invitato di A']);

    $html = eseguiSulWorker(
        $invitato,
        new InvitoUtente($enteA->nome, $invitato->email, $enteA->id),
        $contesto === 'B' ? $this->adminB : null,
    );

    expect($html)->toContain('Ente-SEGRETO-A');
    foreach (MondoDueEnti::marcatori('B') as $marcatore) {
        expect($html)->not->toContain($marcatore);
    }
})->with(['senza contesto' => 'nessuno', 'col contesto di B' => 'B']);

it('carries the Ente as an id in the queued payload, never as ambient state', function () {
    Notification::fake();
    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();

    $payload = serialize(new SendQueuedNotifications($this->adminA, digestAccodatoPer($this->adminA), ['mail']));

    // L'id dell'Ente è nel payload; l'utente autenticato al momento
    // dell'accodamento no (non c'è un campo «tenant corrente» da ripristinare).
    expect($payload)->toContain('enteId";i:'.$this->mondo->riga('A', 'ente')->id)
        ->and($payload)->not->toContain('Ente-SEGRETO-B');
});

it('delivers the digest of A with the brand of A on the sync queue, sent from inside the context of B', function () {
    Notification::fake();
    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();
    $digestA = digestAccodatoPer($this->adminA);

    // Coda sync (phpunit.xml): il job gira nella stessa richiesta, quindi col
    // contesto di chi lo accoda. Qui chi accoda è B, e il destinatario è A.
    Notification::swap(new ChannelManager(app()));
    expect(config('queue.default'))->toBe('sync');
    $this->actingAs($this->adminB);
    $trasporto = app('mailer')->getSymfonyTransport();
    $trasporto->flush();

    Notification::send($this->adminA, $digestA);

    $html = (string) $trasporto->messages()->sole()->getOriginalMessage()->getHtmlBody();
    expect($html)->toContain('Ente-SEGRETO-A');
    foreach (MondoDueEnti::marcatori('B') as $marcatore) {
        expect($html)->not->toContain($marcatore);
    }
});
