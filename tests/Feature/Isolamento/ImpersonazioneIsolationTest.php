<?php

use App\Livewire\Tenancy\SwitcherEnte;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Tests\Feature\Isolamento\Support\MondoDueEnti;

/**
 * Impersonazione e confine fra tenant (ADR-018): l'unico passaggio
 * cross-tenant ammesso per il Superadmin. Si verifica che finisca davvero —
 * all'uscita non resta nessun accesso all'Ente impersonato, nemmeno da una
 * scheda rimasta aperta — e chi può esserne oggetto: il Developer mai, il
 * Superadmin sì.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();

    $this->piattaforma = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente di piattaforma']);
    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->forceFill(['tenant_id' => $this->piattaforma->id])->save();
    $this->superadmin->assignRole('Superadmin');

    $this->adminB = $this->mondo->riga('B', 'admin');
    $this->strumentoB = $this->mondo->riga('B', 'strumento');
});

it('reaches the impersonated Ente only while impersonating', function () {
    // Positivo: senza, il negativo dopo l'uscita non dimostrerebbe niente.
    $this->actingAs($this->superadmin)->get(route('strumenti.show', $this->strumentoB))->assertNotFound();

    $this->get(route('impersonate', $this->adminB));
    expect(app('impersonate')->isImpersonating())->toBeTrue();
    $this->get(route('strumenti.show', $this->strumentoB))->assertOk()->assertSee('Strumento-SEGRETO-B');
});

it('leaves no access to the impersonated Ente after leaving', function () {
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->adminB));
    $this->get(route('impersonate.leave'));

    expect(app('impersonate')->isImpersonating())->toBeFalse()
        ->and(auth()->id())->toBe($this->superadmin->id)
        ->and(CurrentTenant::id())->toBe($this->piattaforma->id)
        ->and(Strumento::pluck('id')->all())->toBe([]);

    $this->get(route('strumenti.show', $this->strumentoB))->assertNotFound();
    $html = $this->get('/strumenti')->assertOk()->getContent();
    foreach (MondoDueEnti::marcatori('B') as $marcatore) {
        expect($html)->not->toContain($marcatore);
    }
});

it('refuses a Livewire update from a scheda left open after leaving the impersonation', function () {
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->adminB));
    $html = $this->get(route('strumenti.show', $this->strumentoB))->assertOk()->getContent();

    $snapshot = collect(preg_match_all('/wire:snapshot="([^"]*)"/', $html, $m) ? $m[1] : [])
        ->map(fn ($s) => html_entity_decode($s, ENT_QUOTES))
        ->first(fn ($s) => str_contains($s, 'strumento') && str_contains(json_decode($s, true)['memo']['name'] ?? '', 'scheda'));
    expect($snapshot)->not->toBeNull();

    $this->get(route('impersonate.leave'));

    $risposta = $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ]);

    expect($risposta->status())->toBeIn([403, 404]);
    expect($risposta->getContent())->not->toContain('Strumento-SEGRETO-B');
});

it('never lets anybody impersonate the Developer', function () {
    $developer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $developer->forceFill(['tenant_id' => $this->piattaforma->id])->save();
    $developer->assignRole('Developer');

    $this->actingAs($this->superadmin)->get(route('impersonate', $developer));

    expect(app('impersonate')->isImpersonating())->toBeFalse()
        ->and(auth()->id())->toBe($this->superadmin->id);
});

it('lets the Developer impersonate the Superadmin, and only within its tenant', function () {
    $developer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $developer->forceFill(['tenant_id' => $this->piattaforma->id])->save();
    $developer->assignRole('Developer');

    $this->actingAs($developer)->get(route('impersonate', $this->superadmin));

    expect(app('impersonate')->isImpersonating())->toBeTrue()
        ->and(auth()->id())->toBe($this->superadmin->id)
        ->and(CurrentTenant::id())->toBe($this->piattaforma->id);
    $this->get(route('strumenti.show', $this->strumentoB))->assertNotFound();
});

it('never lets the impersonated Admin chain into a second impersonation', function () {
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->adminB));

    // L'Admin impersonato non ha `utenti.impersonate`: la catena si ferma qui.
    $this->get(route('impersonate', $this->mondo->riga('A', 'admin')));

    expect(auth()->id())->toBe($this->adminB->id);
    $this->get(route('strumenti.show', $this->mondo->riga('A', 'strumento')))->assertNotFound();
});

it('does not reopen a sede of an account the impersonated person no longer belongs to', function () {
    // T2A-2: la sede scelta resta in sessione dopo l'uscita. Se nel frattempo
    // la persona perde l'account di quella sede, la prossima impersonazione
    // non deve riaprirla: CurrentTenant la rivalida in lettura.
    $enteA = $this->mondo->riga('A', 'ente');
    $this->adminB->accounts()->syncWithoutDetaching([$this->mondo->riga('A', 'account')->id]);

    $this->actingAs($this->superadmin)->get(route('impersonate', $this->adminB));
    Livewire::test(SwitcherEnte::class)->call('passa', $enteA->id);
    expect(CurrentTenant::id())->toBe($enteA->id);
    $this->get(route('impersonate.leave'));

    $this->adminB->accounts()->detach($this->mondo->riga('A', 'account')->id);

    $this->get(route('impersonate', $this->adminB));
    expect(app('impersonate')->isImpersonating())->toBeTrue()
        ->and(CurrentTenant::id())->toBe($this->mondo->riga('B', 'ente')->id);
    $this->get(route('strumenti.show', $this->mondo->riga('A', 'strumento')))->assertNotFound();
});

it('drops the chosen sede as soon as the account of that sede goes into lockout', function () {
    $enteA = $this->mondo->riga('A', 'ente');
    $this->adminB->accounts()->syncWithoutDetaching([$this->mondo->riga('A', 'account')->id]);

    $this->actingAs($this->superadmin)->get(route('impersonate', $this->adminB));
    Livewire::test(SwitcherEnte::class)->call('passa', $enteA->id);
    expect(CurrentTenant::id())->toBe($enteA->id);

    $this->mondo->riga('A', 'account')->blocca('Insoluto');
    $this->get('/strumenti');

    expect(CurrentTenant::id())->toBe($this->mondo->riga('B', 'ente')->id);
});

it('does not land a later impersonation in a sede the user can no longer reach, once out of the contract', function () {
    // T2B-2: Mario (Admin di B, membro anche del contratto di A) sceglie A
    // mentre è impersonato; poi esce da quel contratto. La chiave in sessione
    // è ancora lì, ma CurrentTenant la rivalida.
    $enteA = $this->mondo->riga('A', 'ente');
    $this->mondo->riga('A', 'account')->aggiungiMembro($this->adminB);

    $this->actingAs($this->superadmin)->get(route('impersonate', $this->adminB));
    Livewire::test(SwitcherEnte::class)->call('passa', $enteA->id);
    $this->get(route('impersonate.leave'));

    $this->adminB->accounts()->detach($this->mondo->riga('A', 'account')->id);

    $this->get(route('impersonate', $this->adminB->fresh()));
    expect(auth()->id())->toBe($this->adminB->id)
        ->and(CurrentTenant::id())->toBe($this->mondo->riga('B', 'ente')->id);
    expect($this->get('/strumenti')->getContent())->not->toContain('Strumento-SEGRETO-A');
});

it('forgets the sede chosen while impersonating once the impersonation ends', function () {
    // ⚠️ ROSSO finché non si applica R-T2-2 in board (forget della chiave su
    // Take/LeaveImpersonation in AuditLogSubscriber, file non di T2). La
    // rivalidazione in CurrentTenant chiude già la fuga; questo è l'ordine.
    $this->mondo->riga('A', 'account')->aggiungiMembro($this->adminB);

    $this->actingAs($this->superadmin)->get(route('impersonate', $this->adminB));
    Livewire::test(SwitcherEnte::class)->call('passa', $this->mondo->riga('A', 'ente')->id);
    $this->get(route('impersonate.leave'));

    expect(session()->has(CurrentTenant::SEDE_IMPERSONATA))->toBeFalse();
});
