<?php

use App\Livewire\Tenancy\SwitcherEnte;
use App\Livewire\Utenti\ElencoUtenti;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * T2B-1 (ADR-032). Un Admin retrocesso dalla UI usciva dal ruolo ma non dal
 * contratto: la membership, che `sediRaggiungibili()` e `passaAllEnte()`
 * leggono, gli lasciava le altre sedi dell'account col ruolo nuovo — cosa che
 * un Tenant nato tale non ha. Ora la retrocessione lo toglie dai membri.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Contratto Uno']);
    $this->sedeA = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Nord']);
    $this->sedeA2 = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Sud']);
    $repartoA2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->sedeA2->refresh())->create(['nome' => 'Reparto Sud']);
    $this->strumentoSud = Strumento::factory()->forNode($repartoA2)->create(['nome' => 'Centrifuga-RISERVATA-SUD']);

    $crea = function (string $nome, string $ruolo): User {
        $utente = User::factory()->create(['name' => $nome, 'two_factor_confirmed_at' => now()]);
        $utente->forceFill(['tenant_id' => $this->sedeA->id])->save();
        $utente->assignRole($ruolo);
        if ($ruolo === 'Admin') {
            $this->account->aggiungiMembro($utente);
        }

        return $utente;
    };
    $this->capo = $crea('Giulia Admin', 'Admin');
    $this->exAdmin = $crea('Paolo Ex Admin', 'Admin');
    $this->natoTenant = $crea('Laura Tenant', 'Tenant');
});

function retrocediATenant(User $chi, User $capo): void
{
    Livewire::actingAs($capo)->test(ElencoUtenti::class)
        ->call('apriRuolo', $chi->id)
        ->set('nuovoRuolo', User::TENANT_ROLE)
        ->call('cambiaRuolo')
        ->assertHasNoErrors();
}

it('does not let a Tenant born in sede A reach sede A2 (control)', function () {
    $this->actingAs($this->natoTenant);
    Livewire::test(SwitcherEnte::class)->call('passa', $this->sedeA2->id);

    expect($this->natoTenant->fresh()->tenant_id)->toBe($this->sedeA->id);
});

it('does not let an Admin demoted to Tenant keep reaching the other sedi of the account', function () {
    retrocediATenant($this->exAdmin, $this->capo);
    $ex = $this->exAdmin->fresh();
    expect($ex->getRoleNames()->all())->toBe([User::TENANT_ROLE])
        ->and($ex->sediRaggiungibili()->count())->toBe(0);

    $this->actingAs($ex);
    Livewire::test(SwitcherEnte::class)->call('passa', $this->sedeA2->id);

    expect(CurrentTenant::id())->toBe($this->sedeA->id)
        ->and($ex->fresh()->tenant_id)->toBe($this->sedeA->id);
    expect($this->get('/strumenti')->getContent())->not->toContain('Centrifuga-RISERVATA-SUD');
    $this->get(route('strumenti.show', $this->strumentoSud))->assertNotFound();
});

it('refuses the lockout escape toward another sede to a demoted Admin', function () {
    retrocediATenant($this->exAdmin, $this->capo);

    $this->actingAs($this->exAdmin->fresh())
        ->post(route('bloccato.passa', ['ente' => $this->sedeA2->id]))
        ->assertRedirect(route('bloccato'));

    expect($this->exAdmin->fresh()->tenant_id)->toBe($this->sedeA->id);
});

it('gives the demoted Admin back the other sedi only when promoted again', function () {
    retrocediATenant($this->exAdmin, $this->capo);

    Livewire::actingAs($this->capo)->test(ElencoUtenti::class)
        ->call('apriRuolo', $this->exAdmin->id)
        ->set('nuovoRuolo', 'Admin')
        ->call('cambiaRuolo')
        ->assertHasNoErrors();

    expect($this->exAdmin->fresh()->sediRaggiungibili()->pluck('id')->all())
        ->toEqualCanonicalizing([$this->sedeA->id, $this->sedeA2->id]);
});
