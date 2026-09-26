<?php

use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\User;
use App\Support\Piattaforma\CsvSicuro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * 🔴 CSV injection in uscita, sul file COME ARRIVA al browser (S7, T1c · OWASP A03).
 *
 * `CsvSicuro` ha i suoi test di unità; qui si prova che l'unica esportazione
 * CSV dell'applicazione (i clienti della cabina di regia) passi davvero da lì
 * con **ogni** colonna di testo libero, e che i dati entrino dal punto in cui
 * un cliente li scrive (la registrazione, l'anagrafica), non ripuliti.
 *
 * Inventario al 21 Set 2026: `fputcsv` compare solo in `CsvSicuro` e nel
 * template statico di `ImportStrumenti`; nessun'altra esportazione CSV esiste
 * (le altre sono PDF, renderizzati da Blade con escaping).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin->fresh());
});

/** Le celle di ogni riga dati, lette con lo stesso dialetto con cui sono scritte. */
function celleEsportate(): array
{
    $csv = base64_decode(data_get(Livewire::test(Cabina::class)->call('esportaCsv')->effects, 'download.content'));
    $linee = array_slice(preg_split('/\r?\n/', trim(str_replace(CsvSicuro::BOM, '', $csv))), 1);

    return array_map(fn (string $l) => str_getcsv($l, CsvSicuro::DELIMITATORE, '"', ''), $linee);
}

it('neutralises a formula in every free-text column of the customers file', function (string $innesco) {
    Account::factory()->create([
        'ragione_sociale' => $innesco.'HYPERLINK("http://evil.example","Rossi")',
        'partita_iva' => $innesco.'1+1',
        // Un piano fuori catalogo finisce nel file così com'è, etichetta compresa.
        'piano' => $innesco.'cmd|/c calc',
    ]);

    $righe = celleEsportate();
    expect($righe)->toHaveCount(1);

    [$ragione, $piva, $piano, $etichetta] = $righe[0];

    foreach ([$ragione, $piva, $piano, $etichetta] as $cella) {
        expect($cella)->toStartWith("'".$innesco);
    }
})->with(['=', '+', '-', '@', "\t", "\r"]);

it('leaves an ordinary customer untouched', function () {
    Account::factory()->create(['ragione_sociale' => 'Laboratorio Rossi S.r.l.', 'partita_iva' => '01234567890']);

    [$ragione, $piva] = celleEsportate()[0];

    expect($ragione)->toBe('Laboratorio Rossi S.r.l.')
        ->and($piva)->toBe('01234567890');
});
