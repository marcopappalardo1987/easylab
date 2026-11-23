<?php

use App\Models\Fornitore;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\File;

/**
 * «Data installazione» è diventata «Data di collaudo» (🔗 ADR-065, 23 Nov
 * 2026): in tutto ciò che l'applicazione mostra, la parola è collaudo.
 *
 * La colonna `strumenti.data_installazione` ha tenuto il nome, che è un
 * identificatore, e così la proprietà `dataInstallazione` dell'avviso di
 * obsolescenza: quelli si tolgono prima di cercare. Restano i commenti, che
 * il cliente non legge.
 */
function testiCheNominanoLInstallazione(): array
{
    $trovati = [];
    $identificatori = '/data_installazione|dataInstallazione/';
    $parola = '/installazion|installat[oaie]\b/i';

    // Viste e traduzioni: tutto il testo, tolti i commenti Blade.
    foreach ([resource_path('views'), lang_path('it')] as $cartella) {
        foreach (File::allFiles($cartella) as $file) {
            $testo = preg_replace(['/\{\{--.*?--\}\}/s', $identificatori], '', $file->getContents());

            if (preg_match($parola, $testo, $m)) {
                $trovati[] = $file->getRelativePathname().': «'.$m[0].'»';
            }
        }
    }

    // Il codice: le sole stringhe, senza i commenti (sono i messaggi che
    // un modulo, un'email o un comando mostrano).
    foreach (File::allFiles(app_path()) as $file) {
        foreach (token_get_all($file->getContents()) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match($parola, preg_replace($identificatori, '', $token[1]), $m)) {
                $trovati[] = $file->getRelativePathname().': «'.$m[0].'»';
            }
        }
    }

    return $trovati;
}

it('never names the installation in what the application shows', function () {
    expect(testiCheNominanoLInstallazione())->toBe([]);
});

it('names the collaudo on the page of the machine and in the edit form', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();
    $strumento = Strumento::factory()->forNode($dept)->create([
        'data_installazione' => '2017-09-04',
        'fornitore_id' => Fornitore::factory()->forTenant($ente)->create()->id,
    ]);
    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    scheda($admin, $strumento)
        ->assertSee('Collaudo 09/2017')
        ->assertDontSee('Installato')
        ->call('edit')
        ->assertSee('Data di collaudo')
        ->assertDontSee('Data installazione');

    $this->actingAs($admin)->get(route('strumenti.index'))->assertSee('Collaudo')->assertDontSee('Installazione');
});
