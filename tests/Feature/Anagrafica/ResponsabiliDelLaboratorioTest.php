<?php

use App\Livewire\Anagrafica\Albero;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * 🔴 Il riquadro «Responsabili» nella pagina di un laboratorio (🔗 ADR-057):
 * chi lo segue, e chi può cambiare i ruoli ne aggiunge e ne toglie.
 *
 * È la stessa scrittura della finestra «Laboratori» di «Persone», vista dal
 * laboratorio — e la sola strada con cui un **Superadmin** assegna i laboratori
 * di un cliente che gestisce senza impersonarlo. Area rossa: qui il confine di
 * tenancy del **nodo** lo danno i global scope, quello della **persona** si
 * scrive a mano (`User` non ha uno scope).
 *
 * Mondo: l'Ospedale (Chimica con dentro Cromatografia, e Fisica); la Clinica,
 * un altro cliente che si gestisce da sé; il Poliambulatorio, un cliente la cui
 * manutenzione è gestita da EasyLab.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ospedale = UnitaOrganizzativa::factory()->ente()->perAccount(Account::factory()->create())->create(['nome' => 'Ospedale']);
    $this->chimica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ospedale)->create(['nome' => 'Chimica']);
    $this->cromatografia = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->chimica)->create(['nome' => 'Cromatografia']);
    $this->fisica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ospedale)->create(['nome' => 'Fisica']);

    $this->clinica = UnitaOrganizzativa::factory()->ente()->perAccount(Account::factory()->create())->create(['nome' => 'Clinica']);
    $this->radiologia = UnitaOrganizzativa::factory()->dipartimento()->under($this->clinica)->create(['nome' => 'Radiologia']);

    $gestito = Account::factory()->create(['ragione_sociale' => 'Poliambulatorio']);
    $this->poliambulatorio = UnitaOrganizzativa::factory()->ente()->perAccount($gestito)->create(['nome' => 'Poliambulatorio']);
    $this->ematologia = UnitaOrganizzativa::factory()->dipartimento()->under($this->poliambulatorio)->create(['nome' => 'Ematologia']);
    $gestito->affidaManutenzione();

    $this->easylab = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'EasyLab']);

    $this->persona = function (string $nome, string $ruolo, UnitaOrganizzativa $ente): User {
        $u = User::factory()->create(['name' => $nome, 'tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u->fresh();
    };

    $this->admin = ($this->persona)('Aldo Admin', 'Admin', $this->ospedale);
    $this->rita = ($this->persona)('Rita Responsabile', 'Responsabile Reparto', $this->ospedale);
    $this->franco = ($this->persona)('Franco Responsabile', 'Responsabile Reparto', $this->ospedale);
    $this->bruno = ($this->persona)('Bruno Clinica', 'Responsabile Reparto', $this->clinica);
    $this->piera = ($this->persona)('Piera Poliambulatorio', 'Responsabile Reparto', $this->poliambulatorio);
    $this->superadmin = ($this->persona)('Sara Superadmin', 'Superadmin', $this->easylab);

    /** La pagina di un laboratorio, vista da qualcuno. */
    $this->laboratorio = fn (UnitaOrganizzativa $nodo, ?User $chi = null) => Livewire::actingAs($chi ?? $this->admin)
        ->test(Albero::class)
        ->call('open', $nodo->id);

    $this->righe = fn (User $chi): array => DB::table('responsabile_unita')
        ->where('user_id', $chi->id)->orderBy('unita_organizzativa_id')->pluck('unita_organizzativa_id')->map(fn ($id) => (int) $id)->all();
});

// ─── Che cosa si vede ────────────────────────────────────────────────────────

it('shows on a laboratorio who follows it, and offers the responsabili of the ente who do not yet', function () {
    $this->rita->unitaResponsabili()->sync([$this->chimica->id]);

    $pagina = ($this->laboratorio)($this->chimica)
        ->assertSeeHtml('data-responsabili')
        ->assertSee('Responsabili (1)')
        ->assertSee('Rita Responsabile')
        ->assertSeeHtml('wire:click="togliResponsabile('.$this->rita->id.')"');

    // Nella tendina: Franco, che è dell'Ospedale e non lo segue ancora.
    preg_match_all('/<option value="(\d+)">/', $pagina->html(), $offerti);

    expect(array_map('intval', $offerti[1]))->toBe([$this->franco->id])
        // 🔴 Né Bruno né Piera: sono Responsabili, ma di un altro cliente.
        ->and($pagina->html())->not->toContain('Bruno Clinica')
        ->not->toContain('Piera Poliambulatorio');
});

it('says from where a responsabile gets a sub, and does not offer to take it away from there', function () {
    $this->rita->unitaResponsabili()->sync([$this->chimica->id]);

    ($this->laboratorio)($this->cromatografia)
        ->assertSee('Responsabili (1)')
        ->assertSee('Rita Responsabile')
        ->assertSee('tramite Chimica')
        // Si toglie da «Chimica», non da qui: togliere «Cromatografia» non
        // toglierebbe niente, perché quella riga non esiste.
        ->assertDontSeeHtml('wire:click="togliResponsabile('.$this->rita->id.')"');
});

it('says so when nobody follows the laboratorio, and when the sede has no responsabile at all', function () {
    ($this->laboratorio)($this->fisica)
        ->assertSee('Responsabili (0)')
        ->assertSee('Nessun responsabile segue questo laboratorio.')
        ->assertDontSeeHtml('data-nessun-responsabile-in-sede');

    // Tutti i Responsabili della sede lo seguono già: non c'è nessuno da
    // aggiungere, ma non è vero che la sede non ne ha.
    $this->rita->unitaResponsabili()->sync([$this->fisica->id]);
    $this->franco->unitaResponsabili()->sync([$this->fisica->id]);

    ($this->laboratorio)($this->fisica)
        ->assertSee('Responsabili (2)')
        ->assertDontSee('Assegna questo laboratorio a')
        ->assertDontSeeHtml('data-nessun-responsabile-in-sede');

    $this->rita->syncRoles(['Tenant']);
    $this->franco->delete();

    ($this->laboratorio)($this->fisica)
        ->assertSeeHtml('data-nessun-responsabile-in-sede')
        ->assertSee('In questa sede nessuno ha il ruolo Responsabile Reparto')
        ->assertDontSee('Assegna questo laboratorio a');
});

it('shows no responsabili block on the sede, nor at the root', function () {
    // La sede non è un laboratorio: non si assegna.
    expect(Livewire::actingAs($this->admin)->test(Albero::class)->html())->not->toContain('data-responsabili');

    expect(($this->laboratorio)($this->ospedale)->html())->not->toContain('data-responsabili');
});

// ─── Assegnare e togliere ────────────────────────────────────────────────────

it('lets an admin give the laboratorio to a responsabile of the ente, and take it back', function () {
    ($this->laboratorio)($this->fisica)
        ->set('nuovoResponsabile', (string) $this->rita->id)
        ->call('assegnaResponsabile')
        ->assertHasNoErrors()
        ->assertSet('nuovoResponsabile', '')
        ->assertSet('notice', 'Rita Responsabile ora segue «Fisica», con ciò che contiene.')
        ->assertSee('Responsabili (1)');

    expect(($this->righe)($this->rita))->toBe([$this->fisica->id]);

    $riga = Activity::query()->where('description', 'Laboratori assegnati')->sole();

    expect($riga->causer_id)->toBe($this->admin->id)
        ->and($riga->subject_id)->toBe($this->rita->id)
        ->and($riga->properties['a'])->toBe(['Fisica']);

    ($this->laboratorio)($this->fisica)
        ->call('togliResponsabile', $this->rita->id)
        ->assertSet('notice', 'Rita Responsabile non segue più «Fisica».')
        ->assertSee('Responsabili (0)');

    expect(($this->righe)($this->rita))->toBe([]);
});

it('adds the laboratorio to those the person already follows, without touching the others', function () {
    $this->rita->unitaResponsabili()->sync([$this->fisica->id]);

    ($this->laboratorio)($this->cromatografia)
        ->set('nuovoResponsabile', (string) $this->rita->id)
        ->call('assegnaResponsabile')
        ->assertHasNoErrors();

    expect(($this->righe)($this->rita))->toBe([$this->cromatografia->id, $this->fisica->id]);

    // E toglierne uno lascia l'altro.
    ($this->laboratorio)($this->fisica)->call('togliResponsabile', $this->rita->id);

    expect(($this->righe)($this->rita))->toBe([$this->cromatografia->id]);
});

it('asks to choose somebody before assigning', function () {
    ($this->laboratorio)($this->fisica)
        ->call('assegnaResponsabile')
        ->assertHasErrors(['nuovoResponsabile' => 'required']);

    ($this->laboratorio)($this->fisica)
        ->set('nuovoResponsabile', 'rita')
        ->call('assegnaResponsabile')
        ->assertHasErrors(['nuovoResponsabile' => 'integer']);

    expect(DB::table('responsabile_unita')->count())->toBe(0);
});

// ─── Il Superadmin, sui clienti che gestisce ─────────────────────────────────

it('lets a superadmin assign a laboratorio of a client easylab manages, without impersonating', function () {
    // 🔴 La ragione per cui il riquadro sta anche qui: «Persone» è dell'Ente in
    // cui ci si trova, e per il Superadmin quello è EasyLab.
    ($this->laboratorio)($this->ematologia, $this->superadmin)
        ->assertSeeHtml('data-responsabili')
        ->set('nuovoResponsabile', (string) $this->piera->id)
        ->call('assegnaResponsabile')
        ->assertHasNoErrors()
        ->assertSee('Piera Poliambulatorio');

    expect(($this->righe)($this->piera))->toBe([$this->ematologia->id]);

    ($this->laboratorio)($this->ematologia, $this->superadmin)->call('togliResponsabile', $this->piera->id);

    expect(($this->righe)($this->piera))->toBe([]);
});

it('does not let a superadmin reach the laboratorio of a client that manages itself', function () {
    // La Clinica non è gestita: il Superadmin non ne vede l'albero, e l'azione
    // non trova il nodo. Per lei resta l'impersonazione.
    $pagina = Livewire::actingAs($this->superadmin)->test(Albero::class)->set('currentId', $this->radiologia->id);

    expect(fn () => $pagina->set('nuovoResponsabile', (string) $this->bruno->id)->call('assegnaResponsabile'))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => Livewire::actingAs($this->superadmin)->test(Albero::class)->set('currentId', $this->radiologia->id)->call('togliResponsabile', $this->bruno->id))
        ->toThrow(ModelNotFoundException::class);

    expect(DB::table('responsabile_unita')->count())->toBe(0);
});

// ─── Il permesso, in ogni azione ─────────────────────────────────────────────

it('hides the block from who cannot see the people', function (string $ruolo) {
    $chi = ($this->persona)('Persona', $ruolo, $this->ospedale);
    $chi->unitaResponsabili()->sync([$this->fisica->id]);
    $this->rita->unitaResponsabili()->sync([$this->fisica->id]);

    // Vede il laboratorio, non chi lo segue: i nomi dei colleghi sono «Persone».
    expect(($this->laboratorio)($this->fisica, $chi->fresh())->assertSee('Fisica')->html())
        ->not->toContain('data-responsabili')
        ->not->toContain('Rita Responsabile');
})->with(['Tenant', 'Responsabile Reparto']);

it('refuses both actions to who cannot change the people, whatever they can see', function (string $ruolo) {
    $chi = ($this->persona)('Persona', $ruolo, $this->ospedale);
    $chi->unitaResponsabili()->sync([$this->fisica->id]);
    $this->franco->unitaResponsabili()->sync([$this->fisica->id]);

    // 🔴 Chiamate senza passare dal riquadro: il permesso si chiede per primo,
    // prima ancora di cercare il laboratorio.
    Livewire::actingAs($chi->fresh())->test(Albero::class)
        ->set('currentId', $this->fisica->id)
        ->set('nuovoResponsabile', (string) $this->rita->id)
        ->call('assegnaResponsabile')
        ->assertForbidden();

    Livewire::actingAs($chi->fresh())->test(Albero::class)
        ->set('currentId', $this->fisica->id)
        ->call('togliResponsabile', $this->franco->id)
        ->assertForbidden();

    expect(($this->righe)($this->rita))->toBe([])
        ->and(($this->righe)($this->franco))->toBe([$this->fisica->id]);
})->with(['Tenant', 'Responsabile Reparto', 'Tecnico']);

it('shows the block read-only to who can see the people but not change them', function () {
    Role::create(['name' => 'Solo lettura', 'guard_name' => 'web'])
        ->givePermissionTo(['unita_organizzativa.view', 'utenti.view']);
    $lettore = ($this->persona)('Lea Lettrice', 'Solo lettura', $this->ospedale);
    $this->rita->unitaResponsabili()->sync([$this->fisica->id]);

    $pagina = ($this->laboratorio)($this->fisica, $lettore)
        ->assertSeeHtml('data-responsabili')
        ->assertSee('Rita Responsabile')
        ->assertDontSeeHtml('togliResponsabile')
        ->assertDontSee('Assegna questo laboratorio a');

    $pagina->call('togliResponsabile', $this->rita->id)->assertForbidden();

    ($this->laboratorio)($this->fisica, $lettore)
        ->set('nuovoResponsabile', (string) $this->franco->id)
        ->call('assegnaResponsabile')
        ->assertForbidden();

    expect(($this->righe)($this->rita))->toBe([$this->fisica->id])
        ->and(($this->righe)($this->franco))->toBe([]);
});

// ─── Il confine dell'Ente ────────────────────────────────────────────────────

it('finds nobody when asked for a responsabile of another ente, or for somebody who is not one', function () {
    $tenant = ($this->persona)('Tina Tenant', 'Tenant', $this->ospedale);

    foreach ([$this->bruno->id, $this->piera->id, $tenant->id, $this->admin->id, 999999] as $id) {
        expect(fn () => ($this->laboratorio)($this->fisica)->set('nuovoResponsabile', (string) $id)->call('assegnaResponsabile'))
            ->toThrow(ModelNotFoundException::class)
            ->and(fn () => ($this->laboratorio)($this->fisica)->call('togliResponsabile', $id))
            ->toThrow(ModelNotFoundException::class);
    }

    expect(DB::table('responsabile_unita')->count())->toBe(0);
});

it('does not find a responsabile in the bin', function () {
    $this->rita->delete();

    expect(fn () => ($this->laboratorio)($this->fisica)->set('nuovoResponsabile', (string) $this->rita->id)->call('assegnaResponsabile'))
        ->toThrow(ModelNotFoundException::class);
});

it('does not let an admin act on a laboratorio of another ente', function () {
    // L'Admin dell'Ospedale punta la pagina su un nodo della Clinica: i global
    // scope non glielo fanno trovare.
    expect(fn () => Livewire::actingAs($this->admin)->test(Albero::class)
        ->set('currentId', $this->radiologia->id)
        ->set('nuovoResponsabile', (string) $this->bruno->id)
        ->call('assegnaResponsabile'))
        ->toThrow(ModelNotFoundException::class)
        // Nemmeno con una persona del PROPRIO Ente: il nodo resta di un altro.
        ->and(fn () => Livewire::actingAs($this->admin)->test(Albero::class)
            ->set('currentId', $this->radiologia->id)
            ->set('nuovoResponsabile', (string) $this->rita->id)
            ->call('assegnaResponsabile'))
        ->toThrow(ModelNotFoundException::class);

    expect(DB::table('responsabile_unita')->count())->toBe(0);
});

it('refuses to assign the sede, also when the action is called without the block', function () {
    ($this->laboratorio)($this->ospedale)
        ->set('nuovoResponsabile', (string) $this->rita->id)
        ->call('assegnaResponsabile')
        ->assertStatus(422);

    ($this->laboratorio)($this->ospedale)->call('togliResponsabile', $this->rita->id)->assertStatus(422);

    expect(($this->righe)($this->rita))->toBe([]);
});

// ─── Il Responsabile senza laboratori ────────────────────────────────────────

it('tells a responsabile without laboratori why the page is empty, and whom to ask', function () {
    Livewire::actingAs($this->rita)->test(Albero::class)
        ->assertSeeHtml('data-senza-laboratori')
        ->assertSee('Non ti è stato ancora assegnato nessun laboratorio')
        ->assertDontSee('Nessun ente qui.');

    $this->rita->unitaResponsabili()->sync([$this->fisica->id]);

    Livewire::actingAs($this->rita->fresh())->test(Albero::class)
        ->assertDontSeeHtml('data-senza-laboratori')
        ->assertSee('Fisica');
});

it('does not say that to an admin whose ente is simply empty', function () {
    $vuoto = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Sede nuova']);
    $admin = ($this->persona)('Nora Nuova', 'Admin', $vuoto);

    Livewire::actingAs($admin)->test(Albero::class)
        ->assertDontSeeHtml('data-senza-laboratori')
        ->assertSee('Nessun laboratorio qui.');
});

it('does not say that to who sees nothing for another reason', function () {
    // Un tecnico di EasyLab senza clienti in portafoglio arriva alla stessa
    // pagina vuota, alla radice: non è un Responsabile, e la frase sui
    // laboratori da farsi assegnare da «Persone» non parla a lui.
    $tecnico = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');

    Livewire::actingAs($tecnico->fresh())->test(Albero::class)
        ->assertSet('currentId', null)
        ->assertDontSeeHtml('data-senza-laboratori')
        ->assertSee('Nessun ente qui.');
});

// ─── Quanto costa ────────────────────────────────────────────────────────────

it('reads nothing about responsabili at the root, on the sede, or for who cannot see the people', function () {
    $conta = function (User $chi, ?UnitaOrganizzativa $nodo): int {
        $pagina = Livewire::actingAs($chi)->test(Albero::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $nodo === null ? $pagina->call('goTo', null) : $pagina->call('open', $nodo->id);

        return collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'responsabile_unita') && str_contains($q['query'], 'unita_organizzativa_id" in'))->count();
    };

    $tenant = ($this->persona)('Tina Tenant', 'Tenant', $this->ospedale);

    expect($conta($this->admin, $this->ospedale))->toBe(0)
        ->and($conta($tenant, $this->fisica))->toBe(0)
        // Il complementare: dentro un laboratorio, per chi può, la lettura c'è — ed è una.
        ->and($conta($this->admin, $this->fisica))->toBe(1);
});
