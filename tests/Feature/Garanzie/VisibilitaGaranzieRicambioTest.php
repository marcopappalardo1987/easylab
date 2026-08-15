<?php

use App\Enums\VisibilitaGaranzieRicambio;
use App\Livewire\Anagrafica\Albero;
use App\Models\Garanzia;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * L'impostazione per-Ente della visibilità garanzie ricambio (🔗 ADR-029).
 *
 * Area rossa, e per una ragione che vale scrivere: qui si **allarga** un
 * accesso, non lo si restringe. Il divieto che c'era prima non era mai stato
 * deciso — Fase 2 lo dava per scontato, ADR-004 lo ratificò come «già
 * previsto» — ma i test che lo difendevano erano veri, e riscriverli senza
 * mettere al loro posto una matrice completa significherebbe togliere una rete
 * senza tenderne un'altra.
 *
 * Le due domande sono su piani diversi e vanno provate separate: `nascosta`
 * toglie le RIGHE (global scope), `lettura` toglie la SCRITTURA (Policy).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);

    $this->utente = function (string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create([
            'tenant_id' => ($ente ?? $this->ente)->id,
            'two_factor_confirmed_at' => now(),
        ]);
        $u->assignRole($ruolo);

        return $u;
    };
});

// --- Il default, che È la decisione ---

it('creates every Ente with the garanzie ricambio visible and editable', function () {
    // Non è un dettaglio di schema: ADR-029 decide che chi paga l'abbonamento
    // possiede i propri dati, e l'eccezione la deve giustificare chi la impone.
    $nuovo = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente nuovo']);

    expect($nuovo->visibilita_garanzie_ricambio)->toBe(VisibilitaGaranzieRicambio::Modifica)
        ->and($nuovo->fresh()->visibilita_garanzie_ricambio)->toBe(VisibilitaGaranzieRicambio::Modifica);
});

// --- La matrice: tre stati × due domande ---

it('answers both questions according to the Ente setting', function (
    VisibilitaGaranzieRicambio $stato,
    bool $vede,
    bool $gestisce,
) {
    $tenant = ($this->utente)('Tenant');
    $this->ente->fissaVisibilitaGaranzieRicambio($stato);
    $this->actingAs($tenant->fresh());

    expect(Gate::allows('view', Garanzia::class))->toBe($vede)
        ->and(Gate::allows('manage', Garanzia::class))->toBe($gestisce);
})->with([
    'nascosta → niente' => [VisibilitaGaranzieRicambio::Nascosta, false, false],
    'lettura → vede, non scrive' => [VisibilitaGaranzieRicambio::Lettura, true, false],
    'modifica → tutto' => [VisibilitaGaranzieRicambio::Modifica, true, true],
]);

it('never lets the setting grant what the role does not have', function () {
    // L'impostazione RESTRINGE e non allarga mai: un Ente in `modifica` non
    // regala nulla a un ruolo senza permesso. Senza questo caso, la gerarchia
    // «RBAC decide il default, l'Ente l'eccezione» sarebbe solo un'affermazione.
    $spaesato = User::factory()->create(['tenant_id' => $this->ente->id]); // nessun ruolo
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Modifica);
    $this->actingAs($spaesato);

    expect(Gate::allows('view', Garanzia::class))->toBeFalse()
        ->and(Gate::allows('manage', Garanzia::class))->toBeFalse();
});

it('binds the setting to the Ente, not to the platform', function () {
    // Due Enti, due impostazioni, due Tenant: se il vincolo fosse globale — o
    // se la Policy leggesse l'Ente sbagliato — questo caso non si distinguerebbe.
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $altroEnte->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);

    $tenantA = ($this->utente)('Tenant');
    $tenantB = ($this->utente)('Tenant', $altroEnte);

    expect(Gate::forUser($tenantA)->allows('view', Garanzia::class))->toBeTrue()
        ->and(Gate::forUser($tenantB)->allows('view', Garanzia::class))->toBeFalse();
});

// --- Chi può cambiarla ---

it('shows the control to the Superadmin only', function () {
    $superadmin = ($this->utente)('Superadmin');
    $admin = ($this->utente)('Admin');

    Livewire::actingAs($superadmin)->test(Albero::class)
        ->call('edit', $this->ente->id)
        ->assertSee('Garanzie dei ricambi per il Tenant');

    // L'Admin governa lo stesso form (nome, note, soglia) con
    // `unita_organizzativa.update`: è esattamente il caso in cui il permesso
    // giusto per la schermata è sbagliato per il campo.
    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $this->ente->id)
        ->assertDontSee('Garanzie dei ricambi per il Tenant');
});

it('ignores the field when an Admin forges it from the browser', function () {
    // Le property Livewire arrivano dal client: nascondere il campo nella vista
    // non basta, e questo test è la ragione per cui la colonna sta fuori da
    // `$fillable` e si scrive solo dal metodo di dominio.
    $admin = ($this->utente)('Admin');

    Livewire::actingAs($admin)->test(Albero::class)
        ->call('edit', $this->ente->id)
        ->set('visibilitaGaranzieRicambio', VisibilitaGaranzieRicambio::Nascosta->value)
        ->set('nome', 'Ente A rinominato')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->ente->fresh())
        ->visibilita_garanzie_ricambio->toBe(VisibilitaGaranzieRicambio::Modifica)
        ->nome->toBe('Ente A rinominato'); // il resto del form funziona come prima
});

it('lets the Superadmin change it, and the change takes effect at once', function () {
    $superadmin = ($this->utente)('Superadmin');
    $tenant = ($this->utente)('Tenant');

    Livewire::actingAs($superadmin)->test(Albero::class)
        ->call('edit', $this->ente->id)
        ->set('visibilitaGaranzieRicambio', VisibilitaGaranzieRicambio::Lettura->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->ente->fresh()->visibilita_garanzie_ricambio)->toBe(VisibilitaGaranzieRicambio::Lettura)
        ->and(Gate::forUser($tenant->fresh())->allows('view', Garanzia::class))->toBeTrue()
        ->and(Gate::forUser($tenant->fresh())->allows('manage', Garanzia::class))->toBeFalse();
});

it('records who changed the setting, and from what to what', function () {
    // ADR-029 ha scartato la concessione RBAC caso-per-caso con l'argomento che
    // «non lascia traccia di CHI l'ha decisa». Senza questa riga l'impostazione
    // scelta al suo posto non ne lascerebbe neanche lei, e l'argomento che
    // regge la decisione non sarebbe soddisfatto dalla decisione stessa
    // (🔗 ADR-027, 15 Ago 2026).
    $superadmin = ($this->utente)('Superadmin');
    $this->actingAs($superadmin);

    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);

    $riga = Activity::where('log_name', AuditLog::NAME)
        ->where('subject_type', UnitaOrganizzativa::class)->first();

    expect($riga)->not->toBeNull()
        ->and($riga->description)->toBe('Visibilità garanzie ricambio modificata')
        ->and($riga->causer_id)->toBe($superadmin->id)
        ->and($riga->properties['da'])->toBe('modifica')
        ->and($riga->properties['a'])->toBe('nascosta');
});

it('refuses a value that is not one of the three states', function () {
    $superadmin = ($this->utente)('Superadmin');

    Livewire::actingAs($superadmin)->test(Albero::class)
        ->call('edit', $this->ente->id)
        ->set('visibilitaGaranzieRicambio', 'quasi_visibile')
        ->call('save')
        ->assertHasErrors('visibilitaGaranzieRicambio');

    expect($this->ente->fresh()->visibilita_garanzie_ricambio)->toBe(VisibilitaGaranzieRicambio::Modifica);
});
