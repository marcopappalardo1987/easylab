<?php

use App\Enums\StatoSemaforo;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Parco\MetricheParco;
use App\Support\Parco\RiepilogoParco;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * I quattro numeri della dashboard per ruolo (S6 — Wireframe §1).
 *
 * Sono i primi numeri che un **cliente** legge sul proprio parco, e il rischio
 * non è il 500: è la cifra plausibile e sbagliata, quella che nessuno verifica
 * proprio perché è plausibile. Da qui la forma dei test: l'oracolo è sempre
 * un'implementazione già esistente e indipendente — il calcolo per-model — e mai
 * un ri-enunciato della regola dentro il test.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->macchina = fn (string $nome, ?UnitaOrganizzativa $nodo = null) => Strumento::factory()
        ->forNode($nodo ?? $this->dept)->create(['nome' => $nome]);
});

// ─── I numeri dicono ciò che l'altra implementazione dice ────────────────────

it('counts exactly what the per-model rule names, state by state', function () {
    $scaduta = ($this->macchina)('Scaduta');
    Intervento::factory()->forStrumento($scaduta)->scaduto()->create();

    $garanzia = ($this->macchina)('Garanzia in scadenza');
    Garanzia::factory()->scadenzaDichiarata(today()->addDays(5)->toDateString())
        ->forStrumento($garanzia)->create();

    ($this->macchina)('In regola');
    ($this->macchina)('Anche in regola');

    $rossa = ($this->macchina)('Non idonea');
    $rossa->forzaSemaforo(StatoSemaforo::Rosso, 'Guasto in verifica');

    // Forzata verde con uno scaduto sotto: conta come VERDE (ADR-005).
    $forzata = ($this->macchina)('Forzata verde');
    Intervento::factory()->forStrumento($forzata)->scaduto()->create();
    $forzata->forzaSemaforo(StatoSemaforo::Verde);

    $this->actingAs($this->admin);

    // L'oracolo: il calcolo per-model, riga per riga.
    $perModel = Strumento::all()->groupBy(fn (Strumento $s) => $s->statoSemaforoEffettivo()->value)->map->count();
    $r = MetricheParco::riepilogo();

    expect($r->verdi)->toBe($perModel[StatoSemaforo::Verde->value] ?? 0)
        ->and($r->arancioni)->toBe($perModel[StatoSemaforo::Arancione->value] ?? 0)
        ->and($r->rossi)->toBe($perModel[StatoSemaforo::Rosso->value] ?? 0)
        // ⚠️ Numeri VOLUTAMENTE diversi fra loro: con tre volte lo stesso valore
        // uno scambio fra due campi del DTO passerebbe inosservato.
        ->and([$r->verdi, $r->arancioni, $r->rossi])->toBe([3, 2, 1]);
});

it('adds up to the whole parco, because the three states are a partition', function () {
    foreach (range(1, 4) as $n) {
        $s = ($this->macchina)("Scaduta {$n}");
        Intervento::factory()->forStrumento($s)->scaduto()->create();
    }
    ($this->macchina)('Sana')->forzaSemaforo(StatoSemaforo::Rosso, 'Motivo');
    ($this->macchina)('Sana davvero');

    $this->actingAs($this->admin);

    // È l'invariante su cui la pagina poggia: una macchina che sparisce da tutti
    // e tre gli insiemi è invisibile a qualunque asserzione su un insieme solo.
    expect(MetricheParco::riepilogo()->totale())->toBe(Strumento::count())
        ->and(Strumento::count())->toBe(6);
});

it('refuses a forced_state outside the enum, where the database can say so', function () {
    // 🔴 **L'invariante su cui poggiano i quattro numeri è ora una regola del
    // DATABASE** (migration del 26 Ago 2026), non più un'ipotesi sostenuta dalla
    // forma del codice. Fino a ieri reggeva perché `forced_state` sta fuori da
    // `$fillable` e l'unica via è `forzaSemaforo()`; ciò che non copriva era una
    // migration di correzione o un import, che scrivono col query builder.
    //
    // Si scrive col query builder apposta: è la strada che il vincolo esiste per
    // chiudere, e l'unica su cui il model non ha voce.
    $rognosa = ($this->macchina)('Con uno stato che non esiste');

    expect(fn () => DB::table('strumenti')->where('id', $rognosa->id)->update(['forced_state' => 'giallo']))
        ->toThrow(QueryException::class);
})->skip(
    fn () => DB::connection()->getDriverName() === 'sqlite',
    'SQLite non aggiunge vincoli a una tabella esistente: il CHECK non c\'è, e il test gemello qui sotto descrive quel mondo.'
);

it('makes a rogue forced_state visible instead of hiding it, where the check is missing', function () {
    // 🔴 **Perché il totale è DERIVATO dalla somma e non contato a parte.** Fuori
    // enum la riga cade fuori da tutti e tre i rami di `scopeConStato()`: con un
    // totale letto da una quarta query i numeri tornerebbero singolarmente e
    // **non fra loro**, cioè l'anomalia sarebbe muta. Derivandolo, la somma
    // smette di combaciare col parco e qualcuno se ne accorge.
    //
    // ⚠️ **Questo test descrive il mondo SENZA il CHECK**, che dal 26 Ago 2026 è
    // il solo SQLite — cioè la suite in locale. In CI, su Postgres, la scrittura
    // è rifiutata e vale il gemello qui sopra. La scelta del totale derivato
    // resta comunque quella giusta: il vincolo protegge questa colonna, non
    // rende vera per sempre l'aritmetica di chi la legge.
    ($this->macchina)('Sana');
    $rognosa = ($this->macchina)('Con uno stato che non esiste');
    DB::table('strumenti')->where('id', $rognosa->id)->update(['forced_state' => 'giallo']);

    $this->actingAs($this->admin);
    $r = MetricheParco::riepilogo();

    expect(Strumento::count())->toBe(2)
        ->and($r->totale())->toBe(1)
        ->and($r->totale())->toBeLessThan(Strumento::count());
})->skip(
    fn () => DB::connection()->getDriverName() !== 'sqlite',
    'Qui il CHECK esiste e la riga rognosa non si può creare: vale il test gemello qui sopra.'
);

it('counts the obsolete ones without moving them out of their semaforo state', function () {
    // ADR-014: l'obsolescenza «non tocca il semaforo». La quarta cifra è
    // ORTOGONALE alle tre, non una quarta fetta.
    $this->ente->update(['soglia_obsolescenza_anni' => 10]);

    $vecchiaEScaduta = ($this->macchina)('Vecchia e scaduta');
    $vecchiaEScaduta->update(['data_installazione' => today()->subYears(12)->toDateString()]);
    Intervento::factory()->forStrumento($vecchiaEScaduta)->scaduto()->create();

    $vecchiaESana = ($this->macchina)('Vecchia e sana');
    $vecchiaESana->update(['data_installazione' => today()->subYears(12)->toDateString()]);

    ($this->macchina)('Nuova')->update(['data_installazione' => today()->subYear()->toDateString()]);

    $this->actingAs($this->admin);
    $r = MetricheParco::riepilogo();

    expect($r->obsoleti)->toBe(2)
        ->and($r->totale())->toBe(3)          // le obsolete sono già dentro le tre
        ->and($r->arancioni)->toBe(1)
        ->and($r->verdi)->toBe(2);
});

// ─── Il confine: i numeri si fermano dove si ferma chi guarda ────────────────

it('never counts a machine of another Ente', function () {
    // 🔴 La sonda che morde: `Strumento` ha `TenantScope`, quindi questo è il
    // test che cadrebbe per primo se qualcuno togliesse gli scope «per far
    // vedere di più».
    ($this->macchina)('Mia');

    $altro = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($altro)->create();
    $sua = Strumento::factory()->forNode($altroDept)->create(['nome' => 'Sua']);
    Intervento::factory()->forStrumento($sua)->scaduto()->create();

    $this->actingAs($this->admin);

    expect(MetricheParco::riepilogo()->totale())->toBe(1)
        ->and(MetricheParco::riepilogo()->arancioni)->toBe(0);
});

it('limits a Responsabile to their own sub-tree', function () {
    $mio = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Mio']);
    $altrui = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Altrui']);

    ($this->macchina)('Nel mio reparto', $mio);
    $fuori = Strumento::factory()->forNode($altrui)->create(['nome' => 'Fuori']);
    Intervento::factory()->forStrumento($fuori)->scaduto()->create();

    $resp = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($mio->id);
    $this->actingAs($resp->fresh());

    expect(MetricheParco::riepilogo()->totale())->toBe(1)
        ->and(MetricheParco::riepilogo()->arancioni)->toBe(0);
});

it('counts zero for a Responsabile with no assignments, and that is fail-safe', function () {
    $s = ($this->macchina)('C\'è ma non per lui');
    Intervento::factory()->forStrumento($s)->scaduto()->create();

    $resp = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $resp->assignRole('Responsabile Reparto');
    $this->actingAs($resp->fresh());

    // Zero perché non vede nulla, non perché non c'è nulla: la pagina dovrà
    // dirlo con una frase e non con quattro zeri (blocco D).
    expect(MetricheParco::riepilogo()->totale())->toBe(0)
        ->and(Strumento::withoutGlobalScopes()->count())->toBe(1);
});

it('counts the portfolio of a Tecnico, not the Ente', function () {
    ($this->macchina)('Non sua');

    $altro = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($altro)->create();
    Strumento::factory()->forNode($altroDept)->create(['nome' => 'Del portafoglio']);

    $tecnico = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');
    $tecnico->portafoglioClienti()->attach($altro->id);
    $this->actingAs($tecnico->fresh());

    expect(MetricheParco::riepilogo()->totale())->toBe(1);
});

it('counts zero, not everything, for an authenticated user without a tenant', function () {
    // Fail-closed di ADR-018: è l'errore che si presenta come «vede tutto».
    ($this->macchina)('Di qualcuno');

    $orfano = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $orfano->assignRole('Admin');
    $this->actingAs($orfano->fresh());

    expect(MetricheParco::riepilogo()->totale())->toBe(0);
});

it('leaves a trashed machine out of every number', function () {
    ($this->macchina)('Viva');
    ($this->macchina)('Cestinata')->delete();

    $this->actingAs($this->admin);

    expect(MetricheParco::riepilogo()->totale())->toBe(1);
});

// ─── La frase sotto il numero degli obsoleti ─────────────────────────────────

it('names the soglia when there is one, and refuses to when there are two', function () {
    // ⚠️ La soglia è per Ente (ADR-014): «oltre 10 anni» è vero solo finché di
    // soglie ne esiste una. Chi vede più sedi con soglie diverse — un Tecnico
    // col portafoglio — non deve leggere la regola di una spacciata per quella
    // di tutte.
    $this->ente->update(['soglia_obsolescenza_anni' => 7]);
    ($this->macchina)('Una');

    // ⚠️ Il secondo Ente si crea PRIMA di autenticarsi: con un utente in
    // sessione `BelongsToTenant::creating` riscriverebbe il suo `tenant_id` su
    // quello di chi guarda, e nascerebbe dentro l'Ente A — la trappola per cui
    // esiste `UnitaOrganizzativa::radicaComeEnte()`. Verificato: le due soglie
    // diventavano una, e il test passava dicendo la cosa sbagliata.
    $altro = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $altro->update(['soglia_obsolescenza_anni' => 15]);
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($altro)->create();
    Strumento::factory()->forNode($altroDept)->create(['nome' => "Dell'altro"]);

    $tecnico = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');
    $tecnico->portafoglioClienti()->attach([$this->ente->id, $altro->id]);

    $this->actingAs($this->admin);
    expect(MetricheParco::riepilogo()->dettaglioObsoleti())->toBe('oltre 7 anni');

    $this->actingAs($tecnico->fresh());

    // Il Tecnico vede entrambe le sedi: la frase non può nominare una soglia.
    expect(MetricheParco::riepilogo()->totale())->toBe(2)
        ->and(MetricheParco::riepilogo()->dettaglioObsoleti())->toBe('oltre la soglia di ciascuna sede');
});

it('says nothing about soglie when there is no parco at all', function () {
    // Una frase sulla soglia sotto uno zero senza macchine è rumore: la pagina
    // in quel caso dirà tutt\'altro (blocco D).
    $orfano = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $orfano->assignRole('Admin');
    $this->actingAs($orfano->fresh());

    expect(MetricheParco::riepilogo()->dettaglioObsoleti())->toBe('')
        ->and(MetricheParco::riepilogo()->parcoVuoto())->toBeTrue();
});

// ─── Costo ───────────────────────────────────────────────────────────────────

it('costs the same whether there is one machine or thirty', function () {
    // 🔴 **La prima stesura mentiva due volte, e l'ho misurato.**
    //
    // 1. Filtrava il log per `"strumenti"` e `from "unita_organizzativa"`, cioè
    //    contava le query che si aspettava invece di quelle che ci sono:
    //    infilando tre `DB::table('interventi')->count()` dentro `riepilogo()`
    //    il test restava verde. Ora si conta il log **secco**.
    // 2. Esercitava il solo Admin — il ruolo per cui la costanza vale
    //    banalmente. Per il **Responsabile Reparto** il costo è tutt'altro, e
    //    va scritto invece che scoperto in produzione.
    $misura = function (User $utente): int {
        // Un giro a vuoto prima: la prima richiesta di un utente risolve ruoli e
        // permessi, e quelle letture non si ripetono.
        $this->actingAs($utente);
        MetricheParco::riepilogo();

        DB::flushQueryLog();
        DB::enableQueryLog();
        MetricheParco::riepilogo();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    ($this->macchina)('Una');

    $conUna = $misura($this->admin);

    foreach (range(1, 30) as $n) {
        $s = ($this->macchina)("Macchina {$n}");
        Intervento::factory()->forStrumento($s)->scaduto()->create();
    }

    // La proprietà che conta: trentuno macchine non costano più di una.
    expect($misura($this->admin))->toBe($conUna)
        // Sei, e sapere quali rende utile il numero: le soglie (2), i tre
        // conteggi di stato, il conteggio degli obsoleti.
        ->and($conUna)->toBe(6);
});

it('costs a Responsabile two statements more than the Admin, once per request', function () {
    // Il debito dichiarato in S6 (28 statement, undici riletture dell'albero)
    // è chiuso da `AccessibleNodesMemo` (S7/T4): pivot e albero si leggono
    // UNA volta per richiesta. Si azzerano le istanze scoped prima di
    // misurare, cioè si misura una richiesta nuova: 6 + le due letture.
    $mio = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Mio']);
    Strumento::factory()->forNode($mio)->create(['nome' => 'Sua']);

    $resp = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($mio->id);
    $this->actingAs($resp->fresh());

    MetricheParco::riepilogo();
    app()->forgetScopedInstances();

    DB::flushQueryLog();
    DB::enableQueryLog();
    MetricheParco::riepilogo();
    $statement = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $albero = $statement->filter(fn ($q) => str_contains($q['query'], 'responsabile_unita'))->count();

    expect($statement)->toHaveCount(8)
        ->and($albero)->toBe(1);
});

// ─── Privacy: il pallino è un aggregato, la sua causa no ─────────────────────

it('never breaks the orange down by cause', function () {
    // ⛔ ADR-020 legittima il PALLINO come aggregato dovuto a tutti, non la sua
    // scomposizione: «di cui N da garanzie ricambio» direbbe a un Ente su
    // `nascosta` quanti pezzi sostituiti ha sulle proprie macchine, e lo direbbe
    // senza passare da nessuno scope — perché un numero non è una riga.
    $riflesso = new ReflectionClass(RiepilogoParco::class);
    $campi = collect($riflesso->getProperties())->map->getName()->all();

    expect($campi)->toEqualCanonicalizing(
        ['verdi', 'arancioni', 'rossi', 'obsoleti', 'soglie'],
        'Il DTO del parco ha cambiato forma. Se il campo nuovo scompone uno stato per CAUSA '.
        '(garanzia macchina, garanzia ricambio, intervento) non va aggiunto: ADR-020 legittima '.
        'il pallino come aggregato dovuto a tutti, non la sua scomposizione, e un conteggio non '.
        'passa da nessuno scope. Se invece e un campo innocuo, aggiornalo qui dopo averci pensato.'
    );
});
