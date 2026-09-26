<?php

use App\Models\Account;
use App\Models\Documento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piattaforma\EliminaCliente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Il perimetro di `EliminaCliente` (ADR-040) confrontato con quello dell'export
 * GDPR (`PerimetroTenant`): stesse tabelle, e ora anche stessi Enti e stessi
 * nodi. Emerso dal giro dei cacciatori T5: i test di EliminaClienteTest creano
 * il reparto con `perAccount()`, cioè con un `account_id` che un reparto vero
 * non ha (il model lo vieta), e così non vedevano l'albero reale.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->cartella = sys_get_temp_dir().'/easylab-t5-elimina-'.bin2hex(random_bytes(6));
    Storage::set(Documento::DISCO, Storage::build(['driver' => 'local', 'root' => $this->cartella, 'throw' => false]));

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');

    $this->cliente = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)->create(['nome' => 'Sede di Milano']);
});

afterEach(function () {
    File::deleteDirectory($this->cartella);
});

it('deletes a customer whose tree has a real department, with its manager link', function () {
    $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($this->sede)->create(['nome' => 'Reparto vero']);
    expect($reparto->account_id)->toBeNull();

    $strumento = Strumento::factory()->forNode($reparto)->create();
    $responsabile = User::factory()->create();
    $responsabile->unitaResponsabili()->attach($reparto->id);

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    expect(DB::table('unita_organizzativa')->whereIn('id', [$this->sede->id, $reparto->id])->count())->toBe(0)
        ->and(DB::table('strumenti')->where('id', $strumento->id)->count())->toBe(0)
        ->and(DB::table('responsabile_unita')->where('unita_organizzativa_id', $reparto->id)->count())->toBe(0)
        ->and(Account::withTrashed()->whereKey($this->cliente->id)->count())->toBe(0);
});

it('deletes a customer that has a trashed Ente, with the rows of that Ente', function () {
    $cestinata = UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)->create(['nome' => 'Sede chiusa']);
    $strumento = Strumento::factory()->forNode($cestinata)->create();
    $cestinata->delete();

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    expect(DB::table('unita_organizzativa')->where('id', $cestinata->id)->count())->toBe(0)
        ->and(DB::table('strumenti')->where('id', $strumento->id)->count())->toBe(0)
        ->and(Account::withTrashed()->whereKey($this->cliente->id)->count())->toBe(0);
});

it('leaves the self-signup row with its personal data behind, detached: a retention decision (Per Marco)', function () {
    // Fotografia dello stato di fatto, non una regola: `registrazioni.account_id`
    // è nullOnDelete, e nome ed email del referente restano. Quanto a lungo è
    // una decisione di legale/DPO, non di questo codice.
    DB::table('registrazioni')->insert([
        'nome_ente' => 'Gruppo Rossi', 'nome_referente' => 'Mario Rossi', 'email' => 'mario@example.test',
        'piano' => 'saas', 'account_id' => $this->cliente->id, 'completata_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    $riga = DB::table('registrazioni')->where('email', 'mario@example.test')->first();
    expect($riga)->not->toBeNull()
        ->and($riga->account_id)->toBeNull()
        ->and($riga->nome_referente)->toBe('Mario Rossi');
});
