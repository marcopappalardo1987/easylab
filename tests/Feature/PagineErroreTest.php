<?php

use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Le pagine d'errore sono in italiano e con la grafica dell'applicazione
 * (7 Nov 2026: erano quelle di Laravel, in inglese). E non raccontano mai il
 * messaggio tecnico dell'eccezione.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('says in Italian that a page does not exist', function () {
    $this->get('/una-pagina-che-non-esiste')
        ->assertNotFound()
        ->assertSeeHtml('data-errore="404"')
        ->assertSee("Questa pagina non c'è")
        ->assertSee('Torna alla Dashboard')
        ->assertDontSee('Not Found');
});

it('says in Italian that a page is not for you, without the technical reason', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $tenant = User::factory()->create(['tenant_id' => $ente->id]);
    $tenant->assignRole('Tenant');

    $this->actingAs($tenant)->get('/piattaforma')
        ->assertForbidden()
        ->assertSeeHtml('data-errore="403"')
        ->assertSee('Non puoi aprire questa pagina')
        ->assertDontSee('This action is unauthorized');
});

it('answers a QR link with a broken signature in Italian, not with «Invalid signature»', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();
    $strumento = Strumento::factory()->forNode($reparto)->create();
    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    $this->actingAs($admin)->get('/q/'.$strumento->qr_token.'?signature=sbagliata')
        ->assertForbidden()
        ->assertSeeHtml('data-errore="403"')
        ->assertDontSee('Invalid signature');
});

it('renders the server error pages on their own, without layout or database', function (int $codice, string $titolo) {
    $html = view("errors.{$codice}")->render();

    expect($html)->toContain('data-errore="'.$codice.'"')
        ->toContain($titolo)
        ->not->toContain('@vite')
        ->not->toContain('/build/');
})->with([
    [500, 'Qualcosa non ha funzionato'],
    [503, 'Easy Lab è in manutenzione'],
]);
