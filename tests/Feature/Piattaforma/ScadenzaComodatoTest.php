<?php

use App\Livewire\Billing\PaginaAbbonamento;
use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\ComodatoInScadenza;
use App\Support\AuditLog;
use App\Support\Billing\DurataComodato;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BancoAbbonamento;

/**
 * La scadenza di un piano in comodato d'uso (🔗 ADR-058): si sceglie assegnando
 * il piano (12, 24 o 36 mesi, o un giorno preciso), si cambia dopo, e trenta
 * giorni prima parte un'email al cliente e ai Superadmin.
 *
 * Mondo: il Laboratorio Verdi è in comodato, con la sua Admin fra i membri
 * dell'account; il Gruppo Rossi paga un abbonamento; EasyLab è la piattaforma,
 * con due Superadmin.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00:00'));

    $this->verdi = Account::factory()->create(['ragione_sociale' => 'Laboratorio Verdi']);
    $sedeVerdi = UnitaOrganizzativa::factory()->ente()->perAccount($this->verdi)->create(['nome' => 'Sede Verdi']);
    $this->adminVerdi = User::factory()->create(['name' => 'Vera Verdi', 'tenant_id' => $sedeVerdi->id]);
    $this->adminVerdi->assignRole('Admin');
    $this->verdi->aggiungiMembro($this->adminVerdi);
    // Un Tenant della stessa sede: non amministra il contratto, e non la riceve.
    $this->tenantVerdi = User::factory()->create(['tenant_id' => $sedeVerdi->id]);
    $this->tenantVerdi->assignRole('Tenant');

    $this->rossi = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede Rossi']);

    $piattaforma = Account::factory()->create(['ragione_sociale' => 'EasyLab']);
    $piattaforma->forceFill(['di_piattaforma' => true])->save();
    $this->piattaforma = $piattaforma->fresh();
    $easylab = UnitaOrganizzativa::factory()->ente()->perAccount($this->piattaforma)->create(['nome' => 'EasyLab']);

    $this->superadmin = User::factory()->create(['name' => 'Sara', 'tenant_id' => $easylab->id, 'two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->altroSuperadmin = User::factory()->create(['name' => 'Sergio', 'tenant_id' => $easylab->id]);
    $this->altroSuperadmin->assignRole('Superadmin');

    $this->actingAs($this->superadmin->fresh());
    Notification::fake();

    $this->avvisa = fn (array $opzioni = []) => $this->artisan('easylab:notifica-comodato', $opzioni)->assertSuccessful();
    $this->scade = fn (Account $account) => $account->fresh()->comodato_scade_il?->toDateString();
});

// ─── Le durate ───────────────────────────────────────────────────────────────

it('offers twelve, twenty-four and thirty-six months, a precise date, or no expiry', function () {
    expect(DurataComodato::opzioni())->toBe([
        '' => 'Senza scadenza',
        '12' => '12 mesi da oggi',
        '24' => '24 mesi da oggi',
        '36' => '36 mesi da oggi',
        'data' => 'Fino a una data precisa',
    ])
        ->and(DurataComodato::scadenza(''))->toBeNull()
        ->and(DurataComodato::scadenza('12')->toDateString())->toBe('2027-10-11')
        ->and(DurataComodato::scadenza('24')->toDateString())->toBe('2028-10-11')
        ->and(DurataComodato::scadenza('36')->toDateString())->toBe('2029-10-11')
        ->and(DurataComodato::scadenza('data', '2027-03-15')->toDateString())->toBe('2027-03-15')
        // Dodici mesi dal 29 febbraio sono il 28, non il primo marzo.
        ->and(DurataComodato::scadenza('12', null, CarbonImmutable::parse('2028-02-29'))->toDateString())->toBe('2029-02-28');
});

it('refuses a duration that is not one of those, and a date that is not a date', function (string $scelta, ?string $data) {
    expect(fn () => DurataComodato::scadenza($scelta, $data))->toThrow(InvalidArgumentException::class);
})->with([
    'sei mesi' => ['6', null],
    'dodici con una coda' => ['12 mesi', null],
    'una parola' => ['sempre', null],
    'una data che non esiste' => ['data', '2027-02-30'],
    'una data scritta male' => ['data', '15/03/2027'],
    'nessuna data' => ['data', null],
]);

// ─── Sul cliente già attivo ──────────────────────────────────────────────────

it('lets the superadmin set the expiry of a comodato already running, by months or by date', function () {
    $cabina = Livewire::test(Cabina::class)
        ->assertSeeHtml('wire:click="apriComodato('.$this->verdi->id.')"')
        ->call('apriComodato', $this->verdi->id)
        ->assertSet('pannello', 'comodato')
        ->assertSet('comodato.durata', '')
        ->assertSee('Oggi non ha una scadenza.')
        ->set('comodato.durata', '24')
        ->assertSee('Scadrà il')
        ->assertSee('11/10/2028')
        ->call('salvaComodato')
        ->assertHasNoErrors()
        ->assertSet('pannello', '')
        ->assertSee('comodato fino al 11/10/2028');

    expect(($this->scade)($this->verdi))->toBe('2028-10-11');

    // Riaperto, mostra la data che c'è: salvarlo senza toccare non la sposta.
    $cabina->call('apriComodato', $this->verdi->id)
        ->assertSet('comodato.durata', 'data')
        ->assertSet('comodato.data', '2028-10-11')
        ->assertSee('Oggi scade il')
        ->call('salvaComodato')
        ->assertHasNoErrors();

    expect(($this->scade)($this->verdi))->toBe('2028-10-11');

    $cabina->call('apriComodato', $this->verdi->id)
        ->set('comodato.durata', 'data')
        ->set('comodato.data', '2027-01-31')
        ->call('salvaComodato')
        ->assertHasNoErrors();

    expect(($this->scade)($this->verdi))->toBe('2027-01-31');

    // E si toglie.
    $cabina->call('apriComodato', $this->verdi->id)
        ->set('comodato.durata', '')
        ->call('salvaComodato')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-comodato-scade');

    expect(($this->scade)($this->verdi))->toBeNull();
});

it('leaves in the audit who moved the expiry, from when to when', function () {
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2027-01-31'));
    $this->verdi->fresh()->impostaScadenzaComodato(CarbonImmutable::parse('2028-01-31'));

    $riga = Activity::query()->where('log_name', AuditLog::NAME)->where('subject_id', $this->verdi->id)
        ->where('subject_type', $this->verdi->getMorphClass())->orderByDesc('id')->first();

    expect($riga->causer_id)->toBe($this->superadmin->id)
        ->and(substr((string) $riga->attribute_changes['old']['comodato_scade_il'], 0, 10))->toBe('2027-01-31')
        ->and(substr((string) $riga->attribute_changes['attributes']['comodato_scade_il'], 0, 10))->toBe('2028-01-31');
});

it('asks for the day when a precise date is chosen, and refuses one already gone', function () {
    Livewire::test(Cabina::class)
        ->call('apriComodato', $this->verdi->id)
        ->set('comodato.durata', 'data')
        ->set('comodato.data', '')
        ->call('salvaComodato')
        ->assertHasErrors(['comodato.data' => 'required_if'])
        ->set('comodato.data', '2026-10-10')
        ->call('salvaComodato')
        ->assertHasErrors(['comodato.data' => 'after_or_equal'])
        ->set('comodato.data', '11/10/2027')
        ->call('salvaComodato')
        ->assertHasErrors(['comodato.data' => 'date_format'])
        ->set('comodato.durata', '6')
        ->call('salvaComodato')
        ->assertHasErrors(['comodato.durata' => 'in']);

    expect(($this->scade)($this->verdi))->toBeNull();

    // Oggi stesso è lecito: scade stasera.
    Livewire::test(Cabina::class)
        ->call('apriComodato', $this->verdi->id)
        ->set('comodato.durata', 'data')
        ->set('comodato.data', '2026-10-11')
        ->call('salvaComodato')
        ->assertHasNoErrors();

    expect(($this->scade)($this->verdi))->toBe('2026-10-11');
});

it('ignores a date left in the field when months are chosen', function () {
    Livewire::test(Cabina::class)
        ->call('apriComodato', $this->verdi->id)
        ->set('comodato.durata', 'data')
        ->set('comodato.data', '2026-01-01')
        ->set('comodato.durata', '12')
        ->call('salvaComodato')
        ->assertHasNoErrors();

    expect(($this->scade)($this->verdi))->toBe('2027-10-11');
});

it('offers the panel only for a client on a comodato plan', function () {
    // (L'account di piattaforma in questo elenco non c'è: non è un cliente.)
    $cabina = Livewire::test(Cabina::class)
        ->assertDontSeeHtml('wire:click="apriComodato('.$this->rossi->id.')"')
        ->assertDontSeeHtml('wire:click="apriComodato('.$this->piattaforma->id.')"');

    // 🔴 E l'azione, chiamata senza il bottone, dice di no.
    $cabina->call('apriComodato', $this->rossi->id)->assertStatus(422);

    Livewire::test(Cabina::class)
        ->set('accountInLavorazione', $this->rossi->id)
        ->set('comodato', ['durata' => '12', 'data' => ''])
        ->call('salvaComodato')
        ->assertStatus(422);

    expect(($this->scade)($this->rossi))->toBeNull()
        ->and(fn () => $this->rossi->impostaScadenzaComodato(now()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->piattaforma->impostaScadenzaComodato(now()))->toThrow(InvalidArgumentException::class);
});

it('refuses the panel to who does not govern the platform', function () {
    $this->actingAs($this->adminVerdi->fresh());

    // La cabina è della piattaforma: chi amministra un cliente non ci entra,
    // quindi non arriva né al pannello né alle sue azioni.
    Livewire::test(Cabina::class)->assertForbidden();

    expect(($this->scade)($this->verdi))->toBeNull();
});

it('says in the row whether the comodato is far, close, or gone', function (string $data, string $frase) {
    $this->verdi->forceFill(['comodato_scade_il' => $data])->save();

    Livewire::test(Cabina::class)->assertSeeHtml('data-comodato-scade')->assertSee($frase);
})->with([
    'lontano' => ['2026-11-11', 'comodato fino al 11/11/2026'],
    'a trenta giorni' => ['2026-11-10', 'comodato in scadenza il 10/11/2026'],
    'oggi' => ['2026-10-11', 'comodato in scadenza il 11/10/2026'],
    'ieri' => ['2026-10-10', 'comodato scaduto il 10/10/2026'],
]);

// ─── Alla nascita del cliente ────────────────────────────────────────────────

it('sets the expiry while creating a client on a comodato plan', function (array $campi, ?string $attesa) {
    $cabina = Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->assertSee('Durata del comodato')
        ->set('nuovo.nome', 'Clinica Nuova')
        ->set('nuovo.adminEmail', 'nora@clinica.test')
        ->set('nuovo.adminName', 'Nora Nuova');

    foreach ($campi as $campo => $valore) {
        $cabina->set("nuovo.{$campo}", $valore);
    }

    $cabina->call('creaCliente')->assertHasNoErrors();

    expect(($this->scade)(Account::where('ragione_sociale', 'Clinica Nuova')->sole()))->toBe($attesa);
})->with([
    'senza scadenza' => [[], null],
    'dodici mesi' => [['comodato' => '12'], '2027-10-11'],
    'trentasei mesi' => [['comodato' => '36'], '2029-10-11'],
    'una data precisa' => [['comodato' => 'data', 'comodatoData' => '2027-06-30'], '2027-06-30'],
]);

it('does not create the client when the date is missing or gone', function (string $data, string $regola) {
    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo.nome', 'Clinica Nuova')
        ->set('nuovo.adminEmail', 'nora@clinica.test')
        ->set('nuovo.adminName', 'Nora Nuova')
        ->set('nuovo.comodato', 'data')
        ->set('nuovo.comodatoData', $data)
        ->call('creaCliente')
        ->assertHasErrors(['nuovo.comodatoData' => $regola]);

    expect(Account::where('ragione_sociale', 'Clinica Nuova')->exists())->toBeFalse();
})->with([
    'manca' => ['', 'required_if'],
    'ieri' => ['2026-10-10', 'after_or_equal'],
]);

it('does not touch the expiry of an existing client when a sede is added to it', function () {
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2027-01-31'));

    Livewire::test(Cabina::class)
        ->call('apriProvisioning', $this->verdi->id)
        ->set('nuovo.nome', 'Seconda sede')
        ->set('nuovo.adminEmail', 'altra@verdi.test')
        ->set('nuovo.adminName', 'Altra Persona')
        // 🔴 Il campo non c'è, ma `nuovo` è un array pubblico.
        ->set('nuovo.comodato', '36')
        ->call('creaCliente');

    expect(($this->scade)($this->verdi))->toBe('2027-01-31');

    // E un valore che su un cliente nuovo sarebbe un errore («una data
    // precisa» senza la data) qui non ferma niente: quel campo non esiste.
    Livewire::test(Cabina::class)
        ->call('apriProvisioning', $this->verdi->id)
        ->set('nuovo.nome', 'Terza sede')
        ->set('nuovo.adminEmail', 'terza@verdi.test')
        ->set('nuovo.adminName', 'Terza Persona')
        ->set('nuovo.comodato', 'data')
        ->call('creaCliente')
        ->assertHasNoErrors(['nuovo.comodatoData']);
});

// ─── Ciò che legge il cliente ────────────────────────────────────────────────

it('tells the client on the abbonamento page until when the comodato runs', function (?string $data, ?string $frase) {
    $this->verdi->forceFill(['comodato_scade_il' => $data])->save();
    $this->adminVerdi->forceFill(['two_factor_confirmed_at' => now()])->save();

    $pagina = Livewire::actingAs($this->adminVerdi->fresh())->test(PaginaAbbonamento::class);

    $frase === null
        ? $pagina->assertDontSeeHtml('data-comodato-scade')
        // Testo scritto nel modello, con l'apostrofo com'è: `false` lo cerca tale e quale.
        : $pagina->assertSeeHtml('data-comodato-scade')->assertSee($frase, false);
})->with([
    'senza scadenza' => [null, null],
    'in corso' => ['2027-01-31', "Il comodato d'uso scade il 31/01/2027."],
    'oggi' => ['2026-10-11', "Il comodato d'uso scade il 11/10/2026."],
    'passata' => ['2026-10-10', "Il comodato d'uso è scaduto il 10/10/2026."],
]);

// ─── Il piano che cambia ─────────────────────────────────────────────────────

it('drops the expiry when the client moves to a paid plan, so it cannot come back with it', function () {
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2026-10-20'));
    ($this->avvisa)();

    $this->verdi->fresh()->cambiaPiano('saas', annuncia: false);

    expect($this->verdi->fresh()->only(['comodato_scade_il', 'comodato_avvisato_per']))
        ->toBe(['comodato_scade_il' => null, 'comodato_avvisato_per' => null]);

    // Tornato al comodato dopo una disdetta, riparte senza scadenza.
    $this->verdi->fresh()->cambiaPiano('free', annuncia: false);

    expect(($this->scade)($this->verdi))->toBeNull();
});

it('keeps the expiry when the client moves from one comodato plan to another', function () {
    // Il contratto è lo stesso: cambia il taglio del piano, non la sua durata.
    BancoAbbonamento::pianoSenzaPrice('comodato-esteso', 0, ['gratuito' => true, 'etichetta' => 'Comodato esteso', 'max_enti' => 3]);
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2027-01-31'));

    $this->verdi->fresh()->cambiaPiano('comodato-esteso', annuncia: false);

    expect(($this->scade)($this->verdi))->toBe('2027-01-31');
});

// ─── L'email dei trenta giorni ───────────────────────────────────────────────

it('writes to the client and to every superadmin thirty days before, and not one day earlier', function () {
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2026-11-11'));   // 31 giorni

    ($this->avvisa)();
    Notification::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:30:00'));   // 30 giorni
    ($this->avvisa)();

    Notification::assertSentTo(
        $this->adminVerdi,
        fn (ComodatoInScadenza $n) => $n->ragioneSociale === 'Laboratorio Verdi'
            && $n->piano === "Comodato d'uso"
            && $n->scadeIl === '11/11/2026'
            && $n->giorni === 30
            && $n->perPiattaforma === false,
    );
    Notification::assertSentTo([$this->superadmin, $this->altroSuperadmin], fn (ComodatoInScadenza $n) => $n->perPiattaforma === true && $n->giorni === 30);
    // Chi non amministra il contratto non la riceve.
    Notification::assertNotSentTo($this->tenantVerdi, ComodatoInScadenza::class);
    Notification::assertCount(3);
});

it('writes once for an expiry, and again only when the date is moved', function () {
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2026-10-31'));

    ($this->avvisa)();
    ($this->avvisa)();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 06:30:00'));
    ($this->avvisa)();

    Notification::assertSentToTimes($this->adminVerdi, ComodatoInScadenza::class, 1);

    // Spostata oltre i trenta giorni: silenzio. Riportata dentro: riparte.
    $this->verdi->fresh()->impostaScadenzaComodato(CarbonImmutable::parse('2027-10-31'));
    ($this->avvisa)();
    Notification::assertSentToTimes($this->adminVerdi, ComodatoInScadenza::class, 1);

    $this->verdi->fresh()->impostaScadenzaComodato(CarbonImmutable::parse('2026-11-05'));
    ($this->avvisa)();
    Notification::assertSentToTimes($this->adminVerdi, ComodatoInScadenza::class, 2);

    // E rimessa ESATTAMENTE alla data di prima dopo averla tolta, di nuovo.
    $this->verdi->fresh()->impostaScadenzaComodato(null);
    $this->verdi->fresh()->impostaScadenzaComodato(CarbonImmutable::parse('2026-11-05'));
    ($this->avvisa)();
    Notification::assertSentToTimes($this->adminVerdi, ComodatoInScadenza::class, 2);
});

it('still writes on the last day, and not for a comodato already gone', function () {
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2026-10-11'));
    ($this->avvisa)();

    Notification::assertSentTo($this->adminVerdi, fn (ComodatoInScadenza $n) => $n->giorni === 0);

    Notification::fake();
    $this->verdi->forceFill(['comodato_scade_il' => '2026-10-10', 'comodato_avvisato_per' => null])->save();
    ($this->avvisa)();

    Notification::assertNothingSent();
});

it('leaves out who pays, the platform itself, and who has no expiry', function () {
    // Dati scritti da fuori: una scadenza su chi non dovrebbe averla.
    $this->rossi->forceFill(['comodato_scade_il' => '2026-10-20'])->save();
    $this->piattaforma->forceFill(['comodato_scade_il' => '2026-10-20'])->save();

    ($this->avvisa)();

    Notification::assertNothingSent();
});

it('writes once to a superadmin who is also among the members of the client', function () {
    $this->verdi->aggiungiMembro($this->superadmin);
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2026-10-20'));

    ($this->avvisa)();

    Notification::assertSentToTimes($this->superadmin, ComodatoInScadenza::class, 1);
    Notification::assertSentTo($this->superadmin, fn (ComodatoInScadenza $n) => $n->perPiattaforma === false);
    Notification::assertCount(3);
});

it('sends nothing and remembers nothing while the email is off, so turning it on still warns', function () {
    InterruttoriEmail::imposta(CatalogoEmail::COMODATO_IN_SCADENZA, false);
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2026-10-20'));

    ($this->avvisa)();
    Notification::assertNothingSent();
    expect($this->verdi->fresh()->comodato_avvisato_per)->toBeNull();

    InterruttoriEmail::imposta(CatalogoEmail::COMODATO_IN_SCADENZA, true);
    ($this->avvisa)();

    Notification::assertSentTo($this->adminVerdi, ComodatoInScadenza::class);
});

it('marks the warning as given without writing to anyone on a run that only fills the log', function () {
    $this->verdi->impostaScadenzaComodato(CarbonImmutable::parse('2026-10-20'));

    ($this->avvisa)(['--senza-invio' => true]);
    ($this->avvisa)();

    Notification::assertNothingSent();
    expect($this->verdi->fresh()->comodato_avvisato_per->toDateString())->toBe('2026-10-20');
});

it('is born on, and runs every morning', function () {
    expect(InterruttoriEmail::attiva(CatalogoEmail::COMODATO_IN_SCADENZA))->toBeTrue();

    $pianificato = collect(app(Schedule::class)->events())
        ->first(fn ($evento) => str_contains((string) $evento->command, 'easylab:notifica-comodato'));

    expect($pianificato)->not->toBeNull()
        ->and($pianificato->expression)->toBe('30 6 * * *')
        ->and($pianificato->timezone)->toBe('Europe/Rome');
});

// ─── Le parole dell'email ────────────────────────────────────────────────────

it('tells the client to get in touch, and the superadmin where the date is moved', function () {
    $html = fn (int $giorni, bool $perPiattaforma) => preg_replace('/\s+/', ' ', (string) (new ComodatoInScadenza('Laboratorio Verdi', "Comodato d'uso", '11/11/2026', $giorni, $perPiattaforma))->toMail($this->adminVerdi)->render());

    expect($html(30, false))
        ->toContain('scade il <strong>11/11/2026</strong>, fra 30 giorni.')
        ->toContain('scrivi a chi ti segue in Easy Lab')
        ->toContain('/abbonamento')
        ->not->toContain('governi la piattaforma');

    expect($html(30, true))
        ->toContain('governi la piattaforma')
        ->toContain('/piattaforma')
        ->not->toContain('scrivi a chi ti segue');

    expect($html(1, false))->toContain('</strong>: domani.')
        ->and($html(0, false))->toContain('</strong>: oggi.');

    // 🔗 ADR-050: il piano senza canone non si chiama mai in un altro modo.
    expect(preg_match('/\b(free|gratis|gratuit\w*)\b/iu', strip_tags($html(30, false).$html(30, true))))->toBe(0);
});
