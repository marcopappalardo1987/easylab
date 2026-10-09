<?php

use App\Livewire\Piattaforma\EmailDiSistema;
use App\Models\User;
use App\Notifications\EmailDiProva;
use App\Support\AuditLog;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Piattaforma → Email: l'elenco, gli interruttori, le prove di invio
 * (🔗 ADR-047).
 *
 * 🔴 Da questa pagina si decide cosa ricevono **tutti** i clienti, e si manda
 * posta col marchio di Easy Lab a un indirizzo scelto a mano. I negativi
 * contano più dei positivi: chi può entrare, cosa si può spegnere, e cosa
 * resta scritto di ogni gesto.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['email' => 'direzione@easylab.test', 'two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    RateLimiter::clear('email-di-prova:'.$this->superadmin->id);
});

// ─── Chi entra ───────────────────────────────────────────────────────────────

it('opens the page to whoever governs the platform', function (string $ruolo) {
    $utente = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente)->get(route('piattaforma.email'))->assertOk()->assertSee('Email informative');
})->with(['Superadmin', 'Developer']);

it('refuses the page to every role without the platform', function (string $ruolo) {
    $utente = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente)->get(route('piattaforma.email'))->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('refuses page and actions to whoever may look at the platform but not govern it', function () {
    // `tenants.view_all` apre la cabina. Qui serve `tenants.provision`: si
    // decide cosa ricevono tutti i clienti.
    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');
    $this->actingAs($osservatore->fresh());

    $this->get(route('piattaforma.email'))->assertForbidden();
    Livewire::test(EmailDiSistema::class)->assertForbidden();

    expect(InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_ESEGUITO))->toBeFalse();
});

it('re-authorizes every action, so a permission revoked after the page was opened stops the write', function () {
    Notification::fake();

    // Tre pagine aperte quando il permesso c'era ancora: una per gesto, perché
    // dopo un 403 lo snapshot di Livewire non è più riusabile.
    [$perAccendere, $perSpegnere, $perProvare] = [
        Livewire::test(EmailDiSistema::class),
        Livewire::test(EmailDiSistema::class),
        Livewire::test(EmailDiSistema::class),
    ];

    $this->superadmin->syncRoles(['Admin']);
    $this->actingAs($this->superadmin->fresh());

    $perAccendere->call('accendi', CatalogoEmail::INTERVENTO_ESEGUITO)->assertForbidden();
    $perSpegnere->call('spegni', CatalogoEmail::RIEPILOGO_SCADENZE)->assertForbidden();
    $perProvare->call('inviaProva', CatalogoEmail::INVITO)->assertForbidden();

    expect(InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_ESEGUITO))->toBeFalse()
        ->and(InterruttoriEmail::attiva(CatalogoEmail::RIEPILOGO_SCADENZE))->toBeTrue();

    Notification::assertNothingSent();
});

it('shows the entry in the platform bar only to whoever can open it', function () {
    $this->get(route('piattaforma.index'))->assertSee(route('piattaforma.email'));

    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');

    $this->actingAs($osservatore->fresh())->get(route('piattaforma.index'))
        ->assertOk()->assertDontSee(route('piattaforma.email'));
});

// ─── L'elenco ────────────────────────────────────────────────────────────────

it('lists every email of the catalogue, each with when it leaves and to whom', function () {
    $html = Livewire::test(EmailDiSistema::class)->html();

    foreach (CatalogoEmail::tutte() as $chiave => $tipo) {
        expect($html)->toContain('data-email="'.$chiave.'"')
            ->and($html)->toContain(e($tipo->nome))
            ->and($html)->toContain(e($tipo->quando));
    }
});

it('says which emails are on, and gives a switch to the informative ones only', function () {
    $html = Livewire::test(EmailDiSistema::class)->html();

    $riga = function (string $chiave) use ($html): string {
        preg_match('/<tr[^>]*data-email="'.$chiave.'"[^>]*>.*?<\/tr>/s', $html, $m);

        return $m[0] ?? '';
    };

    // Accesa di nascita: si può spegnere.
    expect($riga(CatalogoEmail::RIEPILOGO_SCADENZE))->toContain('data-attiva="1"')
        ->and($riga(CatalogoEmail::RIEPILOGO_SCADENZE))->toContain("spegni('".CatalogoEmail::RIEPILOGO_SCADENZE."')")
        // Spenta di nascita: si può accendere.
        ->and($riga(CatalogoEmail::INTERVENTO_ESEGUITO))->toContain('data-attiva="0"')
        ->and($riga(CatalogoEmail::INTERVENTO_ESEGUITO))->toContain("accendi('".CatalogoEmail::INTERVENTO_ESEGUITO."')")
        // Di servizio: nessun interruttore, solo la prova.
        ->and($riga(CatalogoEmail::INVITO))->not->toContain('accendi(')
        ->and($riga(CatalogoEmail::INVITO))->not->toContain('spegni(')
        ->and($riga(CatalogoEmail::INVITO))->toContain("inviaProva('".CatalogoEmail::INVITO."')");
});

// ─── Gli interruttori ────────────────────────────────────────────────────────

it('turns an email on and off from the page, and the row follows', function () {
    Livewire::test(EmailDiSistema::class)
        ->call('accendi', CatalogoEmail::INTERVENTO_PROGRAMMATO)
        ->assertSee('«Intervento programmato» è accesa')
        ->assertSeeHtml('data-email="'.CatalogoEmail::INTERVENTO_PROGRAMMATO.'" data-attiva="1"');

    expect(InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_PROGRAMMATO))->toBeTrue();

    $riga = Activity::where('log_name', AuditLog::NAME)->latest('id')->first();
    expect($riga->description)->toBe('Email accesa')->and($riga->causer_id)->toBe($this->superadmin->id);

    Livewire::test(EmailDiSistema::class)
        ->call('spegni', CatalogoEmail::INTERVENTO_PROGRAMMATO)
        ->assertSee('«Intervento programmato» è spenta')
        ->assertSeeHtml('data-email="'.CatalogoEmail::INTERVENTO_PROGRAMMATO.'" data-attiva="0"');

    expect(InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_PROGRAMMATO))->toBeFalse();
});

it('never turns off a service email, even when the key is forged', function (mixed $chiave) {
    // 🔴 Le email di servizio non hanno interruttore in pagina, ma le azioni
    // sono a un `$wire.call()` di distanza: spegnere l'invito chiuderebbe
    // fuori chiunque venga creato da quel momento.
    Livewire::test(EmailDiSistema::class)->call('spegni', $chiave)->assertNotFound();
    Livewire::test(EmailDiSistema::class)->call('accendi', $chiave)->assertNotFound();

    expect(DB::table('interruttori_email')->count())->toBe(0);
})->with([
    'invito' => [CatalogoEmail::INVITO],
    'recupero password' => [CatalogoEmail::RECUPERO_PASSWORD],
    'chiave inventata' => ['inventata'],
    'array' => [[CatalogoEmail::RIEPILOGO_SCADENZE]],
]);

// ─── Le prove di invio ───────────────────────────────────────────────────────

it('sends a sample of any email to the chosen address, at once', function (string $chiave) {
    Notification::fake();

    Livewire::test(EmailDiSistema::class)
        ->set('indirizzoProva', '  collaudo@esempio.test ')
        ->call('inviaProva', $chiave)
        ->assertHasNoErrors()
        ->assertSee('inviata a collaudo@esempio.test');

    Notification::assertSentOnDemand(
        EmailDiProva::class,
        fn (EmailDiProva $prova, array $canali, AnonymousNotifiable $a) => $a->routes['mail'] === 'collaudo@esempio.test'
            && $canali === ['mail']
            && str_starts_with($prova->toMail($a)->subject, '[Prova] '),
    );

    // Resta scritto chi ha mandato cosa, e dove.
    $riga = Activity::where('log_name', AuditLog::NAME)->where('description', 'Email di prova inviata')->latest('id')->first();

    expect($riga->causer_id)->toBe($this->superadmin->id)
        ->and($riga->properties['email'])->toBe($chiave)
        ->and($riga->properties['destinatario'])->toBe('collaudo@esempio.test');
})->with(fn () => array_keys(CatalogoEmail::tutte()));

it('starts from the address of whoever is testing', function () {
    expect(Livewire::test(EmailDiSistema::class)->get('indirizzoProva'))->toBe('direzione@easylab.test');
});

it('sends a sample even of an email that is off, without turning it on', function () {
    // La prova serve proprio a guardarla prima di accenderla.
    Notification::fake();

    Livewire::test(EmailDiSistema::class)
        ->set('indirizzoProva', 'collaudo@esempio.test')
        ->call('inviaProva', CatalogoEmail::INTERVENTO_ESEGUITO)
        ->assertHasNoErrors();

    Notification::assertSentOnDemandTimes(EmailDiProva::class, 1);

    expect(InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_ESEGUITO))->toBeFalse();
});

it('refuses a sample without a valid address, and sends nothing', function (string $indirizzo) {
    Notification::fake();

    Livewire::test(EmailDiSistema::class)
        ->set('indirizzoProva', $indirizzo)
        ->call('inviaProva', CatalogoEmail::INVITO)
        ->assertHasErrors('indirizzoProva');

    Notification::assertNothingSent();

    expect(Activity::where('description', 'Email di prova inviata')->count())->toBe(0);
})->with(['vuoto' => [''], 'non un indirizzo' => ['non-una-email'], 'solo spazi' => ['   ']]);

it('refuses a sample of an email that is not in the catalogue', function () {
    Notification::fake();

    Livewire::test(EmailDiSistema::class)
        ->set('indirizzoProva', 'collaudo@esempio.test')
        ->call('inviaProva', 'inventata')
        ->assertNotFound();

    Notification::assertNothingSent();
});

it('stops after twenty samples in a minute', function () {
    // Bastano a provarle tutte, non a usare la pagina per spedire posta.
    Notification::fake();

    $pagina = Livewire::test(EmailDiSistema::class)->set('indirizzoProva', 'collaudo@esempio.test');

    foreach (range(1, 20) as $_) {
        $pagina->call('inviaProva', CatalogoEmail::INVITO);
    }

    $pagina->call('inviaProva', CatalogoEmail::INVITO)->assertSee('Troppe prove in un minuto');

    Notification::assertSentOnDemandTimes(EmailDiProva::class, 20);
});

it('says so, with the words of the mail server, when the sample does not leave', function () {
    // Chi prova vuole l'esito: «non è partita» senza il perché costringerebbe
    // ad andare a cercarlo nei log.
    $this->app->bind(Dispatcher::class, fn () => new class
    {
        public function sendNow(mixed ...$argomenti): void
        {
            throw new RuntimeException('Connection refused dal server di posta');
        }
    });

    Livewire::test(EmailDiSistema::class)
        ->set('indirizzoProva', 'collaudo@esempio.test')
        ->call('inviaProva', CatalogoEmail::INVITO)
        ->assertSee('NON è partita')
        ->assertSee('Connection refused dal server di posta');

    // Niente riga «inviata» per un invio che non c'è stato.
    expect(Activity::where('description', 'Email di prova inviata')->count())->toBe(0);
});

it('really hands every sample to the mail transport, with the marked subject and the chosen recipient', function (string $chiave) {
    // 🔴 Senza `Notification::fake()`: la prova deve attraversare il canale
    // vero — tema, vista markdown, marchio — o il bottone potrebbe rispondere
    // «inviata» per un'email che il mailer non sa comporre. Il trasporto dei
    // test è `array`: il messaggio si ferma lì, e lo si legge.
    Livewire::test(EmailDiSistema::class)
        ->set('indirizzoProva', 'collaudo@esempio.test')
        ->call('inviaProva', $chiave)
        ->assertHasNoErrors()
        ->assertSee('inviata a collaudo@esempio.test');

    $spediti = app('mailer')->getSymfonyTransport()->messages();

    expect($spediti)->toHaveCount(1);

    $email = $spediti->first()->getOriginalMessage();

    expect($email->getTo()[0]->getAddress())->toBe('collaudo@esempio.test')
        ->and($email->getSubject())->toStartWith('[Prova] ')
        ->and($email->getHtmlBody())->not->toBe('')
        ->and($email->getTextBody())->not->toBe('');
})->with(fn () => array_keys(CatalogoEmail::tutte()));
