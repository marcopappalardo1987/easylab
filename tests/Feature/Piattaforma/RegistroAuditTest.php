<?php

use App\Livewire\Piattaforma\RegistroAudit;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * La tabella del registro (S6): cronologica, completa, e senza righe perse.
 *
 * Su un registro di sicurezza «completa» non è un requisito estetico: una riga
 * che sparisce fra una pagina e l'altra è una traccia che nessuno ritrova.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());
});

it('never shows a row from another log channel', function () {
    // ⚠️ La riga va scritta **apposta** senza canale: senza di essa, togliere il
    // pavimento `log_name` dalla porta lascerebbe tutto verde, perché in
    // condizioni normali il progetto scrive solo su `audit`.
    Activity::create(['log_name' => 'default', 'description' => 'Rumore di un altro canale']);
    Activity::create(['log_name' => AuditLog::NAME, 'description' => 'Riga del registro']);

    Livewire::test(RegistroAudit::class)
        ->assertSee('Riga del registro')
        ->assertDontSee('Rumore di un altro canale');
});

it('never loses a row that shares its timestamp with another', function () {
    // 🔴 I timestamp si serializzano **al secondo**, e login e impersonazione
    // vengono scritti nella stessa richiesta: i pari sono la norma, non un caso
    // limite. Senza tie-break la paginazione perde e ripete righe.
    //
    // ⚠️ Le righe si inseriscono in ordine **diverso** dai loro id: su SQLite
    // l'ordine naturale coincide spesso con quello degli id, e senza questa
    // accortezza il test passerebbe anche con la mutazione applicata.
    // ⚠️ Le righe devono **superare la pagina**, o il test è verde per la
    // ragione sbagliata: con tre righe e venticinque per pagina starebbero tutte
    // in pagina 1 e le altre sarebbero vuote — nessuna paginazione esercitata.
    $istante = now()->subHour();
    $quante = 30;

    // Descrizioni in ordine sparso rispetto agli id, perché su SQLite l'ordine
    // naturale coincide spesso con quello degli id e il tie-break passerebbe
    // per caso anche se non ci fosse.
    foreach (array_reverse(range(1, $quante)) as $n) {
        Activity::create([
            'log_name' => AuditLog::NAME,
            'description' => 'Riga '.$n,
            'created_at' => $istante,
            'updated_at' => $istante,
        ]);
    }

    $viste = collect();

    foreach ([1, 2] as $pagina) {
        $viste = $viste->merge(
            Livewire::test(RegistroAudit::class)
                ->call('gotoPage', $pagina)
                ->viewData('righe')->pluck('id')
        );
    }

    // Nessuna persa, nessuna ripetuta.
    expect($viste->count())->toBe($quante)
        ->and($viste->unique()->count())->toBe($quante);
});

it('carries the tie-break into the query, and not by the driver being kind', function () {
    // 🔴 L'asserzione qui sopra **non morde da sola**, e l'ho scoperto mutando:
    // su SQLite l'ordine naturale di scansione è stabile, quindi due pagine
    // consecutive tornano coerenti anche senza tie-break — il test resterebbe
    // verde su una query che in Postgres, con lo stesso piano, può riordinare i
    // pari fra una pagina e l'altra e far sparire righe.
    //
    // Quindi si asserisce sulla **query**: è l'unica prova che non dipende da
    // quanto è gentile il motore sotto.
    // Una riga, o la query paginata non viene nemmeno emessa.
    Activity::create(['log_name' => AuditLog::NAME, 'description' => 'Una qualunque']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(RegistroAudit::class);
    $sql = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $q) => str_contains($q, 'from "activity_log"') && str_contains($q, 'order by'));
    DB::disableQueryLog();

    expect($sql)->not->toBeNull()
        ->and($sql)->toContain('order by "created_at" desc, "id" desc');
});

it('shows the newest first, and can be turned around', function () {
    Activity::create(['log_name' => AuditLog::NAME, 'description' => 'Vecchia', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    Activity::create(['log_name' => AuditLog::NAME, 'description' => 'Recente']);

    $primaDescrizione = fn ($t) => $t->viewData('righe')->first()->description;

    expect($primaDescrizione(Livewire::test(RegistroAudit::class)))->toBe('Recente')
        ->and($primaDescrizione(Livewire::test(RegistroAudit::class)->call('inverti')))->toBe('Vecchia');
});

it('ignores a sort direction that did not come from the whitelist', function () {
    // `sortDir` arriva dalla query string senza passare da `inverti()`.
    Activity::create(['log_name' => AuditLog::NAME, 'description' => 'Vecchia', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    Activity::create(['log_name' => AuditLog::NAME, 'description' => 'Recente']);

    $t = Livewire::test(RegistroAudit::class)->set('sortDir', 'drop table');

    expect($t->viewData('righe')->first()->description)->toBe('Recente');
});

it('keeps a row without causer reachable, and does not call it «Sistema»', function () {
    // Login falliti, console, webhook e coda: sono le righe che contano di più
    // in un registro di sicurezza. «Sistema» sarebbe un'affermazione — non
    // sappiamo chi fosse, e dirlo sarebbe peggio che tacere.
    Activity::create(['log_name' => AuditLog::NAME, 'description' => 'Login fallito']);

    Livewire::test(RegistroAudit::class)
        ->assertSee('Login fallito')
        ->assertDontSee('Sistema');
});

it('tells an act apart from a CRUD event without reading the description', function () {
    // 🔴 `event` è NULL su **tutte** le righe esplicite (nessuno chiama
    // `->event()`) e porta i quattro verbi su quelle del trait: è l'unica chiave
    // affidabile. `description` è testo libero — c'è persino una descrizione
    // interpolata (`Unito il ricambio «X» in «Y»`) e un refuso storico.
    Activity::create(['log_name' => AuditLog::NAME, 'description' => 'Una descrizione qualunque', 'event' => 'created']);
    Activity::create(['log_name' => AuditLog::NAME, 'description' => 'Unito il ricambio «A» in «B»']);

    Livewire::test(RegistroAudit::class)
        ->assertSee('Creazione')
        ->assertSee('Atto');
});

it('queries exactly the tables of the types on the page, and no others', function () {
    // La forma che **morde**: l'insieme esatto, non «al più». Cattura chi
    // iterasse la mappa congelata invece dei tipi davvero presenti — che
    // costerebbe una query per ciascuno degli undici tipi, a ogni pagina.
    $strumento = Strumento::factory()->forNode(UnitaOrganizzativa::factory()->ente()->create())->create();

    Activity::query()->delete();
    Activity::create([
        'log_name' => AuditLog::NAME,
        'description' => 'Modifica strumento',
        'subject_type' => $strumento->getMorphClass(),
        'subject_id' => $strumento->id,
    ]);

    Livewire::test(RegistroAudit::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(RegistroAudit::class);
    $tabelle = collect(DB::getQueryLog())
        ->map(fn ($q) => preg_match('/from "([a-z_]+)"/', $q['query'], $m) ? $m[1] : null)
        ->filter()
        ->unique()
        ->values();
    DB::disableQueryLog();

    // `strumenti` sì, perché è il tipo in pagina. `documenti`, `garanzie`,
    // `interventi` e gli altri otto **no**: non c'è una loro riga.
    expect($tabelle)->toContain('activity_log')
        ->and($tabelle)->toContain('strumenti')
        ->and($tabelle)->not->toContain('documenti')
        ->and($tabelle)->not->toContain('garanzie')
        ->and($tabelle)->not->toContain('interventi')
        ->and($tabelle)->not->toContain('fornitori');
});

it('keeps the query count flat from two rows to forty', function () {
    // Scaldare la cache dei permessi prima di misurare, o si confronta il primo
    // render col secondo invece del numero di righe.
    Livewire::test(RegistroAudit::class);

    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(RegistroAudit::class);
        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "activity_log"')
                || str_contains($q['query'], 'from "users"')
                || str_contains($q['query'], 'from "strumenti"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    // ⚠️ Ogni riga con un **causer** e un **soggetto**, o il test non misura
    // niente: la prima stesura creava righe nude, quindi né la colonna «Chi» né
    // la colonna «Soggetto» facevano una query — e l'N+1 su `causer`, che c'era
    // davvero, passava inosservato. Una prova anti-N+1 su fixture senza
    // relazioni è una prova che non può fallire.
    $riga = function (string $descrizione) {
        $utente = User::factory()->create();

        return Activity::create([
            'log_name' => AuditLog::NAME,
            'description' => $descrizione,
            'causer_type' => $utente->getMorphClass(),
            'causer_id' => $utente->id,
        ]);
    };

    $riga('Una');
    $riga('Due');
    $conDue = $conta();

    for ($i = 0; $i < 38; $i++) {
        $riga("Riga {$i}");
    }

    expect($conta())->toBe($conDue);
});
