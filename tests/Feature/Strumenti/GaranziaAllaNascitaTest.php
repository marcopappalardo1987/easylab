<?php

use App\Enums\SoggettoGaranzia;
use App\Livewire\Anagrafica\Albero;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * La garanzia si scrive già nel modulo con cui si registra la macchina
 * (🔗 ADR-059), e il modulo di modifica mostra le righe dei parametri che si
 * stanno scrivendo — non quelle salvate.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00:00'));

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ospedale']);
    $this->laboratorio = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Virologia']);
    $this->fornitore = Fornitore::factory()->forTenant($this->ente)->create();

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->nuovo = fn (?User $chi = null) => Livewire::actingAs($chi ?? $this->admin)->test(Albero::class)
        ->call('open', $this->laboratorio->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Cappa')
        ->set('strumentoForm.fornitore_id', $this->fornitore->id);

    $this->garanzie = fn () => Garanzia::query()->where('strumento_id', Strumento::where('nome', 'Cappa')->sole()->id)->get();
});

// ─── Il bug del modulo di modifica ───────────────────────────────────────────

it('shows the row that Aggiungi adds in the edit form, and the ones already saved', function () {
    // 🔴 Segnalato da Marco l'11 Ott 2026: la vista della scheda aveva una
    // variabile `$parametri` (i salvati) che copriva la property del modulo.
    // La riga veniva aggiunta, e non compariva mai.
    $strumento = Strumento::factory()->forNode($this->laboratorio)->create(['fornitore_id' => $this->fornitore->id, 'parametri_tecnici' => null]);

    scheda($this->admin, $strumento)
        ->call('edit')
        ->assertSee('Nessun parametro. Aggiungine uno')
        ->call('addParametro')
        ->assertSeeHtml('wire:model="parametri.0.chiave"')
        ->assertDontSee('Nessun parametro. Aggiungine uno')
        ->set('parametri.0.chiave', 'Tensione')
        ->set('parametri.0.valore', '220V')
        ->call('addParametro')
        ->assertSeeHtml('wire:model="parametri.1.chiave"')
        ->call('save')
        ->assertHasNoErrors();

    expect($strumento->fresh()->parametri_tecnici)->toBe(['Tensione' => '220V']);

    // Riaperto, il modulo porta la riga salvata, come riga da modificare.
    scheda($this->admin, $strumento->fresh())
        ->call('edit')
        ->assertSet('parametri', [['chiave' => 'Tensione', 'valore' => '220V']])
        ->assertSeeHtml('wire:model="parametri.0.valore"')
        ->call('removeParametro', 0)
        ->assertDontSeeHtml('wire:model="parametri.0.chiave"');
});

it('still lists the saved parametri on the page of the strumento', function () {
    $strumento = Strumento::factory()->forNode($this->laboratorio)->create(['parametri_tecnici' => ['Portata' => '5kg']]);

    scheda($this->admin, $strumento)->assertSee('Portata')->assertSee('5kg')->assertDontSee('Nessun parametro tecnico.');
    scheda($this->admin, Strumento::factory()->forNode($this->laboratorio)->create(['parametri_tecnici' => null]))
        ->assertSee('Nessun parametro tecnico.');
});

// ─── La garanzia alla nascita ────────────────────────────────────────────────

it('asks for the garanzia in the form that registers the strumento', function () {
    ($this->nuovo)()
        ->assertSeeHtml('data-garanzia-nuova')
        ->assertSee('Garanzia della macchina')
        ->assertSeeHtml('wire:model="garanziaNuova.data_inizio"')
        ->assertSeeHtml('wire:model="garanziaNuova.durata_mesi"');
});

it('creates the garanzia with the strumento, from the date and the months given', function () {
    ($this->nuovo)()
        ->set('garanziaNuova.data_inizio', '2026-09-01')
        ->set('garanziaNuova.durata_mesi', '24')
        ->call('saveStrumento')
        ->assertHasNoErrors()
        ->assertSet('showStrumentoForm', false);

    $garanzia = ($this->garanzie)()->sole();

    expect($garanzia->soggetto)->toBe(SoggettoGaranzia::Macchina)
        ->and($garanzia->tenant_id)->toBe($this->ente->id)
        ->and($garanzia->data_inizio->toDateString())->toBe('2026-09-01')
        ->and($garanzia->durata_mesi)->toBe(24)
        ->and($garanzia->data_scadenza_effettiva->toDateString())->toBe('2028-09-01');
});

it('starts the garanzia on the day of installation when no start is given, or today without that', function (?string $installazione, string $inizio) {
    ($this->nuovo)()
        ->set('strumentoForm.data_installazione', (string) $installazione)
        ->set('garanziaNuova.durata_mesi', '12')
        ->call('saveStrumento')
        ->assertHasNoErrors();

    expect(($this->garanzie)()->sole()->data_inizio->toDateString())->toBe($inizio);
})->with([
    'installata prima' => ['2026-06-15', '2026-06-15'],
    'senza data di installazione' => [null, '2026-10-11'],
]);

it('creates no garanzia when the fields are left empty', function () {
    ($this->nuovo)()->call('saveStrumento')->assertHasNoErrors();

    expect(($this->garanzie)())->toBeEmpty();
});

it('refuses a start without months, and months that are not a duration, and saves nothing', function (string $inizio, string $mesi, string $regola) {
    ($this->nuovo)()
        ->set('garanziaNuova.data_inizio', $inizio)
        ->set('garanziaNuova.durata_mesi', $mesi)
        ->call('saveStrumento')
        ->assertHasErrors(['garanziaNuova.durata_mesi' => $regola])
        ->assertSet('showStrumentoForm', true);

    expect(Strumento::where('nome', 'Cappa')->exists())->toBeFalse();
})->with([
    'un inizio senza durata' => ['2026-09-01', '', 'required_with'],
    'zero mesi' => ['', '0', 'min'],
    'troppi mesi' => ['', '601', 'max'],
    'una parola' => ['', 'due anni', 'integer'],
]);

it('refuses a start that is not a date', function () {
    ($this->nuovo)()
        ->set('garanziaNuova.data_inizio', 'ieri')
        ->set('garanziaNuova.durata_mesi', '12')
        ->call('saveStrumento')
        ->assertHasErrors(['garanziaNuova.data_inizio' => 'date']);
});

it('opens the next form without the garanzia of the strumento just saved', function () {
    ($this->nuovo)()
        ->set('garanziaNuova.data_inizio', '2026-09-01')
        ->set('garanziaNuova.durata_mesi', '24')
        ->call('saveStrumento')
        ->call('addStrumento')
        ->assertSet('garanziaNuova', ['data_inizio' => '', 'durata_mesi' => '']);
});

it('neither shows the block nor writes a garanzia for who cannot manage them', function () {
    // Chi registra macchine ma non gestisce le garanzie: il blocco non c'è, e
    // una richiesta costruita a mano non la crea lo stesso.
    Role::create(['name' => 'Solo macchine', 'guard_name' => 'web'])
        ->givePermissionTo(['unita_organizzativa.view', 'strumenti.view', 'strumenti.create', 'fornitori.view']);
    $chi = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $chi->assignRole('Solo macchine');

    $modulo = ($this->nuovo)($chi->fresh());

    expect($modulo->html())->not->toContain('data-garanzia-nuova');

    $modulo->set('garanziaNuova.durata_mesi', '24')->call('saveStrumento')->assertHasNoErrors();

    expect(Strumento::where('nome', 'Cappa')->exists())->toBeTrue()
        ->and(Garanzia::count())->toBe(0);
});

it('can then be changed from the garanzie tab, as before', function () {
    ($this->nuovo)()->set('garanziaNuova.durata_mesi', '12')->call('saveStrumento');

    $strumento = Strumento::where('nome', 'Cappa')->sole();
    $garanzia = ($this->garanzie)()->sole();

    scheda($this->admin, $strumento)
        ->call('openModificaGaranzia', $garanzia->id)
        ->assertSet('garanziaForm.durata_mesi', 12)
        ->set('garanziaForm.durata_mesi', 36)
        ->call('saveGaranzia')
        ->assertHasNoErrors();

    expect($garanzia->fresh()->durata_mesi)->toBe(36);
});
