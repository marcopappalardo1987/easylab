<?php

use App\Livewire\Anagrafica\Albero;
use App\Models\Fornitore;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Il referente di uno strumento e le sue note (🔗 ADR-054): i campi del modulo,
 * quando si registra una macchina e quando la si modifica, e ciò che la scheda
 * ne mostra.
 *
 * Le email che ne conseguono stanno in `Email/EmailReferenteTest`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ospedale di Salerno']);
    $this->laboratorio = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Virologia']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->fornitore = Fornitore::factory()->forTenant($this->ente)->create();

    // Il modulo «Nuovo strumento», aperto nel laboratorio e già col minimo
    // che serve a salvarlo.
    $this->nuovo = fn () => Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->laboratorio->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Incubatore')
        ->set('strumentoForm.fornitore_id', $this->fornitore->id);

    $this->incubatore = fn (): Strumento => Strumento::where('nome', 'Incubatore')->sole();
});

it('asks for the referente and the notes when a strumento is added', function () {
    ($this->nuovo)()
        ->assertSeeHtml('Referente dello strumento')
        ->assertSeeHtml('wire:model="strumentoForm.referente_nome"')
        ->assertSeeHtml('wire:model="strumentoForm.referente_cognome"')
        ->assertSeeHtml('wire:model="strumentoForm.referente_email"')
        ->assertSeeHtml('wire:model="strumentoForm.referente_cellulare"')
        ->assertSeeHtml('wire:model="strumentoForm.note"')
        // Chi compila deve sapere che cosa comporta scrivere l'indirizzo.
        ->assertSee('le email che riguardano questo strumento arrivano anche a lui');
});

it('saves the referente and the notes with the new strumento', function () {
    ($this->nuovo)()
        ->set('strumentoForm.referente_nome', 'Giulia')
        ->set('strumentoForm.referente_cognome', 'Bianchi')
        ->set('strumentoForm.referente_email', 'giulia.bianchi@ospedale.test')
        ->set('strumentoForm.referente_cellulare', '333 1234567')
        ->set('strumentoForm.note', "Chiave del locale in portineria.\nNon spegnere di notte.")
        ->call('saveStrumento')
        ->assertHasNoErrors()
        ->assertSet('showStrumentoForm', false);

    expect(($this->incubatore)()->only(['referente_nome', 'referente_cognome', 'referente_email', 'referente_cellulare', 'note']))->toBe([
        'referente_nome' => 'Giulia',
        'referente_cognome' => 'Bianchi',
        'referente_email' => 'giulia.bianchi@ospedale.test',
        'referente_cellulare' => '333 1234567',
        'note' => "Chiave del locale in portineria.\nNon spegnere di notte.",
    ]);
});

it('leaves them empty when nobody fills them in, because they are optional', function () {
    ($this->nuovo)()->call('saveStrumento')->assertHasNoErrors();

    $incubatore = ($this->incubatore)();

    expect($incubatore->only(['referente_nome', 'referente_cognome', 'referente_email', 'referente_cellulare', 'note']))
        ->toBe(['referente_nome' => null, 'referente_cognome' => null, 'referente_email' => null, 'referente_cellulare' => null, 'note' => null])
        ->and($incubatore->haReferente())->toBeFalse()
        ->and($incubatore->referenteNomeCompleto())->toBeNull();
});

it('stores nothing instead of blanks, and the address without capitals or spaces', function () {
    // L'indirizzo è ciò con cui si riconosce chi riceve già l'email: scritto in
    // due modi sarebbero due destinatari.
    ($this->nuovo)()
        ->set('strumentoForm.referente_nome', '  Giulia ')
        ->set('strumentoForm.referente_cognome', '   ')
        ->set('strumentoForm.referente_email', '  Giulia.Bianchi@Ospedale.TEST ')
        ->set('strumentoForm.referente_cellulare', '  ')
        ->set('strumentoForm.note', "  \n ")
        ->call('saveStrumento')
        ->assertHasNoErrors();

    expect(($this->incubatore)()->only(['referente_nome', 'referente_cognome', 'referente_email', 'referente_cellulare', 'note']))->toBe([
        'referente_nome' => 'Giulia',
        'referente_cognome' => null,
        'referente_email' => 'giulia.bianchi@ospedale.test',
        'referente_cellulare' => null,
        'note' => null,
    ]);
});

it('refuses an address that is not one, and names the field the way a person reads it', function () {
    $modulo = ($this->nuovo)()
        ->set('strumentoForm.referente_email', 'giulia.bianchi')
        ->call('saveStrumento')
        ->assertHasErrors(['strumentoForm.referente_email' => 'email'])
        ->assertSet('showStrumentoForm', true);

    // Il messaggio, e non la pagina: «email del referente» sta già scritto
    // nella riga che spiega il campo, e la pagina lo conterrebbe comunque.
    expect($modulo->errors()->first('strumentoForm.referente_email'))
        ->toContain('email del referente')
        ->not->toContain('strumento form');

    expect(Strumento::where('nome', 'Incubatore')->exists())->toBeFalse();
});

it('refuses what is too long for each field, and says which one', function (string $campo, int $massimo, string $comeSiLegge) {
    $modulo = ($this->nuovo)()
        ->set("strumentoForm.{$campo}", str_repeat('a', $massimo + 1))
        ->call('saveStrumento')
        ->assertHasErrors(["strumentoForm.{$campo}" => 'max']);

    expect($modulo->errors()->first("strumentoForm.{$campo}"))->toContain(" {$comeSiLegge} ");

    ($this->nuovo)()
        ->set("strumentoForm.{$campo}", str_repeat('a', $massimo))
        ->call('saveStrumento')
        ->assertHasNoErrors(["strumentoForm.{$campo}"]);
})->with([
    'il nome' => ['referente_nome', 255, 'nome del referente'],
    'il cognome' => ['referente_cognome', 255, 'cognome del referente'],
    'il cellulare' => ['referente_cellulare', 50, 'cellulare del referente'],
    'le note' => ['note', 2000, 'note'],
]);

it('opens the edit form with what the strumento already has, and saves what changes', function () {
    $strumento = Strumento::factory()->forNode($this->laboratorio)->create([
        'nome' => 'Autoclave',
        'fornitore_id' => $this->fornitore->id,
        'referente_nome' => 'Giulia',
        'referente_cognome' => 'Bianchi',
        'referente_email' => 'giulia.bianchi@ospedale.test',
        'referente_cellulare' => '333 1234567',
        'note' => 'Chiave in portineria.',
    ]);

    scheda($this->admin, $strumento)
        ->call('edit')
        ->assertSet('strumentoForm.referente_nome', 'Giulia')
        ->assertSet('strumentoForm.referente_cognome', 'Bianchi')
        ->assertSet('strumentoForm.referente_email', 'giulia.bianchi@ospedale.test')
        ->assertSet('strumentoForm.referente_cellulare', '333 1234567')
        ->assertSet('strumentoForm.note', 'Chiave in portineria.')
        ->set('strumentoForm.referente_nome', 'Marco')
        ->set('strumentoForm.referente_cognome', 'Rossi')
        ->set('strumentoForm.referente_email', 'marco.rossi@ospedale.test')
        // Svuotare un campo lo toglie: è il solo modo di far smettere le email.
        ->set('strumentoForm.referente_cellulare', '')
        ->set('strumentoForm.note', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($strumento->fresh()->only(['referente_nome', 'referente_cognome', 'referente_email', 'referente_cellulare', 'note']))->toBe([
        'referente_nome' => 'Marco',
        'referente_cognome' => 'Rossi',
        'referente_email' => 'marco.rossi@ospedale.test',
        'referente_cellulare' => null,
        'note' => null,
    ]);
});

it('starts the next form empty, without the referente of the strumento just saved', function () {
    ($this->nuovo)()
        ->set('strumentoForm.referente_nome', 'Giulia')
        ->set('strumentoForm.referente_email', 'giulia.bianchi@ospedale.test')
        ->set('strumentoForm.note', 'Chiave in portineria.')
        ->call('saveStrumento')
        ->call('addStrumento')
        ->assertSet('strumentoForm.referente_nome', '')
        ->assertSet('strumentoForm.referente_cognome', '')
        ->assertSet('strumentoForm.referente_email', '')
        ->assertSet('strumentoForm.referente_cellulare', '')
        ->assertSet('strumentoForm.note', '');
});

it('shows the referente on the page of the strumento, with links to write and to call', function () {
    $strumento = Strumento::factory()->forNode($this->laboratorio)->create([
        'referente_nome' => 'Giulia',
        'referente_cognome' => 'Bianchi',
        'referente_email' => 'giulia.bianchi@ospedale.test',
        'referente_cellulare' => '+39 333 1234567',
        'note' => "Chiave in portineria.\nNon spegnere di notte.",
    ]);

    scheda($this->admin, $strumento)
        ->assertSee('Giulia Bianchi')
        ->assertSeeHtml('href="mailto:giulia.bianchi@ospedale.test"')
        // Nel collegamento il numero è senza spazi: è ciò che un telefono compone.
        ->assertSeeHtml('href="tel:+393331234567"')
        ->assertSee('+39 333 1234567')
        ->assertSee('Riceve le email che riguardano questo strumento.')
        ->assertSee('Chiave in portineria.')
        ->assertSee('Non spegnere di notte.')
        // Gli a capo di chi ha scritto restano a capo.
        ->assertSeeHtml('whitespace-pre-line text-ink" data-note-strumento')
        ->assertDontSee('Nessun referente indicato.');
});

it('says that there is no referente, and promises no email, when there is none', function () {
    $senza = Strumento::factory()->forNode($this->laboratorio)->create();

    scheda($this->admin, $senza)
        ->assertSee('Nessun referente indicato.')
        ->assertDontSee('Riceve le email che riguardano questo strumento.')
        ->assertDontSeeHtml('data-note-strumento');

    // Un nome senza indirizzo è un referente a cui non si scrive.
    $soloNome = Strumento::factory()->forNode($this->laboratorio)->create(['referente_cognome' => 'Bianchi', 'referente_cellulare' => '333 1234567']);

    scheda($this->admin, $soloNome)
        ->assertSee('Bianchi')
        ->assertDontSee('Nessun referente indicato.')
        ->assertDontSee('Riceve le email che riguardano questo strumento.')
        ->assertDontSeeHtml('href="mailto:');
});

it('prints what was typed as text, never as markup', function () {
    $strumento = Strumento::factory()->forNode($this->laboratorio)->create([
        'referente_nome' => '<b>Giulia</b>',
        'referente_cellulare' => '333"><script>alert(1)</script>',
        'note' => '<img src=x onerror=alert(1)>',
    ]);

    scheda($this->admin, $strumento)
        ->assertDontSeeHtml('<b>Giulia</b>')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
        ->assertSee('<b>Giulia</b>');
});

it('keeps the forced state out of reach of the form, as before', function () {
    // I campi nuovi entrano in `$fillable`: la lista non deve essersi allargata
    // a ciò che ne era fuori apposta.
    expect((new Strumento)->getFillable())
        ->toContain('referente_nome', 'referente_cognome', 'referente_email', 'referente_cellulare', 'note')
        ->not->toContain('forced_state')
        ->not->toContain('forced_reason')
        ->not->toContain('qr_token');
});

it('does not let a machine that changes ente take its referente along', function () {
    // 🔴 Il referente è una persona del laboratorio di prima: lasciato sulla
    // riga, riceverebbe le email di una macchina che ora è di un altro cliente.
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ospedale di Battipaglia']);
    $laboratorioNuovo = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();

    $strumento = Strumento::factory()->forNode($this->laboratorio)->create([
        'referente_nome' => 'Giulia',
        'referente_cognome' => 'Bianchi',
        'referente_email' => 'giulia.bianchi@ospedale.test',
        'referente_cellulare' => '333 1234567',
        'note' => 'Revisionata nel 2024.',
    ]);

    // `forceFill` perché `tenant_id` si scrive solo con un gesto di dominio.
    $strumento->forceFill(['tenant_id' => $altroEnte->id, 'unita_organizzativa_id' => $laboratorioNuovo->id])->save();

    expect(Strumento::withoutGlobalScopes()->find($strumento->id)->only(['referente_nome', 'referente_cognome', 'referente_email', 'referente_cellulare', 'note']))->toBe([
        'referente_nome' => null,
        'referente_cognome' => null,
        'referente_email' => null,
        'referente_cellulare' => null,
        // Le note parlano della macchina, e la sua storia la segue.
        'note' => 'Revisionata nel 2024.',
    ]);
});

it('keeps what the new ente writes about its referente while taking the machine, and nothing of the old one', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $laboratorioNuovo = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $strumento = Strumento::factory()->forNode($this->laboratorio)->create([
        'referente_nome' => 'Giulia',
        'referente_cognome' => 'Bianchi',
        'referente_email' => 'giulia.bianchi@ospedale.test',
        'referente_cellulare' => '333 1234567',
    ]);

    $strumento->forceFill([
        'tenant_id' => $altroEnte->id,
        'unita_organizzativa_id' => $laboratorioNuovo->id,
        'referente_nome' => 'Luca',
        'referente_email' => 'luca.verdi@battipaglia.test',
    ])->save();

    // Campo per campo: il cognome e il cellulare di Giulia non restano accanto
    // al nome e all'indirizzo di Luca.
    expect(Strumento::withoutGlobalScopes()->find($strumento->id)->only(['referente_nome', 'referente_cognome', 'referente_email', 'referente_cellulare']))->toBe([
        'referente_nome' => 'Luca',
        'referente_cognome' => null,
        'referente_email' => 'luca.verdi@battipaglia.test',
        'referente_cellulare' => null,
    ]);
});

it('leaves the referente where it is on every other change, a move inside the ente included', function () {
    $altroLaboratorio = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $strumento = Strumento::factory()->forNode($this->laboratorio)->create(['referente_cognome' => 'Bianchi', 'referente_email' => 'giulia.bianchi@ospedale.test']);

    $strumento->update(['nome' => 'Autoclave nuova', 'unita_organizzativa_id' => $altroLaboratorio->id]);

    expect($strumento->fresh()->only(['referente_cognome', 'referente_email']))
        ->toBe(['referente_cognome' => 'Bianchi', 'referente_email' => 'giulia.bianchi@ospedale.test']);
});

// ─── Il model, guardato da solo ──────────────────────────────────────────────

it('writes the address of the referente the same way whoever writes it', function () {
    // Non solo il modulo: un import, un seeder, un comando. È l'indirizzo con
    // cui si riconosce chi riceve già un'email.
    $strumento = Strumento::factory()->forNode($this->laboratorio)->create(['referente_email' => "  Giulia.BIANCHI@Ospedale.Test \n"]);

    expect($strumento->fresh()->referente_email)->toBe('giulia.bianchi@ospedale.test')
        ->and((new Strumento)->fill(['referente_email' => '   '])->referente_email)->toBeNull()
        ->and((new Strumento)->fill(['referente_email' => ''])->referente_email)->toBeNull()
        ->and((new Strumento)->fill(['referente_email' => null])->referente_email)->toBeNull();
});

it('knows it has a referente as soon as one thing about them is known', function (array $dati, bool $atteso) {
    expect((new Strumento)->fill($dati)->haReferente())->toBe($atteso);
})->with([
    'niente' => [[], false],
    'il solo nome' => [['referente_nome' => 'Giulia'], true],
    'il solo cognome' => [['referente_cognome' => 'Bianchi'], true],
    'il solo indirizzo' => [['referente_email' => 'g@ospedale.test'], true],
    'il solo cellulare' => [['referente_cellulare' => '333'], true],
    'le sole note' => [['note' => 'Chiave in portineria.'], false],
]);

it('reads name and surname of the referente the way a person does', function (array $dati, ?string $atteso) {
    expect((new Strumento)->fill($dati)->referenteNomeCompleto())->toBe($atteso);
})->with([
    'tutti e due' => [['referente_nome' => 'Giulia', 'referente_cognome' => 'Bianchi'], 'Giulia Bianchi'],
    'con degli spazi attorno' => [['referente_nome' => ' Giulia ', 'referente_cognome' => ' Bianchi '], 'Giulia Bianchi'],
    'il solo nome' => [['referente_nome' => 'Giulia'], 'Giulia'],
    'il solo cognome' => [['referente_cognome' => 'Bianchi'], 'Bianchi'],
    'nessuno dei due' => [['referente_email' => 'g@ospedale.test'], null],
    'due spazi' => [['referente_nome' => ' ', 'referente_cognome' => ' '], null],
]);

it('refuses an address longer than the column, and takes one that just fits', function () {
    // 192 + 1 + 62 = 255: la parte locale lunga è un avviso per la regola
    // `email`, non un errore, quindi a decidere qui è la sola lunghezza.
    $dominio = str_repeat('b', 59).'.it';

    ($this->nuovo)()
        ->set('strumentoForm.referente_email', str_repeat('a', 193).'@'.$dominio)
        ->call('saveStrumento')
        ->assertHasErrors(['strumentoForm.referente_email' => 'max']);

    ($this->nuovo)()
        ->set('strumentoForm.referente_email', str_repeat('a', 192).'@'.$dominio)
        ->call('saveStrumento')
        ->assertHasNoErrors();
});
