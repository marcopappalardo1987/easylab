<?php

/**
 * Il selettore di tema **della top bar** (🔗 ADR-034, DS §8.3).
 *
 * ⚠️ **Non è lo stesso oggetto di quello nella pagina Preferenze**, ed è la
 * ragione per cui questo file esiste: il dropdown del menù utente non è un
 * componente Livewire, e un `wire:click` fuori da Livewire è un attributo
 * **inerte** — non dà errore, semplicemente non chiama nessuno. La pagina
 * cambierebbe colore e `users.tema` no, e al caricamento successivo il
 * riallineamento con il database **annullerebbe la scelta**.
 *
 * Da qui il terzo figlio Livewire in top bar, e da qui il trait: i consumatori
 * della stessa guardia sono diventati due, e due copie divergono.
 */

use App\Enums\TemaUtente;
use App\Livewire\Settings\PreferenzeNotifiche;
use App\Livewire\Settings\SelettoreTema;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('writes the column from the top bar, and refuses a value the enum does not have', function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $u = User::factory()->create(['tenant_id' => $ente->id]);
    $u->assignRole('Tenant');

    $this->actingAs($u);

    Livewire::test(SelettoreTema::class)->call('scegliTema', 'scuro');
    expect($u->fresh()->tema)->toBe(TemaUtente::Scuro);

    Livewire::test(SelettoreTema::class)->call('scegliTema', 'mezzanotte')->assertHasErrors('tema');
    expect($u->fresh()->tema)->toBe(TemaUtente::Scuro);

    // Il render successivo rimette `aria-pressed` sul bottone giusto, dal DB.
    $html = Livewire::test(SelettoreTema::class)->html();
    expect($html)->toContain('data-tema="scuro"')
        ->and(substr_count($html, 'aria-pressed="true"'))->toBe(1);

    preg_match('/<button[^>]*data-tema="scuro"[^>]*>/', $html, $b);
    expect($b[0])->toContain('aria-pressed="true"');

    // E la stessa guardia, dalla pagina Preferenze: una sola implementazione.
    // I metodi di un trait sono COPIATI nella classe, quindi `getDeclaringClass()`
    // direbbe la classe: la prova che l'implementazione è una sola sta nel file.
    expect((new ReflectionMethod(PreferenzeNotifiche::class, 'scegliTema'))->getFileName())
        ->toBe((new ReflectionMethod(SelettoreTema::class, 'scegliTema'))->getFileName())
        ->toContain('Concerns/ScegliTema.php');
});
