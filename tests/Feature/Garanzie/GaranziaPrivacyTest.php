<?php

use App\Enums\VisibilitaGaranzieRicambio;
use App\Models\Garanzia;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Privacy delle garanzie sui ricambi (ADR-004, Definition of Done S3: «il
 * tenant non vede le garanzie ricambi»). Area rossa: test NEGATIVI.
 *
 * Le fixture si creano in contesto console, dove i global scope non filtrano.
 * Dall'8 Ago 2026 (S4 blocco 1) la riga `ricambio` poggia su un
 * `ricambio_utilizzo` VERO: la FK esiste, e l'intero libero che stava qui
 * prima ora violerebbe il vincolo su entrambi i driver.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)
        ->forRicambio(Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Guarnizione O-Ring']))
        ->create();

    $this->garanziaMacchina = Garanzia::factory()->forStrumento($this->strumento)->create();
    $this->garanziaRicambio = Garanzia::factory()->forRicambio($this->utilizzo)->create();

    $this->utente = function (string $ruolo): User {
        $u = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u;
    };
});

// Riscritto il 15 Ago 2026 (ADR-029). Diceva `hides ricambio garanzie from the
// Tenant` e congelava una regola che nessuno aveva mai deciso: Fase 2 la dava
// per scontata, ADR-004 la ratificò dichiarandola «già previsto». Il divieto
// assoluto non esiste più — di default il Tenant vede e gestisce le garanzie
// dei pezzi montati sulle proprie macchine —, e a nasconderle è ora
// l'impostazione del suo Ente. I due casi stanno insieme perché è il CONFRONTO
// a essere la regola: la stessa riga, due Enti, due esiti.
it('shows ricambio garanzie to the Tenant by default, and hides them where the Ente says so', function () {
    $tenant = ($this->utente)('Tenant');
    $this->actingAs($tenant);

    // Default alla creazione dell'Ente: `modifica`.
    expect($this->ente->visibilita_garanzie_ricambio)->toBe(VisibilitaGaranzieRicambio::Modifica)
        ->and(Garanzia::pluck('id')->all())->toContain($this->garanziaRicambio->id);

    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);

    // `fresh()`: la Policy legge l'Ente dalla relazione `ente`, che Eloquent
    // cachea sull'istanza — senza, si leggerebbe il valore di prima.
    $this->actingAs($tenant->fresh());

    expect(Garanzia::pluck('id')->all())->toBe([$this->garanziaMacchina->id])
        ->and(Garanzia::find($this->garanziaRicambio->id))->toBeNull()
        ->and(Garanzia::where('id', $this->garanziaRicambio->id)->exists())->toBeFalse();
});

// `lettura` toglie la scrittura, NON le righe: è la distinzione su cui poggia
// tutto ADR-029, e un test che non la verificasse lascerebbe passare la
// confusione fra i due piani (scope e Policy).
it('keeps the rows visible in sola lettura, and only takes away the writing', function () {
    $tenant = ($this->utente)('Tenant');
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Lettura);
    $this->actingAs($tenant->fresh());

    expect(Garanzia::pluck('id')->all())->toContain($this->garanziaRicambio->id)
        ->and(Gate::allows('view', Garanzia::class))->toBeTrue()
        ->and(Gate::allows('manage', Garanzia::class))->toBeFalse();
});

it('never lets the Ente setting touch who works for EasyLab', function () {
    // L'impostazione è una clausola verso il cliente: Admin e Tecnico non ne
    // sono toccati, o un Ente in sola lettura bloccherebbe chi il pezzo lo monta.
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);

    foreach (['Admin', 'Tecnico'] as $ruolo) {
        $this->actingAs(($this->utente)($ruolo));

        expect(Gate::allows('view', Garanzia::class))->toBeTrue("«{$ruolo}» non deve essere vincolato")
            ->and(Gate::allows('manage', Garanzia::class))->toBeTrue("«{$ruolo}» non deve essere vincolato")
            ->and(Garanzia::pluck('id')->all())->toContain($this->garanziaRicambio->id);
    }
});

// Invertito l'8 Ago 2026 (ADR-027): diceva `hides ... from the Tecnico`. Il
// divieto non veniva da ADR-004 — che nomina il solo Tenant — ma da una citazione
// allargata oltre la fonte, poi difesa da questo test. Il Tecnico monta il pezzo,
// quindi è chi conosce la garanzia; il controllo su di lui è la traccia (canale
// `audit`) e il livello 2 di ADR-007, non la cecità.
it('shows ricambio garanzie to the Tecnico, who mounts the part', function () {
    $tecnico = ($this->utente)('Tecnico');
    $this->actingAs($tecnico);

    expect($tecnico->can('garanzie.ricambio.view'))->toBeTrue()
        ->and(Garanzia::pluck('id')->all())->toContain($this->garanziaRicambio->id);
});

it('shows ricambio garanzie to an Admin, who holds the permission', function () {
    $admin = ($this->utente)('Admin');
    $this->actingAs($admin);

    expect($admin->can('garanzie.ricambio.view'))->toBeTrue()
        ->and(Garanzia::pluck('id')->all())->toContain($this->garanziaRicambio->id);
});

// Invertito l'8 Ago 2026 (S4 blocco 2), e il nome portava già il debito che
// chiude: diceva `still hides ... (debito S4)`. Il Responsabile aveva il
// permesso ma non vedeva le righe, perché il livello 2 filtrava su
// `strumento_id`, NULL sulle righe ricambio — `NULL IN (...)` è UNKNOWN. Ora
// `GaranziaDepartmentScope` fa il doppio salto garanzie → ricambio_utilizzo →
// strumenti. Modifica consapevole: era un fail-closed accettato per mancanza
// della tabella, non una regola.
it('shows a Responsabile the garanzie of parts mounted in their sub-tree', function () {
    $resp = ($this->utente)('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);
    $this->actingAs($resp);

    expect($resp->can('garanzie.ricambio.view'))->toBeTrue()
        ->and(Garanzia::pluck('id')->all())
        ->toEqualCanonicalizing([$this->garanziaMacchina->id, $this->garanziaRicambio->id]);
});

it('is fail-closed for an ad-hoc role without the permission', function () {
    // Un ruolo nuovo parte cieco: si vede solo con permesso esplicito.
    Role::create(['name' => 'Ospite'])->givePermissionTo('garanzie.macchina.view');
    $ospite = User::factory()->create(['tenant_id' => $this->ente->id]);
    $ospite->assignRole('Ospite');

    $this->actingAs($ospite);

    expect(Garanzia::pluck('id')->all())->toBe([$this->garanziaMacchina->id]);
});

it('does not filter in a console context, so the seeder sees everything', function () {
    // Nessun actingAs: stessa postura di TenantScope.
    expect(Garanzia::count())->toBe(2);
});

it('does not leak ricambio garanzie through the strumento relation', function () {
    // Difesa in profondità: la relazione riapplica i global scope del model.
    $this->actingAs(($this->utente)('Tenant'));

    expect($this->strumento->garanzie()->pluck('id')->all())->toBe([$this->garanziaMacchina->id]);
});
