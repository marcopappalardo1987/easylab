<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Fornitori\ElencoFornitori;
use App\Models\Fornitore;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Fornitore: anagrafica per Ente e associazione 1-N (🔗 ADR-023, ERD §7.3 —
 * S4, 15 Ago 2026).
 *
 * ⚠️ Il pivot `fornitore_strumento` della prima stesura dell'ERD **non
 * esiste**: ADR-023 ha corretto la relazione in 1-N, e i casi qui sotto
 * verificano che una macchina abbia un fornitore solo.
 *
 * Le aree rosse sono due, e i negativi vengono prima: **la tenancy** (un
 * fornitore di un altro Ente non è nemmeno nominabile) e **le
 * autorizzazioni** (chi legge non scrive).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->fornitore = Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => 'Thermo Fisher Italia']);

    $this->utente = function (string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create([
            'tenant_id' => ($ente ?? $this->ente)->id,
            'two_factor_confirmed_at' => now(),
        ]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->admin = ($this->utente)('Admin');
});

// --- Tenancy: i negativi per primi ---

it('never shows a fornitore of another Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    Fornitore::factory()->forTenant($altroEnte)->create(['ragione_sociale' => 'Fornitore altrui']);

    $this->actingAs($this->admin);

    expect(Fornitore::pluck('ragione_sociale')->all())->toBe(['Thermo Fisher Italia']);
});

it('refuses a strumento pointing at a fornitore of another Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $altrui = Fornitore::factory()->forTenant($altroEnte)->create();

    // In CONSOLE, dove nessuno scope filtra: è il contesto in cui la guardia
    // del model è l'unica difesa, e dove un payload forgiato arriverebbe.
    expect(fn () => Strumento::factory()->forNode($this->dept)->create(['fornitore_id' => $altrui->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to reassign a strumento to a fornitore of another Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $altrui = Fornitore::factory()->forTenant($altroEnte)->create();
    $strumento = Strumento::factory()->forNode($this->dept)->create(['fornitore_id' => $this->fornitore->id]);

    // Anche in UPDATE: la guardia sta su `creating` E `updating`, mai su
    // `saving` — dove `BelongsToTenant` non ha ancora riscritto `tenant_id`.
    expect(fn () => $strumento->update(['fornitore_id' => $altrui->id]))
        ->toThrow(InvalidArgumentException::class);
});

// --- La cancellazione, e perché la guardia sta nel model ---

it('refuses to bin a fornitore that still has machines', function () {
    Strumento::factory()->forNode($this->dept)->create(['fornitore_id' => $this->fornitore->id]);

    // Nel model e non nel componente: vale per il CRUD, per l'import e per la
    // console. Senza, la scheda mostrerebbe un riferimento illeggibile.
    expect(fn () => $this->fornitore->delete())->toThrow(RuntimeException::class)
        ->and($this->fornitore->fresh()->trashed())->toBeFalse();
});

it('bins a fornitore with no machines, and says why when it cannot', function () {
    Strumento::factory()->forNode($this->dept)->create(['fornitore_id' => $this->fornitore->id]);

    Livewire::actingAs($this->admin)->test(ElencoFornitori::class)
        ->call('confermaElimina', $this->fornitore->id)
        ->call('elimina')
        ->assertSet('notice', fn (?string $n) => $n !== null && str_contains($n, 'ancora associato'));

    expect($this->fornitore->fresh()->trashed())->toBeFalse();
});

it('keeps showing the name of a binned fornitore, so the cell is never silently empty', function () {
    // Cestinato PRIMA di assegnarlo: la guardia del model guarda il confine di
    // Ente, non lo stato della riga, e il caso reale è una macchina che resta
    // agganciata a un fornitore cestinato dopo essere stata riassegnata altrove.
    $cessato = Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => 'Fornitore cessato']);
    $cessato->delete();

    $strumento = Strumento::factory()->forNode($this->dept)->create(['fornitore_id' => $cessato->id]);

    // `withTrashed()` sulla relazione: senza, tornerebbe null e la scheda
    // mostrerebbe una cella vuota — che si legge «mai inserito» invece che
    // «cestinato».
    expect($strumento->fresh()->fornitore?->ragione_sociale)->toBe('Fornitore cessato')
        ->and($strumento->fresh()->fornitore->trashed())->toBeTrue();
});

// --- Autorizzazioni: chi legge non scrive ---

it('lets a Tenant read the anagrafica but never write it', function () {
    $tenant = ($this->utente)('Tenant');

    // `fornitori.view` al Tenant è stato applicato il 15 Ago: ADR-023 e Schema
    // Ruoli lo davano per approvato dal 3 Ago, ma il bootstrap non lo produceva.
    $this->actingAs($tenant)->get(route('fornitori.index'))->assertOk();

    Livewire::actingAs($tenant)->test(ElencoFornitori::class)
        ->call('nuovo')->assertForbidden();
});

it('refuses the whole page to a Tecnico', function () {
    // Il Tecnico non ha `fornitori.view`: il fornitore è un dato dell'Ente, non
    // gli serve per montare un pezzo.
    $this->actingAs(($this->utente)('Tecnico'))->get(route('fornitori.index'))->assertForbidden();
});

it('gives 404 when editing a fornitore of another Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $altrui = Fornitore::factory()->forTenant($altroEnte)->create();

    expect(fn () => Livewire::actingAs($this->admin)->test(ElencoFornitori::class)->call('edit', $altrui->id))
        ->toThrow(ModelNotFoundException::class);
});

// --- Il form dello strumento ---

it('requires a fornitore when creating a strumento by hand', function () {
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->dept->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Autoclave')
        ->call('saveStrumento')
        ->assertHasErrors('strumentoForm.fornitore_id');
});

it('refuses a forged fornitore_id from the browser', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $altrui = Fornitore::factory()->forTenant($altroEnte)->create();

    // La whitelist del select e la validazione al save sono la STESSA
    // definizione: un id che non compare fra le opzioni non passa nemmeno qui.
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->dept->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Autoclave')
        ->set('strumentoForm.fornitore_id', $altrui->id)
        ->call('saveStrumento')
        ->assertHasErrors('strumentoForm.fornitore_id');
});

it('saves the fornitore with the strumento', function () {
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->dept->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Autoclave')
        ->set('strumentoForm.fornitore_id', $this->fornitore->id)
        ->call('saveStrumento')
        ->assertHasNoErrors();

    expect(Strumento::where('nome', 'Autoclave')->first()->fornitore->ragione_sociale)
        ->toBe('Thermo Fisher Italia');
});

it('does not block a role that cannot even see the field', function () {
    // La regola è condizionata al permesso: un ruolo senza `fornitori.view` non
    // vede il select, e bloccarlo su un campo che non ha sarebbe un vicolo
    // cieco. Nessun ruolo reale è in questo stato oggi — è una difesa per il
    // giorno in cui la matrice cambierà.
    // Ruolo sintetico e dichiarato tale: nessun ruolo reale è oggi in questo
    // stato — revocare il permesso all'UTENTE non basta, perché arriva dal
    // ruolo, e il caso serve solo a provare che la regola è condizionata.
    $ruolo = Role::create(['name' => 'Senza fornitori', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(['unita_organizzativa.view', 'strumenti.view', 'strumenti.create']);

    $utente = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    Livewire::actingAs($utente->fresh())->test(Albero::class)
        ->call('open', $this->dept->id)
        ->call('addStrumento')
        ->set('strumentoForm.nome', 'Autoclave senza fornitore')
        ->call('saveStrumento')
        ->assertHasNoErrors();
});
