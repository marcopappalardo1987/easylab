<?php

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Piani;
use Database\Seeders\ParcoDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Il seeder dei dati dimostrativi del Parco: gira e non mente.
 *
 * ⚠️ Non prova la *forma* dei dati — quella è di `DemoSeeder`, che qui viene
 * riusato — ma le tre cose che questo seeder aggiunge di suo: clienti distinti,
 * piani a catalogo, e sedi agganciate all'account giusto. Il resto lo copre già
 * `verificaInvarianti()` del genitore, che questo seeder chiama.
 */
it('builds several distinct clients, each on a plan that exists in the catalogue', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ParcoDemoSeeder::class);

    $clienti = Account::query()->where('di_piattaforma', false)->get();

    expect($clienti->count())->toBeGreaterThanOrEqual(8);

    // ⛔ Nessun piano fuori catalogo: un cliente su un codice inesistente vale
    // 0 € nell'MRR e non compare in nessun filtro «per piano» — è lo stato che
    // la cabina chiama «fuori catalogo», e seminarlo lo renderebbe normale.
    foreach ($clienti as $cliente) {
        expect(Piani::esiste($cliente->piano))->toBeTrue();
    }

    // Almeno un cliente con PIÙ sedi, o la colonna «Sede» del Parco non
    // distinguerebbe niente e il test non proverebbe ciò che dice.
    $conPiuSedi = $clienti->filter(fn (Account $a) => $a->enti()->count() > 1);
    expect($conPiuSedi)->not->toBeEmpty();

    // E ogni sede ha davvero delle macchine: un parco vuoto passerebbe ogni
    // altra asserzione di questo file.
    $sedi = UnitaOrganizzativa::withoutGlobalScopes()
        ->whereNotNull('account_id')
        ->where('tipo', TipoUnitaOrganizzativa::Ente->value)
        ->pluck('id');

    expect(Strumento::withoutGlobalScopes()->whereIn('tenant_id', $sedi)->count())
        ->toBeGreaterThan(100);
});

it('is additive, so running it twice does not duplicate the clients', function () {
    // ⚠️ Un seeder di dimostrazione si rilancia per avere più volume: se
    // duplicasse i clienti, il secondo giro renderebbe illeggibile la colonna
    // Cliente proprio nella vista che deve dimostrare.
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ParcoDemoSeeder::class);

    $primoGiro = Account::query()->where('di_piattaforma', false)->count();
    $sediPrimoGiro = UnitaOrganizzativa::withoutGlobalScopes()
        ->where('tipo', TipoUnitaOrganizzativa::Ente->value)->count();

    $this->seed(ParcoDemoSeeder::class);

    expect(Account::query()->where('di_piattaforma', false)->count())->toBe($primoGiro)
        ->and(UnitaOrganizzativa::withoutGlobalScopes()
            ->where('tipo', TipoUnitaOrganizzativa::Ente->value)->count())
        ->toBe($sediPrimoGiro);
});
