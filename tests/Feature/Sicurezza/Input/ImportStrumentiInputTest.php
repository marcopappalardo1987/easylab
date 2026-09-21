<?php

use App\Livewire\Strumenti\ImportStrumenti;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * 🔴 L'import CSV come superficie d'ingresso (S7, T1c · OWASP A03/A04).
 *
 * Il file arriva da fuori, e il risultato dell'analisi vive in proprietà
 * pubbliche di un componente Livewire: tutte e due sono input dell'utente.
 * I casi che contano non sono il CSV ben fatto (lo copre
 * `Strumenti/ImportStrumentiTest`), ma quello che un Excel italiano o un
 * client manomesso mandano davvero.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dip = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Neonatologia']);
    $this->lab = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->dip)->create(['nome' => 'Terapia intensiva']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

function csvDaImportare(string $contenuto): UploadedFile
{
    return UploadedFile::fake()->createWithContent('strumenti.csv', $contenuto);
}

const INTESTAZIONE_IMPORT = "nome;modello;matricola;data_installazione;ubicazione;provenienza\n";

it('refuses a client that rewrites the analysed rows before importing', function () {
    // L'analisi dà zero righe valide; il client prova a riscriverle con una
    // riga "senza errori" e un nodo a sua scelta, saltando ogni controllo.
    $t = Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare(INTESTAZIONE_IMPORT."Autoclave;;;;Reparto inesistente;\n"))
        ->call('analizza');

    expect(fn () => $t->set('righe', [[
        'numero' => 2,
        'dati' => ['nome' => str_repeat('x', 300), 'modello' => '', 'matricola' => '', 'data_installazione' => '', 'ubicazione' => '', 'provenienza' => ''],
        'nodoId' => $this->ente->id,
        'nodoNome' => 'Ente A',
        'errori' => [],
    ]]))->toThrow(CannotUpdateLockedPropertyException::class);

    $t->call('importa');

    expect(Strumento::withoutGlobalScopes()->count())->toBe(0);
});

it('does not let the client flip the analysis flags either', function (string $proprieta, mixed $valore) {
    expect(fn () => Livewire::actingAs($this->admin)->test(ImportStrumenti::class)->set($proprieta, $valore))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    ['analizzato', true],
    ['troncato', true],
    ['importate', 99],
    ['scartate', 0],
]);

it('reads a Windows-1252 file exported by the Italian Excel without corrupting accents', function () {
    // «Città» e «Unità» in CP1252: 0xE0 non è UTF-8 valido. Senza conversione
    // la stringa rompe la serializzazione del componente e, su Postgres, l'INSERT.
    $csv = mb_convert_encoding(INTESTAZIONE_IMPORT."Unità centrifuga;Modello è;;;Terapia intensiva;Città\n", 'Windows-1252', 'UTF-8');
    expect(mb_check_encoding($csv, 'UTF-8'))->toBeFalse();

    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare($csv))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', 1);

    $strumento = Strumento::withoutGlobalScopes()->sole();
    expect($strumento->nome)->toBe('Unità centrifuga')
        ->and($strumento->modello)->toBe('Modello è');
});

it('keeps a UTF-8 file as it is', function () {
    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare(INTESTAZIONE_IMPORT."Unità centrifuga;;;;Terapia intensiva;\n"))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', 1);

    expect(Strumento::withoutGlobalScopes()->sole()->nome)->toBe('Unità centrifuga');
});

it('refuses a binary file renamed to csv', function () {
    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare("MZ\x90\x00\x03\x00\x00\x00\x04\x00nome;ubicazione\n"))
        ->call('analizza')
        ->assertHasErrors('file')
        ->assertSet('analizzato', false);
});

it('refuses a file over 2 MB', function () {
    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', UploadedFile::fake()->create('strumenti.csv', 2049, 'text/csv'))
        ->call('analizza')
        ->assertHasErrors(['file' => 'max']);
});

it('flags a giant field instead of letting it reach the database', function () {
    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare(INTESTAZIONE_IMPORT.str_repeat('A', 100_000).';'.str_repeat('B', 300).";;;Terapia intensiva;\n"))
        ->call('analizza')
        ->assertSet('analizzato', true)
        ->call('importa')
        ->assertSet('importate', null);

    expect(Strumento::withoutGlobalScopes()->count())->toBe(0);
});

it('stops at the row cap however many rows the file carries', function () {
    $righe = str_repeat("Pipetta;;;;Terapia intensiva;\n", ImportStrumenti::MAX_RIGHE + 50);

    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare(INTESTAZIONE_IMPORT.$righe))
        ->call('analizza')
        ->assertSet('troncato', true)
        ->assertCount('righe', ImportStrumenti::MAX_RIGHE);
});

it('stores formula-looking values verbatim: neutralising them is the job of the export', function (string $formula) {
    // Si difende in USCITA (CsvSicuro), non storpiando il dato: un «-5 °C» o un
    // «@reparto» sono valori legittimi, e un apice in testa li corromperebbe.
    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare(INTESTAZIONE_IMPORT.'"'.str_replace('"', '""', $formula).'";;;;Terapia intensiva;'."\n"))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', 1);

    expect(Strumento::withoutGlobalScopes()->sole()->nome)->toBe($formula);
})->with([
    '=HYPERLINK("http://evil.example","x")',
    '+1+1',
    '-2+3',
    '@SUM(A1)',
]);

it('cannot place an instrument on a node of another tenant by naming it', function () {
    $altro = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    UnitaOrganizzativa::factory()->dipartimento()->under($altro)->create(['nome' => 'Reparto di B']);

    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare(INTESTAZIONE_IMPORT."Autoclave;;;;Reparto di B;\nAutoclave 2;;;;Ente B > Reparto di B;\n"))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', null);

    expect(Strumento::withoutGlobalScopes()->count())->toBe(0);
});

it('flags year zero as an invalid date, which Postgres refuses', function (string $data) {
    $t = Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare(INTESTAZIONE_IMPORT."Autoclave;;;{$data};Terapia intensiva;\n"))
        ->call('analizza');

    expect($t->get('righe')[0]['errori'])->toContain('Data non valida (usa AAAA-MM-GG o GG/MM/AAAA)');
})->with(['0000-01-01', '01/01/0000']);

it('still accepts year one, the first year Postgres stores', function () {
    $t = Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare(INTESTAZIONE_IMPORT."Autoclave;;;0001-01-01;Terapia intensiva;\n"))
        ->call('analizza');

    expect($t->get('righe')[0]['errori'])->toBe([]);
});

it('still finds the header of a BOM file with one stray Windows-1252 byte', function () {
    $csv = "\xEF\xBB\xBF".INTESTAZIONE_IMPORT."Autoclave Unit\xE0;;;;Terapia intensiva;\n";

    $t = Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare($csv))
        ->call('analizza');

    expect($t->get('righe')[0]['dati']['nome'])->toBe('Autoclave Unità')
        ->and($t->get('righe')[0]['errori'])->toBe([]);
});

it('keeps a discarded giant row out of the Livewire snapshot', function () {
    $t = Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvDaImportare(INTESTAZIONE_IMPORT.str_repeat('A', 1_500_000).';;;;'.str_repeat('U', 50_000).";\n"))
        ->call('analizza');

    $riga = $t->get('righe')[0];

    expect($riga['errori'])->toContain('Nome troppo lungo')
        ->and(mb_strlen($riga['dati']['nome']))->toBeLessThanOrEqual(ImportStrumenti::ANTEPRIMA_SCARTATE + 3)
        ->and(strlen(json_encode($t->snapshot)))->toBeLessThan(100_000);
});
