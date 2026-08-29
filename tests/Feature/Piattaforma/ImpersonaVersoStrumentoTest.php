<?php

use App\Http\Controllers\ImpersonaVersoStrumento;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * 🔴 Il tasto «impersona» del Parco che atterra **sulla macchina** (ADR-037).
 *
 * Chiesto da Marco il 29 Ago 2026: il tasto serve a intervenire in fretta, e la
 * rotta del pacchetto rimanda a una destinazione fissa — si finiva in dashboard
 * e bisognava ritrovare a mano la macchina appena vista in elenco.
 *
 * ⛔ **È un SECONDO ingresso all'impersonazione**, ed è per questo che i
 * negativi qui sotto sono il cuore del file: un secondo ingresso con guardie
 * più larghe di quelle del pacchetto è il modo in cui una regola si aggira
 * senza che nessuno se ne accorga.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Milano']);
    $this->macchina = Strumento::factory()->forNode($this->sede)->create(['nome' => 'Autoclave']);

    $this->cliente = User::factory()->create(['tenant_id' => $this->sede->id, 'two_factor_confirmed_at' => now()]);
    $this->cliente->assignRole('Admin');
    $this->account->aggiungiMembro($this->cliente);

    $easylab = Account::factory()->create(['ragione_sociale' => 'EasyLab', 'di_piattaforma' => true]);
    $enteEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($easylab)->create(['nome' => 'EasyLab']);

    $this->superadmin = User::factory()->create(['tenant_id' => $enteEasylab->id, 'two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $easylab->aggiungiMembro($this->superadmin);
});

function versoLaMacchina(User $utente, Strumento $strumento): string
{
    return route('piattaforma.parco.impersona', ['utente' => $utente->id, 'strumento' => $strumento->id]);
}

// --- Il caso felice, che è la ragione per cui la rotta esiste ---

it('lands straight on the machine, instead of bouncing to the dashboard', function () {
    $this->actingAs($this->superadmin)
        ->get(versoLaMacchina($this->cliente, $this->macchina))
        ->assertRedirect(route('strumenti.show', $this->macchina->id));

    // E si è davvero entrati come il cliente.
    expect(app('impersonate')->isImpersonating())->toBeTrue();
});

// --- 🔴 I negativi: le stesse quattro guardie del pacchetto, più la nostra ---

it('refuses whoever cannot see the parco at all', function () {
    // La nostra guardia in più: questa porta si apre DAL parco, e non deve
    // esistere per chi il parco non può vederlo — anche se sapesse impersonare.
    $tecnico = User::factory()->create(['tenant_id' => $this->sede->id, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');

    $this->actingAs($tecnico)
        ->get(versoLaMacchina($this->cliente, $this->macchina))
        ->assertForbidden();

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

it('never lets anyone impersonate the Developer, not even from here', function () {
    // ⛔ L'unico account protetto del progetto (ADR-018). Se questa porta lo
    // lasciasse passare, l'avremmo aperta con guardie più larghe di quelle che
    // il resto dell'applicazione applica.
    $developer = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    $this->actingAs($this->superadmin)
        ->get(versoLaMacchina($developer, $this->macchina))
        ->assertForbidden();

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

it('refuses to impersonate again while already impersonating', function () {
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->cliente));

    $this->get(versoLaMacchina($this->cliente, $this->macchina))->assertForbidden();
});

it('refuses to impersonate yourself', function () {
    $this->actingAs($this->superadmin)
        ->get(versoLaMacchina($this->superadmin, $this->macchina))
        ->assertForbidden();
});

// --- La macchina di un'altra sede: si dice, non si atterra su un 404 ---

it('says so instead of dropping you on a 404, when the machine is of another sede', function () {
    // ⚠️ Caso legittimo e comune: un account con più sedi, e il membro scelto
    // sta in una sede diversa da quella della macchina. Dopo l'impersonazione
    // quella macchina è semplicemente invisibile — atterrarci sopra darebbe un
    // 404 dopo aver già cambiato identità, che è il modo peggiore di negare.
    $altraSede = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Torino']);
    $macchinaAltrove = Strumento::factory()->forNode($altraSede)->create(['nome' => 'Centrifuga']);

    $this->actingAs($this->superadmin)
        ->get(versoLaMacchina($this->cliente, $macchinaAltrove))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status');

    // Si è entrati lo stesso — il gesto non va perso — ma lo si è detto.
    expect(app('impersonate')->isImpersonating())->toBeTrue();
});

it('carries its own gate, and does not lean on the route middleware', function () {
    // ⛔ Trovato per mutazione: togliendo `Gate::authorize()` dal controller la
    // suite restava VERDE, perché il `can:` di rotta rispondeva prima. Quel
    // cancello era quindi una guardia che nessuno sapeva più dire se servisse —
    // e la difesa in profondità serve proprio il giorno in cui la rotta cambia.
    //
    // Qui il controller si invoca DIRETTAMENTE, senza middleware davanti: è
    // l'unico modo di provare la seconda linea. Stessa forma della doppia
    // guardia dell'indice PDF dei documenti.
    $tecnico = User::factory()->create(['tenant_id' => $this->sede->id, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');
    $this->actingAs($tecnico);

    $controller = new ImpersonaVersoStrumento;

    expect(fn () => $controller($this->cliente->id, $this->macchina->id))
        ->toThrow(AuthorizationException::class);

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});
