<?php

use App\Enums\StatoSemaforo;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Semaforo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * 🔴 La regola del semaforo ha UNA forma SQL (🔗 ADR-005/004/014/020 — S6).
 *
 * Fino a questo blocco ne esistevano due, entrambe dentro `ElencoStrumenti`: il
 * `match` del filtro e il `CASE` dell'ordinamento. La dashboard di S6 ne avrebbe
 * scritta una terza, e il progetto ha già pagato due volte la duplicazione. Ora
 * vivono in `Strumento::scopeConStato()` / `scopeOrdinaPerStato()`, che leggono
 * entrambe da `fontiArancione()`.
 *
 * ⚠️ **L'oracolo di questo file è `statoSemaforoEffettivo()`**, cioè
 * un'implementazione **già esistente e indipendente** — il calcolo per-model che
 * la scheda usa da S3. Non si ri-enuncia qui la regola che si sta controllando:
 * sarebbe un meta-test che verifica sé stesso, la forma di falso verde che
 * questo progetto ha già incontrato.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    /**
     * Il parco di prova: **tutti** i modi in cui uno stato può nascere, in una
     * sola fixture. I casi forzati ci sono perché sono quelli su cui la regola
     * sbaglia in silenzio — «il forzato vince» è la clausola che un refactor
     * dimentica per prima.
     */
    $this->popola = function (): array {
        $macchina = fn (string $nome) => Strumento::factory()->forNode($this->dept)->create(['nome' => $nome]);

        $nuda = $macchina('Nuda');

        $daIntervento = $macchina('Scaduta da intervento');
        Intervento::factory()->forStrumento($daIntervento)->scaduto()->create();

        $imminente = $macchina('Intervento imminente');
        Intervento::factory()->forStrumento($imminente)->create(['data_scadenza' => today()->addDays(3)->toDateString()]);

        $lontano = $macchina('Intervento lontano');
        Intervento::factory()->forStrumento($lontano)->create(['data_scadenza' => today()->addMonths(8)->toDateString()]);

        // 🔴 I due casi al CONFINE della soglia, senza cui la fixture non
        // esercita la riga che decide chi è arancione. Verificato mutando: con
        // scadenze a 3 giorni e a 8 mesi, togliere il `+ 1` da
        // `Intervento::scopeApertiEntroSoglia()` non rompeva niente qui.
        // La soglia si legge da `Semaforo`, che è dove vive: qui è la posizione
        // di un dato di prova, non la regola sotto esame.
        $alConfine = $macchina('Intervento al confine');
        Intervento::factory()->forStrumento($alConfine)
            ->create(['data_scadenza' => today()->addDays(Semaforo::giorniImminente())->toDateString()]);

        $unGiornoOltre = $macchina('Intervento un giorno oltre');
        Intervento::factory()->forStrumento($unGiornoOltre)
            ->create(['data_scadenza' => today()->addDays(Semaforo::giorniImminente() + 1)->toDateString()]);

        // Lo stesso confine sulla garanzia macchina, che ha il proprio scope.
        $garanziaAlConfine = $macchina('Garanzia al confine');
        Garanzia::factory()
            ->scadenzaDichiarata(today()->addDays(Semaforo::giorniImminente())->toDateString())
            ->forStrumento($garanziaAlConfine)->create();

        $daGaranziaMacchina = $macchina('Garanzia macchina in scadenza');
        // ⚠️ Scadenza DICHIARATA e non `durata_mesi`: «11 mesi fa + 12» cade a
        // 31 giorni da oggi, cioè un giorno FUORI soglia — e questa macchina
        // nasceva verde mentre il suo nome prometteva arancione. La differenziale
        // restava comunque verde, perché le due implementazioni concordavano
        // sulla risposta sbagliata: da qui il guardiano della fixture in fondo.
        Garanzia::factory()
            ->scadenzaDichiarata(today()->addDays(10)->toDateString())
            ->forStrumento($daGaranziaMacchina)->create();

        // Terza fonte (ADR-020): un pezzo MONTATO con garanzia in scadenza.
        $daGaranziaRicambio = $macchina('Garanzia ricambio in scadenza');
        $ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Lampada']);
        $utilizzo = RicambioUtilizzo::factory()
            ->forStrumento($daGaranziaRicambio)
            ->forRicambio($ricambio)
            ->create(['data' => today()->subMonths(11)->toDateString()]);
        Garanzia::factory()
            ->scadenzaDichiarata(today()->addDays(10)->toDateString())
            ->forRicambio($utilizzo)->create();

        // 🔴 **Il confine della TERZA fonte, e quello della garanzia macchina.**
        // Senza la riga «oltre», togliere `->entroSoglia()` dalla fonte dei
        // pezzi montati — cioè rendere arancione ogni macchina con un ricambio
        // garantito, anche a tre anni da oggi — lasciava verde l'INTERA suite:
        // 1337 test, zero rossi. Misurato. Una fonte «accesa» non basta: serve
        // che una esista e sia SPENTA, o il confine non è provato da nessuno.
        $ricambioAlConfine = $macchina('Ricambio al confine');
        $utilizzoAlConfine = RicambioUtilizzo::factory()
            ->forStrumento($ricambioAlConfine)->forRicambio($ricambio)
            ->create(['data' => today()->subMonth()->toDateString()]);
        Garanzia::factory()
            ->scadenzaDichiarata(today()->addDays(Semaforo::giorniImminente())->toDateString())
            ->forRicambio($utilizzoAlConfine)->create();

        $ricambioOltre = $macchina('Ricambio un giorno oltre');
        $utilizzoOltre = RicambioUtilizzo::factory()
            ->forStrumento($ricambioOltre)->forRicambio($ricambio)
            ->create(['data' => today()->subMonth()->toDateString()]);
        Garanzia::factory()
            ->scadenzaDichiarata(today()->addDays(Semaforo::giorniImminente() + 1)->toDateString())
            ->forRicambio($utilizzoOltre)->create();

        $garanziaOltre = $macchina('Garanzia macchina un giorno oltre');
        Garanzia::factory()
            ->scadenzaDichiarata(today()->addDays(Semaforo::giorniImminente() + 1)->toDateString())
            ->forStrumento($garanziaOltre)->create();

        // 🔴 Forzato VERDE con uno scaduto sotto: il caso che distingue «stato
        // effettivo» da «stato calcolato». Deve contare come verde.
        $forzatoVerde = $macchina('Forzata verde con scaduto');
        Intervento::factory()->forStrumento($forzatoVerde)->scaduto()->create();
        $forzatoVerde->forzaSemaforo(StatoSemaforo::Verde);

        // Forzato ARANCIONE senza nessuna fonte accesa: il simmetrico.
        $forzatoArancione = $macchina('Forzata arancione senza motivi');
        $forzatoArancione->forzaSemaforo(StatoSemaforo::Arancione);

        // Il rosso esiste solo così.
        $forzatoRosso = $macchina('Forzata rossa');
        $forzatoRosso->forzaSemaforo(StatoSemaforo::Rosso, 'Non idonea');

        return compact(
            'nuda', 'daIntervento', 'imminente', 'lontano', 'daGaranziaMacchina',
            'alConfine', 'unGiornoOltre', 'garanziaAlConfine', 'garanziaOltre',
            'ricambioAlConfine', 'ricambioOltre',
            'daGaranziaRicambio', 'forzatoVerde', 'forzatoArancione', 'forzatoRosso'
        );
    };
});

// ─── Il guardiano della fixture ──────────────────────────────────────────────

it('builds a fixture where every machine really is what its name says', function () {
    // 🔴 **Questo test esiste per una svista vera, colta mutando.** Due macchine
    // — «Garanzia macchina in scadenza» e «Garanzia ricambio in scadenza» —
    // nascevano VERDI: la garanzia era costruita con `data_inizio` a 11 mesi fa
    // e 12 mesi di durata, cioè una scadenza a 31 giorni, un giorno oltre la
    // soglia. La differenziale non se ne accorgeva, perché SQL e calcolo
    // per-model concordavano sulla risposta sbagliata — ed è precisamente la
    // forma di falso verde in cui il numero dei casi copre l'assenza del caso.
    //
    // Le fonti dell'arancione sono TRE, e questo è il solo posto che verifica
    // che siano tutte e tre accese nella fixture.
    $parco = ($this->popola)();
    $this->actingAs($this->admin);

    $atteso = [
        'nuda' => StatoSemaforo::Verde,
        'daIntervento' => StatoSemaforo::Arancione,
        'imminente' => StatoSemaforo::Arancione,
        'lontano' => StatoSemaforo::Verde,
        'alConfine' => StatoSemaforo::Arancione,
        'unGiornoOltre' => StatoSemaforo::Verde,
        'garanziaAlConfine' => StatoSemaforo::Arancione,
        'daGaranziaMacchina' => StatoSemaforo::Arancione,
        'daGaranziaRicambio' => StatoSemaforo::Arancione,
        'ricambioAlConfine' => StatoSemaforo::Arancione,
        'ricambioOltre' => StatoSemaforo::Verde,
        'garanziaOltre' => StatoSemaforo::Verde,
        'forzatoVerde' => StatoSemaforo::Verde,
        'forzatoArancione' => StatoSemaforo::Arancione,
        'forzatoRosso' => StatoSemaforo::Rosso,
    ];

    foreach ($atteso as $chiave => $stato) {
        expect($parco[$chiave]->fresh()->statoSemaforoEffettivo())
            ->toBe($stato, "«{$parco[$chiave]->nome}» non è {$stato->value}");
    }

    // ⚠️ Si itera su `$atteso`, quindi una macchina aggiunta a `popola()` e
    // dimenticata qui verrebbe **saltata in silenzio** — proprio la svista che
    // questo guardiano esiste per impedire.
    expect($parco)->toHaveCount(count($atteso));

    // E la terza fonte accende davvero da sola: senza questo, un ricambio
    // scollegato passerebbe per «arancione» grazie a un'altra causa.
    expect($parco['daGaranziaRicambio']->diagnosiSemaforo()->motivi)->toHaveCount(1);
});

// ─── La differenziale: SQL contro calcolo per-model ──────────────────────────

it('names the same machines as the per-model rule, state by state', function () {
    ($this->popola)();
    $this->actingAs($this->admin);

    foreach (StatoSemaforo::cases() as $stato) {
        // L'oracolo: l'implementazione per-model, interrogata riga per riga.
        $attesi = Strumento::all()
            ->filter(fn (Strumento $s) => $s->statoSemaforoEffettivo() === $stato)
            ->pluck('id')->all();

        $trovati = Strumento::query()->conStato($stato)->pluck('id')->all();

        // ⚠️ Un oracolo vuoto uguale a un risultato vuoto è verde e non prova
        // niente: ogni stato deve avere almeno una macchina.
        expect($attesi)->not->toBeEmpty("Fixture senza nessuna macchina «{$stato->value}»")
            ->and($trovati)->toEqualCanonicalizing($attesi, "Disallineamento sullo stato «{$stato->value}»");
    }
});

it('puts every machine in exactly one of the three states', function () {
    $parco = ($this->popola)();
    $this->actingAs($this->admin);

    $per = [];
    foreach (StatoSemaforo::cases() as $stato) {
        $per[$stato->value] = Strumento::query()->conStato($stato)->pluck('id')->all();
    }

    $tutti = array_merge(...array_values($per));

    // La partizione è la proprietà su cui la dashboard poggia i propri numeri:
    // una macchina che sparisce da tutti e tre gli insiemi è invisibile a
    // qualunque asserzione fatta su un insieme solo.
    expect(count($tutti))->toBe(Strumento::count())
        ->and(array_unique($tutti))->toHaveCount(count($tutti))
        ->and($per[StatoSemaforo::Verde->value])->toContain($parco['forzatoVerde']->id)
        ->and($per[StatoSemaforo::Arancione->value])->not->toContain($parco['forzatoVerde']->id);
});

// ─── Filtro e ordinamento nella STESSA richiesta ─────────────────────────────

it('keeps filter and sort in agreement across pages, losing nothing between them', function () {
    ($this->popola)();

    // ⚠️ **La prima stesura di questo test non provava ciò che diceva**, e
    // l'ho misurato: confrontava due *insiemi* di id con
    // `toEqualCanonicalizing`, quindi non toccava né l'ordine né la
    // paginazione. Ciò che conta quando filtro e ordinamento girano insieme è
    // che **la paginazione non perda né duplichi righe**.
    //
    // ⚠️ E serve abbastanza parco perché una seconda pagina esista: `perPage`
    // è ri-validato contro `[20, 50, 100]` a ogni render, quindi non si può
    // rimpicciolire la pagina — si allunga l'elenco. Con la sola fixture le
    // pagine erano una, e l'asserzione sulla paginazione non aveva nulla da
    // dire.
    $dip = $this->dept;
    foreach (range(1, 16) as $n) {
        $arancione = Strumento::factory()->forNode($dip)->create(['nome' => "Riempitivo arancione {$n}"]);
        Intervento::factory()->forStrumento($arancione)->scaduto()->create();
        Strumento::factory()->forNode($dip)->create(['nome' => "Riempitivo verde {$n}"]);
    }

    $ordinale = fn (Strumento $s) => match ($s->statoSemaforoEffettivo()) {
        StatoSemaforo::Verde => 0,
        StatoSemaforo::Arancione => 1,
        StatoSemaforo::Rosso => 2,
    };

    $raccogli = function (?string $stato) {
        $tutte = collect();

        foreach (range(1, 4) as $numero) {
            $righe = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
                ->set('stato', $stato)
                ->set('sortBy', 'stato')
                ->set('sortDir', 'asc')
                ->call('setPage', $numero)
                ->viewData('strumenti');

            $tutte = $tutte->concat($righe->all());
        }

        return $tutte;
    };

    // 1. Filtrato per arancione, su più di una pagina: l'unione è esattamente
    //    l'insieme che lo scope nomina, senza doppioni e senza buchi.
    $arancioni = Strumento::query()->conStato(StatoSemaforo::Arancione)->pluck('id')->all();
    $raccolti = $raccogli(StatoSemaforo::Arancione->value)->pluck('id')->all();

    expect(count($arancioni))->toBeGreaterThan(20, 'Serve più di una pagina, o il test non paga la paginazione')
        ->and($raccolti)->toEqualCanonicalizing($arancioni)
        ->and(array_unique($raccolti))->toHaveCount(count($raccolti));

    // 2. Senza filtro, ordinato per stato: l'ordinale non decresce MAI, nemmeno
    //    passando da una pagina all'altra. È l'asserzione che la stesura
    //    precedente non faceva.
    $precedente = -1;

    foreach ($raccogli(null) as $strumento) {
        $corrente = $ordinale($strumento);
        expect($corrente)->toBeGreaterThanOrEqual(
            $precedente,
            "«{$strumento->nome}» arriva dopo uno stato più grave"
        );
        $precedente = $corrente;
    }

    expect($raccogli(null)->pluck('id')->unique())->toHaveCount(Strumento::count());
});

it('orders green before orange before red, with the forced state winning', function () {
    $parco = ($this->popola)();
    $this->actingAs($this->admin);

    $ordinati = Strumento::query()->ordinaPerStato('asc')->orderBy('id')->get();

    $ordinale = fn (Strumento $s) => match ($s->statoSemaforoEffettivo()) {
        StatoSemaforo::Verde => 0,
        StatoSemaforo::Arancione => 1,
        StatoSemaforo::Rosso => 2,
    };

    // La sequenza degli ordinali non deve mai decrescere: è l'unico modo di
    // dire «l'ordinamento concorda con lo stato effettivo» senza riscrivere la
    // regola dentro il test.
    $precedente = -1;
    foreach ($ordinati as $strumento) {
        $corrente = $ordinale($strumento);
        expect($corrente)->toBeGreaterThanOrEqual($precedente, "Fuori ordine su «{$strumento->nome}»");
        $precedente = $corrente;
    }

    expect($ordinati->last()->id)->toBe($parco['forzatoRosso']->id);
});

// ─── Obsolescenza: una soglia per Ente, non una per pagina ───────────────────

it('follows the soglia of each Ente, not the one of whoever is looking', function () {
    // 🔴 Il difetto che il refactor corregge, e che nessun test poteva vedere
    // finché tutti giravano su un Ente solo: il filtro leggeva UNA soglia
    // (quella dell'utente corrente, 10 per un Tecnico esterno che non ha Ente)
    // mentre il badge ⏳ della riga accanto legge quella dell'Ente della riga.
    $this->ente->update(['soglia_obsolescenza_anni' => 5]);

    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $enteB->update(['soglia_obsolescenza_anni' => 20]);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();

    // Stessa età, esiti opposti: obsoleta di qua, non di là.
    $seiAnniA = Strumento::factory()->forNode($this->dept)->create([
        'nome' => 'Sei anni in A', 'data_installazione' => today()->subYears(6)->toDateString(),
    ]);
    $seiAnniB = Strumento::factory()->forNode($deptB)->create([
        'nome' => 'Sei anni in B', 'data_installazione' => today()->subYears(6)->toDateString(),
    ]);
    Strumento::factory()->forNode($deptB)->create([
        'nome' => 'Ventun anni in B', 'data_installazione' => today()->subYears(21)->toDateString(),
    ]);
    Strumento::factory()->forNode($this->dept)->create([
        'nome' => 'Senza data', 'data_installazione' => null,
    ]);

    // Un Tecnico esterno vede entrambi gli Enti e non ne ha uno proprio: è la
    // figura su cui «una soglia sola» sbagliava.
    $tecnico = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');
    $tecnico->portafoglioClienti()->attach([$this->ente->id, $enteB->id]);
    $this->actingAs($tecnico->fresh());

    $attesi = Strumento::all()->filter(fn (Strumento $s) => $s->isObsoleto())->pluck('id')->all();
    $trovati = Strumento::query()->obsoleti()->pluck('id')->all();

    expect($trovati)->toEqualCanonicalizing($attesi)
        ->and($trovati)->toContain($seiAnniA->id)      // soglia 5 → obsoleta
        ->and($trovati)->not->toContain($seiAnniB->id) // soglia 20 → no
        ->and($trovati)->not->toBeEmpty();
});

it('stays on the inclusive boundary the ADR describes', function () {
    $this->ente->update(['soglia_obsolescenza_anni' => 5]);
    $this->actingAs($this->admin);

    $esatto = Strumento::factory()->forNode($this->dept)->create([
        'nome' => 'Esatti cinque anni', 'data_installazione' => today()->subYears(5)->toDateString(),
    ]);
    $unGiornoSotto = Strumento::factory()->forNode($this->dept)->create([
        'nome' => 'Un giorno sotto', 'data_installazione' => today()->subYears(5)->addDay()->toDateString(),
    ]);

    $trovati = Strumento::query()->obsoleti()->pluck('id')->all();

    // `>=` dell'ADR: installato esattamente N anni fa oggi è GIÀ obsoleto.
    expect($trovati)->toContain($esatto->id)
        ->and($trovati)->not->toContain($unGiornoSotto->id)
        ->and($esatto->fresh()->isObsoleto())->toBeTrue()
        ->and($unGiornoSotto->fresh()->isObsoleto())->toBeFalse();
});

it('falls back to the default soglia when the Ente is no longer readable', function () {
    // 🔴 **Il caso in cui le due letture divergevano in silenzio.**
    // `sogliaObsolescenza()` passa dalla relazione `tenant()`, che ha i global
    // scope: un Ente cestinato torna `null` e il per-model ricade su 10 —
    // comportamento congelato da `ObsolescenzaTest`. La forma SQL, invece, non
    // trovava la riga in `unita_organizzativa` e quel `tenant_id` restava
    // **senza alcun ramo OR**: la macchina spariva dal filtro mentre
    // `isObsoleto()` continuava a dichiararla obsoleta.
    //
    // La soglia è 50 apposta: senza il fallback la macchina non è obsoleta per
    // nessuno dei due, e il test sarebbe verde per la ragione sbagliata.
    $this->ente->update(['soglia_obsolescenza_anni' => 50]);

    $vecchia = Strumento::factory()->forNode($this->dept)->create([
        'nome' => 'Trentenne', 'data_installazione' => today()->subYears(30)->toDateString(),
    ]);

    $this->ente->delete();
    $this->actingAs($this->admin);

    // L'oracolo: il per-model, che sul tenant illeggibile ricade su 10.
    expect($vecchia->fresh()->tenant)->toBeNull()
        ->and($vecchia->fresh()->sogliaObsolescenza())->toBe(10)
        ->and($vecchia->fresh()->isObsoleto())->toBeTrue();

    expect(Strumento::query()->obsoleti()->pluck('id')->all())->toContain($vecchia->id);
});

it('finds nothing obsolete for someone who sees no Ente at all', function () {
    // Fail-closed: un Tecnico senza portafoglio né assegnazioni non vede
    // strumenti, quindi non ne vede di obsoleti.
    //
    // ⚠️ **Questo test NON è il sentinella del ramo di salvaguardia** dentro
    // `scopeObsoleti()`, e va detto invece di lasciarlo credere: qui il parco
    // visibile è già vuoto, quindi «passerebbe tutto» non ha nulla da far
    // passare. Misurato togliendo quel ramo: resta verde. Ciò che prova è
    // l'altra metà, cioè che il fail-closed di ADR-018 arriva fino a qui.
    Strumento::factory()->forNode($this->dept)->create([
        'data_installazione' => today()->subYears(30)->toDateString(),
    ]);

    $tecnico = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');
    $this->actingAs($tecnico->fresh());

    expect(Strumento::query()->obsoleti()->count())->toBe(0)
        ->and(Strumento::query()->count())->toBe(0);
});
