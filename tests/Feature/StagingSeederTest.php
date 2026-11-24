<?php

use App\Models\Account;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\User;
use App\Support\Garanzie\GaranzieDalCollaudo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StagingSeeder;
use Illuminate\Support\Facades\DB;

it('fills three EasyLab staging sites and remains idempotent', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(StagingSeeder::class);

    $account = Account::where('ragione_sociale', 'EasyLab — Demo staging')->sole();
    $sedi = $account->enti()->orderBy('nome')->get();

    expect($account->piano)->toBe('saas')
        ->and($sedi->pluck('nome')->all())->toBe([
            'EasyLab Catania',
            'EasyLab Milano',
            'EasyLab Roma',
        ]);

    foreach ($sedi as $sede) {
        $strumenti = Strumento::withoutGlobalScopes()->where('tenant_id', $sede->id)->count();

        expect($strumenti)->toBeGreaterThan(1000)
            ->and(User::role('Tecnico')->where('tenant_id', $sede->id)->count())->toBe(12)
            ->and(Fornitore::withoutGlobalScopes()->where('tenant_id', $sede->id)->count())->toBe(10)
            ->and(Ricambio::withoutGlobalScopes()->where('tenant_id', $sede->id)->count())->toBe(10)
            ->and(Intervento::withoutGlobalScopes()->where('tenant_id', $sede->id)->count())->toBeGreaterThan($strumenti)
            ->and(Garanzia::withoutGlobalScopes()->where('tenant_id', $sede->id)->count())->toBeGreaterThanOrEqual($strumenti)
            ->and(SpostamentoStrumento::withoutGlobalScopes()->where('tenant_id', $sede->id)->count())->toBeGreaterThan(0);
    }

    expect(DB::table('tecnico_cliente')->whereIn('ente_id', $sedi->pluck('id'))->count())->toBe(3);

    // 🔗 ADR-065: le garanzie della macchina nascono contate dal collaudo, e
    // il DB dimostrativo ha ancora i tre esiti (finite, in scadenza, attive).
    $macchina = fn () => Garanzia::withoutGlobalScopes()->where('soggetto', 'macchina');

    expect(GaranzieDalCollaudo::disallineate())->toBeEmpty()
        ->and($macchina()->where('data_scadenza_effettiva', '<', today()->toDateString())->exists())->toBeTrue()
        ->and($macchina()->whereBetween('data_scadenza_effettiva', [today()->toDateString(), today()->addDays(30)->toDateString()])->exists())->toBeTrue()
        ->and($macchina()->where('data_scadenza_effettiva', '>', today()->addDays(60)->toDateString())->exists())->toBeTrue();

    $conteggi = [
        Strumento::withoutGlobalScopes()->count(),
        Intervento::withoutGlobalScopes()->count(),
        Garanzia::withoutGlobalScopes()->count(),
        User::count(),
    ];

    $this->seed(StagingSeeder::class);

    expect([
        Strumento::withoutGlobalScopes()->count(),
        Intervento::withoutGlobalScopes()->count(),
        Garanzia::withoutGlobalScopes()->count(),
        User::count(),
    ])->toBe($conteggi);
});
