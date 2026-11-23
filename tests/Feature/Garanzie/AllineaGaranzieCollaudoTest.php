<?php

use App\Models\Garanzia;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * `easylab:allinea-garanzie-collaudo` (🔗 ADR-065): le garanzie della macchina
 * registrate quando avevano un inizio loro.
 *
 * Di default elenca e non scrive; con `--applica` scrive le sole righe
 * disallineate e lascia stare le macchine senza collaudo. In produzione
 * `--applica` si lancia solo col via di Marco.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00:00'));

    $this->rossi = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Clinica Rossi']);
    $this->bianchi = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ospedale Bianchi']);
    $labRossi = UnitaOrganizzativa::factory()->dipartimento()->under($this->rossi)->create();
    $labBianchi = UnitaOrganizzativa::factory()->dipartimento()->under($this->bianchi)->create();

    // Rossi: una garanzia disallineata (durata) e una già allineata.
    $this->autoclave = Strumento::factory()->forNode($labRossi)->create(['nome' => 'Autoclave', 'matricola' => 'AC-1', 'data_installazione' => '2025-01-10']);
    $this->spostata = Garanzia::factory()->forStrumento($this->autoclave)->create(['data_inizio' => '2025-03-01', 'durata_mesi' => 24]);
    $this->giusta = Garanzia::factory()->forStrumento($this->autoclave)->create(['data_inizio' => '2025-01-10', 'durata_mesi' => 36]);

    // Bianchi: una dichiarata disallineata, una dichiarata che il collaudo
    // supera, e una macchina senza collaudo.
    $this->centrifuga = Strumento::factory()->forNode($labBianchi)->create(['nome' => 'Centrifuga', 'matricola' => 'CF-2', 'data_installazione' => '2024-06-01']);
    $this->dichiarata = Garanzia::factory()->forStrumento($this->centrifuga)->scadenzaDichiarata('2028-12-31')->create(['data_inizio' => '2024-09-01']);

    $this->tardiva = Strumento::factory()->forNode($labBianchi)->create(['nome' => 'Cappa tardiva', 'matricola' => null, 'data_installazione' => '2024-01-01']);
    $this->superata = Garanzia::factory()->forStrumento($this->tardiva)->scadenzaDichiarata('2024-12-31')->create(['data_inizio' => '2023-01-01']);
    Strumento::withoutGlobalScopes()->whereKey($this->tardiva->id)->update(['data_installazione' => '2025-02-01']);

    $this->senza = Strumento::factory()->forNode($labBianchi)->create(['nome' => 'Stufa senza collaudo', 'matricola' => 'ST-9', 'data_installazione' => '2024-01-01']);
    $this->orfana = Garanzia::factory()->forStrumento($this->senza)->create(['data_inizio' => '2023-05-01', 'durata_mesi' => 60]);
    Strumento::withoutGlobalScopes()->whereKey($this->senza->id)->update(['data_installazione' => null]);

    // Il pezzo montato: conta dal montaggio, e il comando non lo guarda.
    $utilizzo = RicambioUtilizzo::factory()->forStrumento($this->autoclave)
        ->forRicambio(Ricambio::factory()->forTenant($this->rossi)->create())->create(['data' => '2025-06-01']);
    $this->pezzo = Garanzia::factory()->forRicambio($utilizzo)->scadenzaDichiarata('2027-06-01')->create(['data_inizio' => '2025-06-01']);

    $this->fotografia = fn () => Garanzia::withoutGlobalScopes()->withTrashed()->orderBy('id')->get()
        ->map(fn (Garanzia $g) => [$g->id, $g->data_inizio->toDateString(), $g->data_scadenza_effettiva->toDateString(), (string) $g->updated_at])
        ->all();
});

it('lists the misaligned garanzie by client, with today and the new scadenza, and writes nothing', function () {
    $prima = ($this->fotografia)();
    $audit = Activity::count();

    $this->travel(1)->hours();

    $this->artisan('easylab:allinea-garanzie-collaudo')
        ->expectsOutputToContain('Garanzie della macchina che non contano dal collaudo: 3 su 3 macchine.')
        ->expectsOutputToContain('Clinica Rossi (Ente #'.$this->rossi->id.')')
        ->expectsOutputToContain('Ospedale Bianchi (Ente #'.$this->bianchi->id.')')
        ->expectsTable(
            ['Macchina', 'Matricola', 'Collaudo', 'Inizio di oggi', 'Durata', 'Scadenza di oggi', 'Scadenza nuova'],
            [['Autoclave', 'AC-1', '10/01/2025', '01/03/2025', '24 mesi', '01/03/2027', '10/01/2027']],
        )
        ->expectsOutputToContain('Nessuna scrittura')
        ->assertSuccessful();

    expect(($this->fotografia)())->toBe($prima)
        ->and(Activity::count())->toBe($audit);
});

it('shows the declared garanzia keeping its scadenza, and the one the collaudo passes as a manual case', function () {
    $this->artisan('easylab:allinea-garanzie-collaudo')
        ->expectsTable(
            ['Macchina', 'Matricola', 'Collaudo', 'Inizio di oggi', 'Durata', 'Scadenza di oggi', 'Scadenza nuova'],
            [
                ['Centrifuga', 'CF-2', '01/06/2024', '01/09/2024', 'fino al 31/12/2028', '31/12/2028', '31/12/2028'],
                ['Cappa tardiva', '—', '01/02/2025', '01/01/2023', 'fino al 31/12/2024', '31/12/2024', 'a mano: non viene dopo il collaudo'],
            ],
        )
        ->assertSuccessful();
});

it('lists apart the machines with a garanzia and no collaudo', function () {
    $this->artisan('easylab:allinea-garanzie-collaudo')
        ->expectsOutputToContain('Macchine con garanzia ma senza data di collaudo (non si toccano): 1.')
        ->expectsTable(
            ['Macchina', 'Matricola', 'Garanzie', 'Scadenza più lontana'],
            [['Stufa senza collaudo', 'ST-9', 1, '01/05/2028']],
        )
        ->assertSuccessful();
});

it('with --applica writes only the misaligned rows, and skips the ones without collaudo', function () {
    $this->travel(1)->hours();

    $this->artisan('easylab:allinea-garanzie-collaudo', ['--applica' => true])
        ->expectsOutputToContain('Riallineate 2 garanzie della macchina al collaudo.')
        ->expectsOutputToContain("Lasciate com'erano: 1")
        ->expectsOutputToContain('Non toccate: 1 garanzie su macchine senza data di collaudo.')
        ->assertSuccessful();

    $leggi = fn (Garanzia $g) => Garanzia::withoutGlobalScopes()->find($g->id);

    // Il ramo durata: inizio e scadenza.
    expect($leggi($this->spostata)->data_inizio->toDateString())->toBe('2025-01-10')
        ->and($leggi($this->spostata)->data_scadenza_effettiva->toDateString())->toBe('2027-01-10');

    // Il ramo dichiarato: l'inizio sì, la scadenza no.
    expect($leggi($this->dichiarata)->data_inizio->toDateString())->toBe('2024-06-01')
        ->and($leggi($this->dichiarata)->data_scadenza_effettiva->toDateString())->toBe('2028-12-31');

    // Già allineata: nemmeno salvata.
    expect($leggi($this->giusta)->updated_at->equalTo($this->giusta->updated_at))->toBeTrue();

    // A mano, senza collaudo, e il pezzo: com'erano.
    expect($leggi($this->superata)->data_inizio->toDateString())->toBe('2023-01-01')
        ->and($leggi($this->orfana)->data_inizio->toDateString())->toBe('2023-05-01')
        ->and($leggi($this->orfana)->updated_at->equalTo($this->orfana->updated_at))->toBeTrue()
        ->and($leggi($this->pezzo)->data_inizio->toDateString())->toBe('2025-06-01')
        ->and($leggi($this->pezzo)->updated_at->equalTo($this->pezzo->updated_at))->toBeTrue();

    // Un secondo giro non trova più niente da scrivere.
    $this->artisan('easylab:allinea-garanzie-collaudo', ['--applica' => true])
        ->expectsOutputToContain('Niente da riallineare.')
        ->assertSuccessful();
});

it('ignores trashed garanzie and the garanzie of trashed machines', function () {
    $this->spostata->delete();
    $this->centrifuga->delete();

    $this->artisan('easylab:allinea-garanzie-collaudo', ['--applica' => true])
        ->expectsOutputToContain('Niente da riallineare.')
        ->assertSuccessful();

    expect(Garanzia::withoutGlobalScopes()->withTrashed()->find($this->spostata->id)->data_inizio->toDateString())->toBe('2025-03-01')
        ->and(Garanzia::withoutGlobalScopes()->find($this->dichiarata->id)->data_inizio->toDateString())->toBe('2024-09-01');
});

it('does not touch a garanzia left with an Ente that is no longer the one of the machine', function () {
    // 🔗 ADR-015: il trasferimento fra Enti deve spostare le garanzie con lo
    // strumento. Una rimasta indietro non è di questa macchina per il tenant,
    // e il comando non la scrive a nome di un altro cliente.
    Garanzia::withoutGlobalScopes()->whereKey($this->spostata->id)->update(['tenant_id' => $this->bianchi->id]);

    $this->artisan('easylab:allinea-garanzie-collaudo', ['--applica' => true])
        ->expectsOutputToContain('Riallineate 1 garanzie')
        ->assertSuccessful();

    expect(Garanzia::withoutGlobalScopes()->find($this->spostata->id)->data_inizio->toDateString())->toBe('2025-03-01');
});

it('asks for confirmation in production, and writes nothing without it', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('easylab:allinea-garanzie-collaudo', ['--applica' => true])
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertFailed();

    expect(Garanzia::withoutGlobalScopes()->find($this->spostata->id)->data_inizio->toDateString())->toBe('2025-03-01');
});
