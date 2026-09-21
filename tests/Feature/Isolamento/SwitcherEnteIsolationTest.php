<?php

use App\Livewire\Dashboard\Home;
use App\Livewire\Tenancy\SwitcherEnte;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Isolamento\Support\MondoDueEnti;

/**
 * Multi-Ente (ADR-032): lo switcher cambia l'Ente attivo di un membro
 * dell'account. Dopo il passaggio non deve restare niente dell'Ente di
 * partenza — non nella sessione, non nella cache, non in un componente
 * rimasto aperto — e l'Ente B, di un altro account, resta irraggiungibile.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();

    // Seconda sede dello STESSO account di A.
    $this->sedeA2 = UnitaOrganizzativa::factory()->ente()->perAccount($this->mondo->riga('A', 'account'))
        ->create(['nome' => 'Sede-SECONDA-A2']);
    $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($this->sedeA2->refresh())->create();
    $this->strumentoA2 = Strumento::factory()->forNode($reparto)->create(['nome' => 'Strumento-SECONDO-A2']);

    $this->admin = $this->mondo->riga('A', 'admin');
});

/** @return list<string> marcatori della sede di partenza che non devono seguire il membro */
function marcatoriSedeDiPartenza(): array
{
    return ['Strumento-SEGRETO-A', 'MAT-SEGRETO-A', 'Intervento-SEGRETO-A', 'Documento-SEGRETO-A', 'Fornitore-SEGRETO-A', 'Ricambio-SEGRETO-A'];
}

function passaAllaSede(int $enteId): void
{
    Livewire::test(SwitcherEnte::class)->call('passa', $enteId);
}

it('shows only the new sede after the switch, on every tenant page', function () {
    $this->actingAs($this->admin);
    foreach (['/strumenti', '/scadenzario', '/documenti', '/fornitori', '/dashboard'] as $url) {
        $this->get($url)->assertOk();
    }

    passaAllaSede($this->sedeA2->id);
    expect(CurrentTenant::id())->toBe($this->sedeA2->id);

    $this->get('/strumenti')->assertOk()->assertSee('Strumento-SECONDO-A2');
    foreach (['/strumenti', '/scadenzario', '/documenti', '/fornitori', '/dashboard', '/ricambi?search=SEGRETO'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach ([...marcatoriSedeDiPartenza(), ...MondoDueEnti::marcatori('B')] as $marcatore) {
            expect($html)->not->toContain($marcatore);
        }
    }
    $this->get(route('strumenti.show', $this->mondo->riga('A', 'strumento')))->assertNotFound();
});

it('recounts the dashboard on the new sede instead of carrying the old totals', function () {
    $this->actingAs($this->admin);
    expect(Livewire::test(Home::class)->viewData('parco')->totale())->toBe(2);

    passaAllaSede($this->sedeA2->id);

    expect(Livewire::test(Home::class)->viewData('parco')->totale())->toBe(1);
});

it('carries no data of the previous sede in the session', function () {
    $this->actingAs($this->admin);
    $this->get('/strumenti?search=SEGRETO')->assertOk();
    $this->get(route('strumenti.show', $this->mondo->riga('A', 'strumento')))->assertOk();

    passaAllaSede($this->sedeA2->id);

    $sessione = serialize(session()->all());
    foreach (marcatoriSedeDiPartenza() as $marcatore) {
        expect($sessione)->not->toContain($marcatore);
    }
});

it('never keeps tenant data in the shared cache, so a switch cannot inherit it', function () {
    // Il guasto temuto: una chiave di cache non legata all'Ente, scritta dalla
    // sede di partenza e letta da quella d'arrivo (o da un altro cliente).
    $this->actingAs($this->admin);
    foreach (['/dashboard', '/strumenti', '/scadenzario', '/documenti', '/fornitori', '/ricambi?search=SEGRETO', '/anagrafica'] as $url) {
        $this->get($url)->assertOk();
    }
    passaAllaSede($this->sedeA2->id);
    $this->get('/dashboard')->assertOk();

    $store = Cache::store()->getStore();
    expect($store)->toBeInstanceOf(ArrayStore::class);
    $contenuto = serialize((fn () => $this->storage)->call($store));

    foreach ([...marcatoriSedeDiPartenza(), 'Strumento-SECONDO-A2'] as $marcatore) {
        expect($contenuto)->not->toContain($marcatore);
    }
});

it('refuses a Livewire update from a scheda of the previous sede left open', function () {
    $this->actingAs($this->admin);
    $html = $this->get(route('strumenti.show', $this->mondo->riga('A', 'strumento')))->assertOk()->getContent();
    $snapshot = collect(preg_match_all('/wire:snapshot="([^"]*)"/', $html, $m) ? $m[1] : [])
        ->map(fn ($s) => html_entity_decode($s, ENT_QUOTES))
        ->first(fn ($s) => str_contains(json_decode($s, true)['memo']['name'] ?? '', 'scheda'));
    expect($snapshot)->not->toBeNull();

    passaAllaSede($this->sedeA2->id);
    $this->actingAs($this->admin->fresh());

    $risposta = $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ]);

    expect($risposta->status())->toBeIn([403, 404]);
    expect($risposta->getContent())->not->toContain('Strumento-SEGRETO-A');
});

it('refuses to switch into the Ente of another account', function () {
    $this->actingAs($this->admin);

    passaAllaSede($this->mondo->riga('B', 'ente')->id);

    expect(CurrentTenant::id())->toBe($this->mondo->riga('A', 'ente')->id)
        ->and($this->admin->fresh()->tenant_id)->toBe($this->mondo->riga('A', 'ente')->id);
});

it('refuses the lockout escape toward the Ente of another account', function () {
    $this->actingAs($this->admin)
        ->post(route('bloccato.passa', ['ente' => $this->mondo->riga('B', 'ente')->id]))
        ->assertRedirect(route('bloccato'));

    expect($this->admin->fresh()->tenant_id)->toBe($this->mondo->riga('A', 'ente')->id);
});
