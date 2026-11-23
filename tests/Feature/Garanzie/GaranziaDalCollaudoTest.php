<?php

use App\Enums\SoggettoGaranzia;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Garanzie\CollaudoMancante;
use App\Support\Garanzie\GaranzieDalCollaudo;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * La garanzia della macchina conta dal collaudo (🔗 ADR-065).
 *
 * «Data installazione» è diventata «data di collaudo», e l'inizio della
 * garanzia è sparito dai moduli: per `soggetto = macchina` vale la data di
 * collaudo dello strumento, anche per i rinnovi. Un collaudo corretto sposta
 * le garanzie della macchina; quelle dei pezzi montati contano dal montaggio
 * e non si muovono.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00:00'));

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ospedale']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->fornitore = Fornitore::factory()->forTenant($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave', 'data_installazione' => '2025-01-10', 'fornitore_id' => $this->fornitore->id]);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->ricambio = function (Strumento $strumento, string $montaggio = '2025-06-01'): Garanzia {
        $utilizzo = RicambioUtilizzo::factory()
            ->forStrumento($strumento)
            ->forRicambio(Ricambio::factory()->forTenant($this->ente)->create())
            ->create(['data' => $montaggio]);

        return Garanzia::factory()->forRicambio($utilizzo)->scadenzaDichiarata('2027-06-01')->create(['data_inizio' => $montaggio]);
    };

    // Il modulo «Modifica strumento» con il solo collaudo cambiato.
    $this->collaudo = fn (string $data) => scheda($this->admin, $this->strumento->fresh())
        ->call('edit')
        ->set('strumentoForm.data_installazione', $data)
        ->call('save');
});

// ─── Il tab Garanzie ─────────────────────────────────────────────────────────

it('has no start field in the garanzia form, and says it counts from the collaudo', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
        ->assertSee('Conta dal collaudo, il')
        ->assertSee('10/01/2025')
        ->assertSeeHtml('wire:model="garanziaForm.durata_mesi"')
        ->assertSeeHtml('wire:model="garanziaForm.scadenza"')
        ->assertDontSeeHtml('garanziaForm.data_inizio')
        ->assertDontSee('Data inizio');
});

it('refuses a garanzia for a machine without collaudo, asks for it, and saves nothing', function () {
    $this->strumento->update(['data_installazione' => null]);

    scheda($this->admin, $this->strumento->fresh())
        ->call('openNuovaGaranzia')
        ->set('garanziaForm.durata_mesi', 24)
        ->call('saveGaranzia')
        ->assertHasErrors(['garanziaForm.collaudo'])
        ->assertSee('serve prima la data di collaudo')
        ->assertSeeHtml('data-collaudo-mancante')
        ->assertSet('showGaranziaForm', true);

    expect(Garanzia::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses also to change an old garanzia of a machine without collaudo', function () {
    // Le garanzie storiche su una macchina senza collaudo non si toccano:
    // nemmeno dal tab, finché qualcuno non scrive la data.
    $garanzia = Garanzia::factory()->forStrumento($this->strumento)->create(['data_inizio' => '2024-05-01', 'durata_mesi' => 12]);
    Strumento::withoutGlobalScopes()->whereKey($this->strumento->id)->update(['data_installazione' => null]);

    scheda($this->admin, $this->strumento->fresh())
        ->call('openModificaGaranzia', $garanzia->id)
        ->set('garanziaForm.durata_mesi', 36)
        ->call('saveGaranzia')
        ->assertHasErrors(['garanziaForm.collaudo']);

    expect($garanzia->fresh()->durata_mesi)->toBe(12)
        ->and($garanzia->fresh()->data_inizio->toDateString())->toBe('2024-05-01');
});

it('ignores a start forged into the request of the tab', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
        ->set('garanziaForm.data_inizio', '2020-01-01')
        ->set('garanziaForm.durata_mesi', 24)
        ->call('saveGaranzia')
        ->assertHasNoErrors();

    $garanzia = Garanzia::where('strumento_id', $this->strumento->id)->sole();

    expect($garanzia->data_inizio->toDateString())->toBe('2025-01-10')
        ->and($garanzia->data_scadenza_effettiva->toDateString())->toBe('2027-01-10');
});

it('registers an extension with the date it lasts until, counted from the collaudo', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
        ->set('garanziaForm.durata_mesi', '')
        ->set('garanziaForm.scadenza', '2029-03-31')
        ->call('saveGaranzia')
        ->assertHasNoErrors();

    $garanzia = Garanzia::where('strumento_id', $this->strumento->id)->sole();

    expect($garanzia->soggetto)->toBe(SoggettoGaranzia::Macchina)
        ->and($garanzia->data_inizio->toDateString())->toBe('2025-01-10')
        ->and($garanzia->durata_mesi)->toBeNull()
        ->and($garanzia->data_scadenza_dichiarata->toDateString())->toBe('2029-03-31')
        ->and($garanzia->data_scadenza_effettiva->toDateString())->toBe('2029-03-31');

    // La riga del tab dice fino a quando, e la modifica la riapre così.
    scheda($this->admin, $this->strumento)
        ->assertSee('fino al 31/03/2029')
        ->call('openModificaGaranzia', $garanzia->id)
        ->assertSet('garanziaForm.scadenza', '2029-03-31')
        ->assertSet('garanziaForm.durata_mesi', null);
});

it('refuses both a durata and a scadenza, and a scadenza not after the collaudo', function (mixed $mesi, string $scadenza, string $campo, string $regola) {
    scheda($this->admin, $this->strumento)
        ->call('openNuovaGaranzia')
        ->set('garanziaForm.durata_mesi', $mesi)
        ->set('garanziaForm.scadenza', $scadenza)
        ->call('saveGaranzia')
        ->assertHasErrors([$campo => $regola]);

    expect(Garanzia::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'tutte e due' => [24, '2029-03-31', 'garanziaForm.durata_mesi', 'prohibits'],
    'il giorno del collaudo' => ['', '2025-01-10', 'garanziaForm.scadenza', 'after'],
    'prima del collaudo' => ['', '2024-12-31', 'garanziaForm.scadenza', 'after'],
]);

it('makes an old garanzia count from the collaudo when it is changed from the tab', function () {
    $garanzia = Garanzia::factory()->forStrumento($this->strumento)->create(['data_inizio' => '2025-03-01', 'durata_mesi' => 24]);

    scheda($this->admin, $this->strumento)
        ->call('openModificaGaranzia', $garanzia->id)
        ->set('garanziaForm.durata_mesi', 36)
        ->call('saveGaranzia')
        ->assertHasNoErrors();

    expect($garanzia->fresh()->data_inizio->toDateString())->toBe('2025-01-10')
        ->and($garanzia->fresh()->data_scadenza_effettiva->toDateString())->toBe('2028-01-10');
});

it('shows no start column in the tab, and the collaudo the garanzie count from', function () {
    Garanzia::factory()->forStrumento($this->strumento)->create(['data_inizio' => '2025-01-10', 'durata_mesi' => 24]);

    scheda($this->admin, $this->strumento)
        ->assertSee('Contano dal collaudo, il 10/01/2025')
        ->assertDontSeeHtml('data-etichetta="Inizio"')
        ->assertSee('24 mesi');

    $this->strumento->update(['data_installazione' => null]);

    scheda($this->admin, $this->strumento->fresh())->assertSee('Manca la data di collaudo');
});

// ─── Il cambio di collaudo ───────────────────────────────────────────────────

it('moves the scadenza of a durata garanzia when the collaudo changes', function () {
    $garanzia = GaranzieDalCollaudo::registra($this->strumento, 24);

    ($this->collaudo)('2025-04-15')->assertHasNoErrors();

    expect($garanzia->fresh()->data_inizio->toDateString())->toBe('2025-04-15')
        ->and($garanzia->fresh()->data_scadenza_effettiva->toDateString())->toBe('2027-04-15');
});

it('moves the start of a declared garanzia but never its scadenza', function () {
    $garanzia = GaranzieDalCollaudo::registra($this->strumento, Carbon::parse('2029-03-31'));

    ($this->collaudo)('2025-04-15')->assertHasNoErrors();

    expect($garanzia->fresh()->data_inizio->toDateString())->toBe('2025-04-15')
        ->and($garanzia->fresh()->data_scadenza_dichiarata->toDateString())->toBe('2029-03-31')
        ->and($garanzia->fresh()->data_scadenza_effettiva->toDateString())->toBe('2029-03-31');
});

it('never moves the garanzia of a mounted ricambio when the collaudo changes', function () {
    $pezzo = ($this->ricambio)($this->strumento);
    $macchina = GaranzieDalCollaudo::registra($this->strumento, 24);

    ($this->collaudo)('2025-04-15')->assertHasNoErrors();

    $pezzo = Garanzia::withoutGlobalScopes()->find($pezzo->id);

    expect($pezzo->data_inizio->toDateString())->toBe('2025-06-01')
        ->and($pezzo->data_scadenza_effettiva->toDateString())->toBe('2027-06-01')
        ->and($macchina->fresh()->data_inizio->toDateString())->toBe('2025-04-15');
});

it('moves only the garanzie of that machine, and not the trashed ones', function () {
    $altra = Strumento::factory()->forNode($this->dept)->create(['data_installazione' => '2025-01-10']);
    $dellAltra = GaranzieDalCollaudo::registra($altra, 24);
    $cestinata = GaranzieDalCollaudo::registra($this->strumento, 12);
    $cestinata->delete();

    ($this->collaudo)('2025-04-15')->assertHasNoErrors();

    expect($dellAltra->fresh()->data_inizio->toDateString())->toBe('2025-01-10')
        ->and(Garanzia::withoutGlobalScopes()->withTrashed()->find($cestinata->id)->data_inizio->toDateString())->toBe('2025-01-10');
});

it('recalculates from the model too, without a user and without scopes', function () {
    // In console i global scope si ritirano: la strada è la stessa, e le
    // query hanno il tenant esplicito.
    $garanzia = GaranzieDalCollaudo::registra($this->strumento, 24);

    Strumento::withoutGlobalScopes()->find($this->strumento->id)->update(['data_installazione' => '2025-02-20']);

    expect($garanzia->fresh()->data_scadenza_effettiva->toDateString())->toBe('2027-02-20');
});

it('writes nothing when the collaudo does not change', function () {
    $garanzia = GaranzieDalCollaudo::registra($this->strumento, 24);
    $prima = $garanzia->fresh()->updated_at;

    $this->travel(1)->hours();
    ($this->collaudo)('2025-01-10')->assertHasNoErrors();

    expect($garanzia->fresh()->updated_at->equalTo($prima))->toBeTrue();
});

it('refuses to take the collaudo away from a machine with a garanzia, and keeps it', function () {
    GaranzieDalCollaudo::registra($this->strumento, 24);

    ($this->collaudo)('')
        ->assertHasErrors(['strumentoForm.data_installazione' => 'required'])
        ->assertSee('la data si può correggere, non togliere');

    expect($this->strumento->fresh()->data_installazione->toDateString())->toBe('2025-01-10');
});

it('still lets a machine without garanzie, or an old one without collaudo, be saved without the date', function () {
    ($this->collaudo)('')->assertHasNoErrors();
    expect($this->strumento->fresh()->data_installazione)->toBeNull();

    // La macchina storica: garanzia sì, collaudo mai scritto. Si modifica
    // come sempre; la regola vale per chi la data l'aveva.
    Garanzia::factory()->forStrumento(Strumento::factory()->forNode($this->dept)->create())->create();
    $storica = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Storica', 'fornitore_id' => $this->fornitore->id]);
    Garanzia::factory()->forStrumento($storica)->create(['data_inizio' => '2024-01-01']);
    Strumento::withoutGlobalScopes()->whereKey($storica->id)->update(['data_installazione' => null]);

    scheda($this->admin, $storica->fresh())
        ->call('edit')
        ->set('strumentoForm.nome', 'Storica rinominata')
        ->call('save')
        ->assertHasNoErrors();

    expect($storica->fresh()->nome)->toBe('Storica rinominata');
});

it('refuses a collaudo on or after a declared scadenza of the machine', function (string $collaudo) {
    $garanzia = GaranzieDalCollaudo::registra($this->strumento, Carbon::parse('2026-03-31'));

    ($this->collaudo)($collaudo)
        ->assertHasErrors(['strumentoForm.data_installazione'])
        ->assertSee('vale fino al 31/03/2026: il collaudo deve venire prima');

    expect($this->strumento->fresh()->data_installazione->toDateString())->toBe('2025-01-10')
        ->and($garanzia->fresh()->data_inizio->toDateString())->toBe('2025-01-10');
})->with(['lo stesso giorno' => ['2026-03-31'], 'dopo' => ['2026-05-01']]);

it('leaves a declared garanzia as it is if a collaudo written elsewhere passes its scadenza', function () {
    // Il modulo lo rifiuta; chi scrive senza passare da lì non deve far
    // fallire il proprio gesto per una riga incoerente (stessa scelta del
    // montaggio dei pezzi).
    $garanzia = GaranzieDalCollaudo::registra($this->strumento, Carbon::parse('2026-03-31'));

    Strumento::withoutGlobalScopes()->find($this->strumento->id)->update(['data_installazione' => '2026-05-01']);

    expect($this->strumento->fresh()->data_installazione->toDateString())->toBe('2026-05-01')
        ->and($garanzia->fresh()->data_inizio->toDateString())->toBe('2025-01-10')
        ->and($garanzia->fresh()->data_scadenza_effettiva->toDateString())->toBe('2026-03-31');
});

// ─── Il dominio ──────────────────────────────────────────────────────────────

it('refuses to create a machine garanzia without collaudo even outside the forms', function () {
    $senza = Strumento::factory()->forNode($this->dept)->create(['data_installazione' => null]);

    expect(fn () => GaranzieDalCollaudo::registra($senza, 24))->toThrow(CollaudoMancante::class)
        ->and(fn () => Garanzia::factory()->forStrumento($senza)->create())->toThrow(CollaudoMancante::class)
        ->and(Garanzia::withoutGlobalScopes()->count())->toBe(0);

    // La garanzia di un pezzo non dipende dal collaudo della macchina.
    expect(($this->ricambio)($senza)->exists)->toBeTrue();
});

it('lets only the garanzia of the machine count from the collaudo', function () {
    $pezzo = ($this->ricambio)($this->strumento);

    expect(fn () => $pezzo->contaDalCollaudo(Carbon::parse('2025-01-10')))->toThrow(LogicException::class);
});

it('refuses to change through the service a garanzia of another machine', function () {
    $altra = Strumento::factory()->forNode($this->dept)->create(['data_installazione' => '2025-01-10']);
    $garanzia = GaranzieDalCollaudo::registra($altra, 24);

    expect(fn () => GaranzieDalCollaudo::modifica($garanzia, $this->strumento, 36))->toThrow(LogicException::class)
        ->and($garanzia->fresh()->durata_mesi)->toBe(24);
});

it('keeps an extension from the collaudo superseding the first garanzia', function (int|string $estensione) {
    // `scopeNonSuperate()` e `garanzieInVigore()` confrontano le scadenze, e
    // un rinnovo che conta dal collaudo le supera come prima.
    $prima = GaranzieDalCollaudo::registra($this->strumento, 12);
    $rinnovo = GaranzieDalCollaudo::registra($this->strumento, is_int($estensione) ? $estensione : Carbon::parse($estensione));

    expect(Garanzia::query()->where('strumento_id', $this->strumento->id)->nonSuperate()->pluck('id')->all())->toBe([$rinnovo->id])
        ->and(Strumento::garanzieInVigore(collect([$prima->fresh(), $rinnovo->fresh()]))->pluck('id')->all())->toBe([$rinnovo->id])
        ->and($prima->fresh()->isScaduta())->toBeTrue();
})->with(['una durata più lunga' => [36], 'la scadenza dichiarata' => ['2028-06-30']]);
