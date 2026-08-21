<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * Chi amministra il rapporto commerciale di un Account (ADR-032).
 *
 * Il permesso è la condizione necessaria, la Policy restringe con
 * l'appartenenza — «e nessuno dei due allarga l'altro». Con `teams = false` in
 * `config/permission.php` i permessi sono globali, quindi senza questa Policy
 * un Admin con `billing.manage_own` lo avrebbe su **ogni** account della
 * piattaforma.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create();

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->admin->assignRole('Admin');
    $this->account->aggiungiMembro($this->admin);
});

it('lets a member with the permission manage their own account', function () {
    expect(Gate::forUser($this->admin)->allows('manage', $this->account))->toBeTrue();
});

it('never lets an Admin manage an account they are not a member of', function () {
    // Il negativo che regge tutto: l'Admin il permesso ce l'ha (è globale),
    // e ciò che gli impedisce di toccare il contratto di un altro cliente è
    // solo questa Policy. È anche la prova che il nome dell'ability è scelto
    // bene: se si chiamasse `billing.manage_own`, il `Gate::before` di spatie
    // concederebbe prima ancora di interrogarci.
    $altrui = Account::factory()->create(['ragione_sociale' => 'Gruppo Bianchi']);

    expect($this->admin->can('billing.manage_own'))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manage', $altrui))->toBeFalse();
});

it('never lets a member without the permission manage the account', function () {
    // L'appartenenza da sola non basta: la Policy restringe, non concede.
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $this->account->aggiungiMembro($tenant);

    expect($tenant->can('billing.manage_own'))->toBeFalse()
        ->and(Gate::forUser($tenant)->allows('manage', $this->account))->toBeFalse();
});

it('lets the Superadmin manage an account they do not belong to', function () {
    // `billing.manage_global` esiste proprio per gli account altrui: senza
    // questo ramo il Superadmin avrebbe `manage` falso ovunque, e in S6 la via
    // rapida sarebbe rilassare l'appartenenza — cioè rompere ADR-032 dal lato
    // sbagliato.
    $superadmin = User::factory()->create();
    $superadmin->assignRole('Superadmin');

    $altrui = Account::factory()->create();

    expect(Gate::forUser($superadmin)->allows('manage', $altrui))->toBeTrue();
});

it('keeps the lockout lever in platform hands only', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('Superadmin');

    expect(Gate::forUser($superadmin)->allows('lockout', $this->account))->toBeTrue()
        // 🔒 `billing.lockout` non è dell'Admin, nemmeno sul proprio account:
        // chiudere un contratto è un gesto di piattaforma.
        ->and(Gate::forUser($this->admin)->allows('lockout', $this->account))->toBeFalse();
});

it('never lets the bare permission be used in place of the ability', function () {
    // 🛡️ Guardrail, non un test di comportamento. La strada sbagliata più
    // probabile — nel blocco 2 e in S6 — è `authorize('billing.manage_own')` o
    // `->middleware('can:billing.manage_own')`: il permesso nudo, che concede a
    // chiunque su qualunque account. È già successo con
    // `garanzie.ricambio.manage` in `_panoramica.blade.php`.
    //
    // Si cerca la STRINGA e non la forma della chiamata: `routes/web.php` usa
    // `->middleware('can:…')` dodici volte, e una tokenizzazione su `can(`
    // lascerebbe passare proprio l'idioma del progetto.
    $sorgenti = collect([app_path(), resource_path('views'), base_path('routes')])
        ->flatMap(fn (string $dir) => collect(
            iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)))
        )->filter(fn ($f) => $f->isFile() && in_array($f->getExtension(), ['php'], true))->map->getPathname())
        ->unique()
        ->reject(fn (string $file) => str_ends_with($file, 'app/Policies/AccountPolicy.php'));

    // I **commenti si tolgono prima di cercare**, come fa il meta-test
    // dell'audit: questo blocco nomina `billing.manage_own` in più docblock per
    // spiegare proprio perché non si usa nudo, e un guardrail che punisse la
    // documentazione finirebbe per farla cancellare.
    $codice = function (string $file): string {
        $sorgente = file_get_contents($file);

        if (! str_ends_with($file, '.blade.php')) {
            $sorgente = collect(token_get_all($sorgente))
                ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($token) => is_array($token) ? $token[1] : $token)
                ->implode('');
        }

        return $sorgente;
    };

    $colpevoli = $sorgenti
        ->filter(fn (string $file) => str_contains($codice($file), 'billing.manage_own'))
        ->values();

    expect($colpevoli)->toBeEmpty(
        'billing.manage_own va usato solo dentro AccountPolicy: altrove concede a chiunque su qualunque account. Trovato in: '.$colpevoli->implode(', ')
    );
});
