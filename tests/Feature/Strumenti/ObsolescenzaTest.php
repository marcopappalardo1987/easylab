<?php

use App\Enums\StatoSemaforo;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Obsolescenza (ADR-014): derivata da `data_installazione` con soglia
 * configurabile per Ente. Solo segnalazione — non tocca il semaforo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->strumentoInstallato = fn (?string $data) => Strumento::factory()->forNode($this->dept)
        ->create(['data_installazione' => $data]);
});

// --- Confini del calcolo ---

it('defaults the soglia to 10 years', function () {
    // Il default vive sulla colonna: va riletto dal DB, non dall'istanza appena creata.
    expect($this->ente->fresh()->soglia_obsolescenza_anni)->toBe(10);
});

it('is obsoleto when installed exactly the soglia ago', function () {
    // Confine INCLUSIVO: `>=` dell'ADR.
    $strumento = ($this->strumentoInstallato)(today()->subYears(10)->toDateString());

    expect($strumento->isObsoleto())->toBeTrue();
});

it('is not obsoleto one day short of the soglia', function () {
    $strumento = ($this->strumentoInstallato)(today()->subYears(10)->addDay()->toDateString());

    expect($strumento->isObsoleto())->toBeFalse();
});

it('is never obsoleto without an installation date', function () {
    // Manca la base del calcolo: nessuna affermazione sostenibile.
    $strumento = ($this->strumentoInstallato)(null);

    expect($strumento->isObsoleto())->toBeFalse();
});

it('reads the soglia from its own Ente', function () {
    $this->ente->update(['soglia_obsolescenza_anni' => 5]);

    $seiAnni = ($this->strumentoInstallato)(today()->subYears(6)->toDateString());
    $quattroAnni = ($this->strumentoInstallato)(today()->subYears(4)->toDateString());

    expect($seiAnni->fresh()->isObsoleto())->toBeTrue()
        ->and($quattroAnni->fresh()->isObsoleto())->toBeFalse()
        ->and($seiAnni->fresh()->sogliaObsolescenza())->toBe(5);
});

it('keeps each Ente on its own soglia', function () {
    $this->ente->update(['soglia_obsolescenza_anni' => 5]);
    $mio = ($this->strumentoInstallato)(today()->subYears(6)->toDateString());

    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']); // default 10
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $suo = Strumento::factory()->forNode($deptB)->create(['data_installazione' => today()->subYears(6)->toDateString()]);

    // Stessa età, esito diverso: la soglia è per-tenant.
    expect($mio->fresh()->isObsoleto())->toBeTrue()
        ->and($suo->fresh()->isObsoleto())->toBeFalse();
});

it('falls back to 10 years when the tenant is not readable', function () {
    // `tenant_id` è NOT NULL, ma la relazione può comunque restituire null:
    // UnitaOrganizzativa usa SoftDeletes, quindi un Ente cestinato sparisce.
    $this->ente->update(['soglia_obsolescenza_anni' => 3]);
    $strumento = ($this->strumentoInstallato)(today()->subYears(5)->toDateString());
    $this->ente->delete();

    $senzaTenant = Strumento::withoutGlobalScopes()->find($strumento->id);

    expect($senzaTenant->tenant)->toBeNull()
        ->and($senzaTenant->sogliaObsolescenza())->toBe(10)   // fallback, non la soglia 3
        ->and($senzaTenant->isObsoleto())->toBeFalse();       // 5 anni < 10
});

it('does not affect the semaforo', function () {
    // ADR-014: solo segnalazione. Uno strumento vecchissimo senza scadenze
    // aperte resta verde.
    $vecchio = ($this->strumentoInstallato)(today()->subYears(30)->toDateString());

    expect($vecchio->isObsoleto())->toBeTrue()
        ->and($vecchio->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde);
});

// --- Filtro dell'elenco: la forma SQL deve coincidere col calcolo per-model ---

it('keeps the elenco filter aligned with isObsoleto, with a custom soglia', function () {
    $this->ente->update(['soglia_obsolescenza_anni' => 5]);

    foreach ([
        'esatti 5 anni' => today()->subYears(5)->toDateString(),
        'un giorno sotto' => today()->subYears(5)->addDay()->toDateString(),
        'un giorno sopra' => today()->subYears(5)->subDay()->toDateString(),
        'molto vecchio' => today()->subYears(20)->toDateString(),
        'recente' => today()->subMonths(3)->toDateString(),
        'senza data' => null,
    ] as $etichetta => $data) {
        Strumento::factory()->forNode($this->dept)->create(['nome' => $etichetta, 'data_installazione' => $data]);
    }

    $filtrati = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('soloObsoleti', true)
        ->viewData('strumenti')->pluck('id')->all();

    $attesi = Strumento::with('tenant')->get()
        ->filter(fn (Strumento $s) => $s->isObsoleto())
        ->pluck('id')->all();

    expect($filtrati)->toEqualCanonicalizing($attesi)
        ->and($filtrati)->not->toBeEmpty();
});

it('shows the obsoleto badge in the elenco only beyond the soglia', function () {
    Strumento::factory()->forNode($this->dept)->create(['nome' => 'Vecchia', 'data_installazione' => today()->subYears(12)->toDateString()]);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)->assertSee('Obsoleto');

    Strumento::query()->delete();
    Strumento::factory()->forNode($this->dept)->create(['nome' => 'Nuova', 'data_installazione' => today()->subYear()->toDateString()]);

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)->assertDontSee('Obsoleto');
});

it('goes back to the first page when the obsoleti filter changes', function () {
    foreach (range(1, 25) as $n) {
        Strumento::factory()->forNode($this->dept)->create(['data_installazione' => today()->subYears(15)->toDateString()]);
    }

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->call('setPage', 2)
        ->set('soloObsoleti', true)
        ->assertSet('paginators.page', 1);
});

it('reads the soglie in a fixed number of queries when the filter is on', function () {
    // ⚠️ **Il test qui sotto non copre questo caso**, e la differenza conta: là
    // `soloObsoleti` resta falso, quindi `scopeObsoleti()` non gira nemmeno.
    // Dal blocco A di S6 quello scope fa una lettura propria delle soglie.
    //
    // ⚠️ **Si asserisce il numero ASSOLUTO e non la sola costanza**, ed è una
    // correzione misurata: il ciclo di `scopeObsoleti()` gira sui tenant
    // DISTINTI, non sulle righe, quindi una query infilata lì dentro resta
    // costante al crescere del parco e la sola costanza non la vede. Con un
    // Ente solo gli statement su `unita_organizzativa` sono due — le soglie e
    // l'elenco dei nodi dei filtri — e fissarli è l'unico modo di accorgersene.
    // ⚠️ `withQueryParams()` e non `set()`: `set()` monta col default e poi
    // RIRENDERIZZA, cioè misura due pagine invece di una — ed è anche la strada
    // da cui il filtro arriva davvero, essendo `#[Url]`.
    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['soloObsoleti' => true])
            ->actingAs($this->admin)->test(ElencoStrumenti::class);
        $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "unita_organizzativa"'))->count();
        DB::disableQueryLog();

        return $n;
    };

    ($this->strumentoInstallato)(today()->subYears(12)->toDateString());

    // Un render a vuoto prima di misurare: scalda la cache dei permessi di
    // spatie, che altrimenti finirebbe nel primo conteggio e non nel secondo.
    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class);
    $conUno = $conta();

    foreach (range(1, 10) as $n) {
        ($this->strumentoInstallato)(today()->subYears(12)->toDateString());
    }

    // Costante al crescere delle righe, e **fissa nel numero**: quattro
    // statement su `unita_organizzativa`, e sapere quali è ciò che rende utile
    // il numero — le soglie di `scopeObsoleti()`, l'elenco dei nodi dei filtri,
    // e i due eager load della pagina (`unita` e `tenant`). Una quinta lettura
    // è qualcuno che ha rimesso una query dentro un ciclo.
    expect($conUno)->toBe(4)
        ->and($conta())->toBe($conUno);
});

it('loads the tenant once, whatever the number of rows', function () {
    // Il badge per riga legge la soglia dal tenant: senza eager load sarebbe N+1.
    $queryTenant = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->admin)->test(ElencoStrumenti::class);
        $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "unita_organizzativa"'))->count();
        DB::disableQueryLog();

        return $n;
    };

    Strumento::factory()->forNode($this->dept)->create(['data_installazione' => today()->subYears(12)->toDateString()]);
    $conUno = $queryTenant();

    foreach (range(1, 10) as $n) {
        Strumento::factory()->forNode($this->dept)->create(['data_installazione' => today()->subYears(12)->toDateString()]);
    }

    expect($queryTenant())->toBe($conUno);
});
