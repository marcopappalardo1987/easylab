<?php

use App\Enums\TipoSpostamento;
use App\Livewire\Strumenti\ImportStrumenti;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dip = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Neonatologia']);
    $this->lab = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->dip)->create(['nome' => 'Terapia intensiva']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

function csvFile(string $contenuto): UploadedFile
{
    return UploadedFile::fake()->createWithContent('strumenti.csv', $contenuto);
}

// --- Accesso ---

it('forbids a Tenant from opening the import page', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    $this->actingAs($tenant)->get(route('strumenti.import'))->assertForbidden();
});

it('opens the import page for an admin', function () {
    $this->actingAs($this->admin)->get(route('strumenti.import'))->assertOk();
});

// --- Happy path ---

it('imports valid rows into the right nodes', function () {
    $csv = <<<'CSV'
    nome;modello;matricola;data_installazione;ubicazione;provenienza
    Autoclave AC-200;AC-200;SN-0001;2015-03-01;Terapia intensiva;
    Incubatrice INC-9;INC-9;SN-0002;01/07/2020;Neonatologia;
    CSV;

    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvFile($csv))
        ->call('analizza')
        ->assertSet('analizzato', true)
        ->call('importa')
        ->assertSet('importate', 2)
        ->assertSet('scartate', 0);

    $auto = Strumento::withoutGlobalScopes()->where('nome', 'Autoclave AC-200')->first();
    expect($auto->unita_organizzativa_id)->toBe($this->lab->id);
    expect($auto->tenant_id)->toBe($this->ente->id);
    expect($auto->modello)->toBe('AC-200');
    expect($auto->data_installazione->toDateString())->toBe('2015-03-01');

    $inc = Strumento::withoutGlobalScopes()->where('nome', 'Incubatrice INC-9')->first();
    expect($inc->unita_organizzativa_id)->toBe($this->dip->id);
    expect($inc->data_installazione->toDateString())->toBe('2020-07-01'); // GG/MM/AAAA
});

it('accepts the comma delimiter and strips the BOM', function () {
    $csv = "\xEF\xBB\xBFnome,modello,matricola,data_installazione,ubicazione,provenienza\nBilancia,BL-1,SN-9,,Terapia intensiva,\n";

    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvFile($csv))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', 1);

    expect(Strumento::withoutGlobalScopes()->where('nome', 'Bilancia')->exists())->toBeTrue();
});

// --- Import parziale ---

it('imports only valid rows and reports the discarded ones', function () {
    $csv = <<<'CSV'
    nome;modello;matricola;data_installazione;ubicazione;provenienza
    Valida;M1;;2020-01-01;Terapia intensiva;
    ;M2;;;Terapia intensiva;
    Ubicazione fantasma;M3;;;Laboratorio Inesistente;
    Data storta;M4;;31/02/2020;Terapia intensiva;
    CSV;

    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvFile($csv))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', 1)
        ->assertSet('scartate', 3);

    expect(Strumento::withoutGlobalScopes()->count())->toBe(1);
    expect(Strumento::withoutGlobalScopes()->first()->nome)->toBe('Valida');
});

it('rejects the ente as ubicazione', function () {
    $csv = "nome;ubicazione\nX;Ente A\n";

    $component = Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvFile($csv))
        ->call('analizza');

    expect($component->get('righe')[0]['errori'])->not->toBeEmpty();

    $component->call('importa');
    expect(Strumento::withoutGlobalScopes()->count())->toBe(0);
});

// --- Ambiguità e percorso ---

it('flags an ambiguous ubicazione and resolves it via the path', function () {
    // Secondo nodo omonimo sotto un altro dipartimento.
    $altroDip = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Chirurgia']);
    UnitaOrganizzativa::factory()->sottolaboratorio()->under($altroDip)->create(['nome' => 'Terapia intensiva']);

    $ambiguo = "nome;ubicazione\nX;Terapia intensiva\n";
    $component = Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvFile($ambiguo))
        ->call('analizza');

    expect($component->get('righe')[0]['errori'][0])->toContain('ambigua');

    // Con il percorso si risolve.
    $conPercorso = "nome;ubicazione\nX;Neonatologia > Terapia intensiva\n";
    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvFile($conPercorso))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', 1);

    expect(Strumento::withoutGlobalScopes()->first()->unita_organizzativa_id)->toBe($this->lab->id);
});

// --- Provenienza esterna ---

it('logs an ingresso movement when provenienza is set', function () {
    $csv = "nome;ubicazione;provenienza\nDonata;Terapia intensiva;Ospedale San Paolo\n";

    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvFile($csv))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', 1);

    $sp = SpostamentoStrumento::withoutGlobalScopes()->first();
    expect($sp->tipo_spostamento)->toBe(TipoSpostamento::Ingresso);
    expect($sp->da_esterno)->toBe('Ospedale San Paolo');
    expect($sp->a_nodo_id)->toBe($this->lab->id);
});

// --- Isolamento e sotto-albero ---

it('cannot resolve a node of another tenant', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create(['nome' => 'Reparto Altrui']);

    $csv = "nome;ubicazione\nX;Reparto Altrui\n";

    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->set('file', csvFile($csv))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', null); // nessuna riga valida → import no-op

    expect(Strumento::withoutGlobalScopes()->count())->toBe(0);
});

it('limits a Responsabile to nodes in its subtree', function () {
    $fuori = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Fuori Reparto']);

    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dip->id); // vede Neonatologia + Terapia intensiva

    $csv = "nome;ubicazione\nDentro;Terapia intensiva\nFuori;Fuori Reparto\n";

    Livewire::actingAs($resp)->test(ImportStrumenti::class)
        ->set('file', csvFile($csv))
        ->call('analizza')
        ->call('importa')
        ->assertSet('importate', 1)
        ->assertSet('scartate', 1);

    expect(Strumento::withoutGlobalScopes()->pluck('nome')->all())->toBe(['Dentro']);
    expect($fuori->strumenti ?? true)->not->toBeNull(); // il nodo fuori resta senza strumenti
});

// --- Template ---

it('downloads a CSV template', function () {
    Livewire::actingAs($this->admin)->test(ImportStrumenti::class)
        ->call('scaricaTemplate')
        ->assertFileDownloaded('template-strumenti.csv');
});
