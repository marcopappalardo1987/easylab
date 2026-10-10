<?php

use App\Enums\StatoSemaforo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\MacchinaSegnalata;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Notification;

/**
 * L'email «macchina segnalata»: qualcuno porta a mano il semaforo su «Azione
 * richiesta» o «Non idoneo» (🔗 ADR-047, aggiunta del 9 Ott 2026; ADR-005).
 *
 * **Area rossa**, come le email sugli interventi: decide cosa esce
 * dall'applicazione e verso chi. Qui in più esce un **testo libero** — il
 * motivo — quindi contano anche i casi in cui non deve uscire nulla.
 *
 * Mondo: l'Ente A con due reparti (la macchina sta in Chimica); l'Ente B come
 * controprova; un gestore di EasyLab con l'Ente A in portafoglio.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->chimica = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Chimica']);
    $fisica = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Fisica']);
    $this->autoclave = Strumento::factory()->forNode($this->chimica)->create(['nome' => 'Autoclave']);

    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);

    $persona = function (string $nome, string $ruolo, ?UnitaOrganizzativa $ente): User {
        $u = User::factory()->create(['name' => $nome, 'tenant_id' => $ente?->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u->fresh();
    };

    $this->admin = $persona('Aldo Admin', 'Admin', $this->enteA);
    $this->altroAdmin = $persona('Anna Admin', 'Admin', $this->enteA);
    $this->tenant = $persona('Tina Tenant', 'Tenant', $this->enteA);
    $this->respChimica = $persona('Rita Chimica', 'Responsabile Reparto', $this->enteA);
    $this->respChimica->unitaResponsabili()->attach($this->chimica);
    $this->respFisica = $persona('Franco Fisica', 'Responsabile Reparto', $this->enteA);
    $this->respFisica->unitaResponsabili()->attach($fisica);
    $this->tecnicoInterno = $persona('Mario Interno', 'Tecnico', $this->enteA);
    $this->adminB = $persona('Bruno B', 'Admin', $enteB);

    $this->gestore = $persona('Giorgia Gestore', 'Gestore', null);
    $this->gestore->portafoglioClienti()->attach($this->enteA);

    Notification::fake();

    // Segnala la macchina dalla scheda, come farebbe una persona.
    $this->segnala = fn (User $chi, string $stato, string $motivo = '') => scheda($chi, $this->autoclave)
        ->call('openForza')
        ->set('forzaForm.stato', $stato)
        ->set('forzaForm.motivo', $motivo)
        ->call('forza')
        ->assertHasNoErrors();
});

it('sends nothing while the email is off', function () {
    // 🔴 Lo stato di nascita: un deploy non comincia a scrivere ai clienti.
    ($this->segnala)($this->admin, 'rosso', 'Guasto alla pompa');

    expect($this->autoclave->fresh()->forced_state)->toBe(StatoSemaforo::Rosso);

    Notification::assertNothingSent();
});

it('tells the people of the client that a machine is not fit, with the reason, and nobody else', function () {
    InterruttoriEmail::imposta(CatalogoEmail::MACCHINA_SEGNALATA, true);

    ($this->segnala)($this->admin, 'rosso', 'Guasto alla pompa');

    Notification::assertSentTo(
        [$this->altroAdmin, $this->tenant, $this->respChimica],
        fn (MacchinaSegnalata $n) => $n->enteId === $this->enteA->id
            && $n->enteNome === 'Ente A'
            && $n->strumentoId === $this->autoclave->id
            && $n->strumentoNome === 'Autoclave'
            && str_contains($n->ubicazione, 'Chimica')
            && $n->stato === 'Strumento non idoneo'
            && $n->nonIdonea === true
            && $n->motivo === 'Guasto alla pompa'
            && $n->autore === 'Aldo Admin',
    );

    // Chi l'ha segnalata lo sa già.
    Notification::assertNotSentTo($this->admin, MacchinaSegnalata::class);
    // 🔴 Il responsabile del reparto accanto, e un altro cliente.
    Notification::assertNotSentTo([$this->respFisica, $this->adminB], MacchinaSegnalata::class);
    // I tecnici non sono «il cliente».
    Notification::assertNotSentTo([$this->tecnicoInterno, $this->gestore], MacchinaSegnalata::class);
});

it('tells them also when the machine only needs attention, with or without a reason', function () {
    InterruttoriEmail::imposta(CatalogoEmail::MACCHINA_SEGNALATA, true);

    ($this->segnala)($this->admin, 'arancione');

    Notification::assertSentTo($this->tenant, fn (MacchinaSegnalata $n) => $n->stato === 'Interventi necessari'
        && $n->nonIdonea === false
        && $n->motivo === null);
});

it('tells the client when easylab flags the machine, with the name of who did it', function () {
    // Il caso per cui l'email esiste: chi fa manutenzione per il cliente
    // trova un guasto, e il cliente lo viene a sapere subito.
    InterruttoriEmail::imposta(CatalogoEmail::MACCHINA_SEGNALATA, true);

    ($this->segnala)($this->gestore, 'rosso', 'Sonda di temperatura fuori taratura');

    Notification::assertSentTo(
        [$this->admin, $this->altroAdmin, $this->tenant, $this->respChimica],
        fn (MacchinaSegnalata $n) => $n->autore === 'Giorgia Gestore' && $n->motivo === 'Sonda di temperatura fuori taratura',
    );
});

it('says nothing when the machine is declared fit, or when the flag is taken away', function () {
    // «In regola» è una buona notizia, e non è ciò che questa email promette.
    InterruttoriEmail::imposta(CatalogoEmail::MACCHINA_SEGNALATA, true);

    ($this->segnala)($this->admin, 'verde', 'Verificata sul campo');

    scheda($this->admin, $this->autoclave)->call('rimuoviForzatura')->assertHasNoErrors();

    Notification::assertNothingSent();
});

it('writes once for the same flag, and again only when state or reason change', function () {
    InterruttoriEmail::imposta(CatalogoEmail::MACCHINA_SEGNALATA, true);

    ($this->segnala)($this->admin, 'rosso', 'Guasto alla pompa');
    // Un secondo «Salva» identico: stessa macchina, stesso stato, stesso motivo.
    ($this->segnala)($this->admin, 'rosso', 'Guasto alla pompa');

    Notification::assertSentToTimes($this->tenant, MacchinaSegnalata::class, 1);

    // Il motivo cambia: c'è qualcosa di nuovo da dire.
    ($this->segnala)($this->admin, 'rosso', 'Guasto alla pompa e al compressore');

    Notification::assertSentToTimes($this->tenant, MacchinaSegnalata::class, 2);

    // Lo stato cambia.
    ($this->segnala)($this->admin, 'arancione', 'Guasto alla pompa e al compressore');

    Notification::assertSentToTimes($this->tenant, MacchinaSegnalata::class, 3);
});

it('does not send the email to who gave it up, and keeps sending it to the others', function () {
    InterruttoriEmail::imposta(CatalogoEmail::MACCHINA_SEGNALATA, true);

    $this->tenant->forceFill(['riceve_email_macchine_segnalate' => false])->save();

    ($this->segnala)($this->admin, 'rosso', 'Guasto alla pompa');

    Notification::assertNotSentTo($this->tenant, MacchinaSegnalata::class);
    Notification::assertSentTo($this->altroAdmin, MacchinaSegnalata::class);
});

it('flags the machine even when the email cannot be queued', function () {
    // 🔴 La segnalazione di un guasto non deve dipendere dalla posta.
    InterruttoriEmail::imposta(CatalogoEmail::MACCHINA_SEGNALATA, true);

    $this->app->bind(Dispatcher::class, fn () => new class
    {
        public function send(mixed ...$argomenti): void
        {
            throw new RuntimeException('coda irraggiungibile');
        }
    });

    ($this->segnala)($this->admin, 'rosso', 'Guasto alla pompa');

    expect($this->autoclave->fresh()->forced_state)->toBe(StatoSemaforo::Rosso);
});

it('writes the two states with different words, and the reason as plain text', function () {
    InterruttoriEmail::imposta(CatalogoEmail::MACCHINA_SEGNALATA, true);

    ($this->segnala)($this->admin, 'rosso', 'Guasto [clicca](https://evil.example)');

    Notification::assertSentTo($this->tenant, function (MacchinaSegnalata $n) {
        $messaggio = $n->toMail($this->tenant);
        $html = (string) $messaggio->render();

        return $messaggio->subject === 'Easy Lab · Ente A: Autoclave non è idonea'
            // 🔗 ADR-052: il TITOLO è la dicitura dello stato. Si guarda l'`<h1>`
            // e non la pagina, perché la stessa dicitura sta anche nel corpo, e
            // un titolo tornato quello di prima passerebbe inosservato.
            && preg_match('/<h1[^>]*>\s*Strumento non idoneo\s*<\/h1>/', $html) === 1
            && str_contains($html, 'non va usata')
            // Il motivo è testo scritto da una persona: non diventa un link.
            && ! str_contains($html, 'href="https://evil.example');
    });

    ($this->segnala)($this->admin, 'arancione', 'Rumore anomalo');

    Notification::assertSentTo($this->tenant, function (MacchinaSegnalata $n) {
        if ($n->nonIdonea) {
            return false;
        }

        $messaggio = $n->toMail($this->tenant);
        $html = (string) $messaggio->render();

        return $messaggio->subject === 'Easy Lab · Ente A: Autoclave richiede un intervento'
            && preg_match('/<h1[^>]*>\s*Interventi necessari\s*<\/h1>/', $html) === 1
            && ! str_contains($html, 'non va usata');
    });
});

it('builds the email with the brand and the name of the ente in its payload, whatever the context on the worker', function () {
    // 🔴 Sul worker c'è, per ipotesi, l'Admin di un ALTRO cliente: marchio e
    // nome vengono dall'id nel payload, e da nient'altro.
    $this->enteA->forceFill(['marchio_colore' => '#aa0000'])->save();
    InterruttoriEmail::imposta(CatalogoEmail::MACCHINA_SEGNALATA, true);

    ($this->segnala)($this->admin, 'rosso', 'Guasto alla pompa');

    $this->actingAs($this->adminB);

    $messaggio = Notification::sent($this->tenant, MacchinaSegnalata::class)->first()->toMail($this->tenant);
    $html = (string) $messaggio->render();

    expect($messaggio->viewData['marchio']->nome)->toBe('Ente A')
        ->and($messaggio->viewData['marchio']->colore)->toBe('#aa0000')
        ->and($html)->toContain('Autoclave')
        ->and($html)->not->toContain('Ente B');
});
