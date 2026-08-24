<?php

use App\Models\Errore;
use App\Models\OccorrenzaErrore;
use App\Support\Retention;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * 🔴 La retention **applicata** (S6, blocco 7 — 🔗 `docs/Architettura/Error
 * Tracker Interno (piano).md`; Privacy §3, righe T4 e T8).
 *
 * Il difetto che questo file esiste per impedire ha già un nome nel progetto:
 * **T6**. `config/activitylog.php` dichiara `clean_after_days => 365` e
 * `activitylog:clean` non è schedulato — retention scritta, mai avvenuta. I due
 * meta-test qui sotto sono una coppia e nessuno dei due basta da solo:
 *
 * - il primo lega i model alla costante (una `prunable()` dimenticata è rossa);
 * - il secondo legge lo **scheduler vero** e pretende che quella costante ci
 *   finisca dentro. Senza di lui il primo proverebbe soltanto che una lista è
 *   coerente con sé stessa — cioè T6 in persona, con un meta-test verde sopra.
 *
 * ⚠️ **Le cifre degli orizzonti sono scritte a mano qui**, non lette dalle
 * costanti dei model: un test che rilegge la costante che sta verificando resta
 * verde a qualunque valore la si sposti, e i confini sono proprio ciò che la
 * prova di mutazione muove di un giorno.
 */
function modelliPrunable(): array
{
    $trovati = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $classe = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

        if (! class_exists($classe) || ! is_subclass_of($classe, Model::class)) {
            continue;
        }
        if (! in_array(Prunable::class, class_uses_recursive($classe), true)) {
            continue;
        }

        $trovati[] = $classe;
    }

    sort($trovati);

    return $trovati;
}

it('finds at least one prunable model to check', function () {
    // Il glob è la sola cosa che tiene onesto il meta-test qui sotto: se un
    // giorno smettesse di trovare i file (una sottocartella, un rename), il
    // confronto diventerebbe `[] === []` e passerebbe sempre.
    expect(modelliPrunable())->not->toBeEmpty();
});

it('names every prunable model, so a retention cannot be declared and left inert', function () {
    $dichiarati = Retention::MODELLI;
    sort($dichiarati);

    expect(modelliPrunable())->toBe($dichiarati);
});

it('actually schedules the pruning, instead of only declaring it', function () {
    // 🔴 **Il test che rende vero quello sopra.** Si legge lo scheduler
    // dell'applicazione — lo stesso oggetto che `schedule:list` stampa — e non
    // il file di rotte come testo: ciò che conta non è che una riga sia scritta
    // da qualche parte, è che un comando pianificato **nomini** questi modelli.
    $potature = collect(app(Schedule::class)->events())
        ->filter(fn ($evento) => str_contains($evento->command ?? '', 'model:prune'));

    expect($potature)->toHaveCount(1);

    $comando = $potature->first()->command;

    // ⚠️ Non `toContain(...Retention::MODELLI)`: `toContain()` è **variadico**,
    // quindi con la costante svuotata sarebbe una chiamata a zero argomenti,
    // cioè verde per vuoto — proprio la mutazione che va vista. Il ciclo con il
    // conteggio davanti non ha quella scappatoia.
    expect(Retention::MODELLI)->not->toBeEmpty();

    foreach (Retention::MODELLI as $modello) {
        // 🔴 **Sulla FORMA dell'argomento, non sulla presenza del nome.** La
        // prima stesura cercava il FQCN come sottostringa, e due mutazioni da
        // una parola sola producevano una potatura **schedulata e inerte**
        // lasciando tutti e dieci i test verdi — verificato:
        //
        //   ['--model' => Retention::MODELLI, '--pretend' => true]
        //   ['--except' => Retention::MODELLI]
        //
        // Nel primo caso il cron gira ogni notte e non cancella niente («una
        // prova a secco dimenticata in produzione» è un incidente plausibile,
        // non teorico); nel secondo pota **tutto tranne** ciò che deve potare.
        // In entrambi il registro dei trattamenti continuerebbe a dichiarare la
        // conservazione «attiva».
        //
        // È il difetto T6 in persona — «una retention dichiarata e inerte» —
        // rientrato dalla finestra: spostato dal «comando non schedulato» al
        // «comando schedulato che non fa nulla». Questo file esiste per non
        // farlo succedere, quindi qui si guarda **come** i modelli sono passati.
        expect($comando)->toContain("--model='{$modello}'");
    }

    expect($comando)->not->toContain('--pretend')
        ->and($comando)->not->toContain('--except');
});

it('does not contradict itself about the retention it has not applied', function () {
    // ⚠️ **Dichiarata NON falsificabile, e va detto invece che far finta.** La
    // riga `Activity::class` in `RETENTION_NON_APPLICATA` documenta che il
    // registro di audit una rotazione non ce l'ha (T6, aperta col legale): il
    // meta-test qui sopra non potrebbe accorgersene neanche volendo, perché
    // globba `app/Models/*.php` e `Activity` vive in `vendor/spatie`.
    //
    // Ciò che invece si può ancora provare è che le due costanti non si
    // contraddicano — nessuna classe può essere insieme «potata» e «non potata»
    // — e che la dichiarazione non sia già scaduta: il giorno in cui qualcuno
    // rendesse `Activity` potabile, questa riga andrebbe tolta.
    expect(Retention::RETENTION_NON_APPLICATA)->not->toBeEmpty();

    foreach (Retention::RETENTION_NON_APPLICATA as $classe) {
        expect(Retention::MODELLI)->not->toContain($classe);
        expect(class_uses_recursive($classe))->not->toContain(Prunable::class);
    }
});

it('never prunes an ignored issue', function () {
    // 🔴 **L'unico interruttore di silenzio del tracker.** Potarlo lo
    // resusciterebbe: al prossimo avvenimento il `firstOrCreate` non troverebbe
    // la riga, ne creerebbe una nuova `aperto`, e l'alert che qualcuno ha
    // chiesto di non ricevere più tornerebbe da sé, a scadenza.
    //
    // La data è oltre **tutti** gli orizzonti — più vecchia dei 180 giorni delle
    // aperte, quindi non è un caso al limite: se `ignorato` cadesse in uno dei
    // due rami, cadrebbe in questo.
    $zittita = issueErrore([
        'stato' => 'ignorato',
        'ultima_occorrenza_at' => now()->subYears(3),
        'prima_occorrenza_at' => now()->subYears(3),
    ]);

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    expect(Errore::find($zittita->id))->not->toBeNull()
        ->and(Errore::find($zittita->id)->stato)->toBe('ignorato');
});

it('prunes a resolved issue only past ninety days since it last happened', function () {
    $this->freezeTime();

    // Il confine appartiene a chi resta: a novanta giorni esatti la issue vive,
    // un minuto oltre no.
    $sulConfine = issueErrore(['stato' => 'risolto', 'ultima_occorrenza_at' => now()->subDays(90)]);
    $oltre = issueErrore(['stato' => 'risolto', 'ultima_occorrenza_at' => now()->subDays(90)->subMinute()]);

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    expect(Errore::find($sulConfine->id))->not->toBeNull()
        ->and(Errore::find($oltre->id))->toBeNull();
});

it('keeps an open issue twice as long, because it is still work to do', function () {
    $this->freezeTime();

    // Gli stessi novanta giorni che si portano via una issue chiusa non toccano
    // una aperta: è il ramo che distingue i due orizzonti, e senza questa riga
    // 90 e 180 sarebbero indistinguibili.
    $chiusa = issueErrore(['stato' => 'risolto', 'ultima_occorrenza_at' => now()->subDays(100)]);
    $apertaRecente = issueErrore(['stato' => 'aperto', 'ultima_occorrenza_at' => now()->subDays(100)]);

    $sulConfine = issueErrore(['stato' => 'aperto', 'ultima_occorrenza_at' => now()->subDays(180)]);
    $oltre = issueErrore(['stato' => 'aperto', 'ultima_occorrenza_at' => now()->subDays(180)->subMinute()]);

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    expect(Errore::find($chiusa->id))->toBeNull()
        ->and(Errore::find($apertaRecente->id))->not->toBeNull()
        ->and(Errore::find($sulConfine->id))->not->toBeNull()
        ->and(Errore::find($oltre->id))->toBeNull();
});

it('prunes an occurrence past ninety days, whatever its issue is doing', function () {
    $this->freezeTime();

    // La issue è viva e per giunta `ignorato`, cioè lo stato che non si pota
    // mai: le sue prove hanno comunque un orizzonte proprio, ed è il più corto
    // delle due tabelle perché è lì che stanno i dati personali (T8).
    $issue = issueErrore(['stato' => 'ignorato', 'ultima_occorrenza_at' => now()]);

    $sulConfine = occorrenza($issue, now()->subDays(90));
    $oltre = occorrenza($issue, now()->subDays(90)->subMinute());

    $this->artisan('model:prune', ['--model' => [OccorrenzaErrore::class]])->assertSuccessful();

    expect(OccorrenzaErrore::find($sulConfine->id))->not->toBeNull()
        ->and(OccorrenzaErrore::find($oltre->id))->toBeNull()
        ->and(Errore::find($issue->id))->not->toBeNull();
});

it('takes the evidence away together with its issue, even yesterday evidence', function () {
    $this->freezeTime();

    // 🔴 Il verso che conta: una prova non sopravvive mai alla propria issue. Non
    // lo fa questa query — lo fa il `cascadeOnDelete` dello schema, e senza
    // questo test nessuno se ne accorgerebbe il giorno in cui la migration
    // cambiasse in `nullOnDelete`.
    $potata = issueErrore(['stato' => 'risolto', 'ultima_occorrenza_at' => now()->subDays(200)]);
    $viva = issueErrore(['stato' => 'aperto', 'ultima_occorrenza_at' => now()]);

    $orfana = occorrenza($potata, now()->subMinute());
    $superstite = occorrenza($viva, now()->subMinute());

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    expect(OccorrenzaErrore::find($orfana->id))->toBeNull()
        ->and(OccorrenzaErrore::find($superstite->id))->not->toBeNull();
});

it('reports a failure while pruning without feeding on itself', function () {
    // 🔴 **La ragione per cui non c'è una guardia di rientranza qui.**
    // `Prunable::pruneAll()` avvolge ogni riga in un `catch (Throwable)` che
    // chiama `report()`, cioè — per questo model — il tracker stesso: potare gli
    // errori può **scrivere** negli errori, e il flag di `CatturaErrori` non
    // copre il caso (quel `report()` avviene dopo che `cattura()` è uscita).
    //
    // Si è deciso di lasciarlo scrivere. Questo test è ciò che rende la
    // decisione difendibile: il giro **si chiude**. La riga nata dal guasto ha
    // `ultima_occorrenza_at = now()`, quindi è fuori dalla query di potatura, e
    // `chunkById` avanza per id crescente — due righe alla fine, non N.
    $this->freezeTime();

    $vecchia = issueErrore(['stato' => 'risolto', 'ultima_occorrenza_at' => now()->subDays(200)]);

    Errore::deleting(function () {
        throw new RuntimeException('la potatura si è rotta');
    });

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    Errore::flushEventListeners();

    expect(Errore::find($vecchia->id))->not->toBeNull('la delete è fallita: la riga resta')
        ->and(Errore::count())->toBe(2);

    // E la riga nata dal guasto è viva: è la prova che il giro non si
    // autoalimenta a ogni passata.
    $nata = Errore::where('id', '!=', $vecchia->id)->sole();

    expect($nata->stato)->toBe('aperto')
        ->and($nata->classe)->toBe(RuntimeException::class);
});

/**
 * Un'occorrenza di prova, avvenuta quando si dice.
 *
 * ⚠️ Vive qui e non in `tests/Pest.php` perché la usa questa sola suite: la
 * disciplina che ha portato là `issueErrore()` è la reciproca — ci si sale
 * quando una seconda suite ne ha bisogno.
 */
function occorrenza(Errore $errore, DateTimeInterface $quando): OccorrenzaErrore
{
    return OccorrenzaErrore::create([
        'errore_id' => $errore->getKey(),
        'messaggio' => 'Qualcosa non ha funzionato',
        'stack_trace' => "#0 app/Support/Prova.php(42)\n",
        'percorso' => '/strumenti',
        'metodo' => 'GET',
        'contesto' => 'http',
        'avvenuta_at' => $quando,
    ]);
}

it('leaves a state it has never heard of out of the pruning', function () {
    // 🔴 **La whitelist è fail-closed, e questo test è ciò che lo rende vero.**
    // `prunable()` nomina `risolto` e `aperto` invece di escludere `ignorato`:
    // semanticamente identico **oggi**, ma il verso opposto — riscriverlo come
    // «tutto tranne ignorato» — farebbe cadere nella potatura **ogni stato
    // futuro** per difetto, e la disciplina che il docblock rivendica era
    // invisibile alla suite (verificato: la riscrittura a blacklist lasciava
    // dieci test verdi).
    //
    // Uno stato nuovo deve sopravvivere finché qualcuno non decide che orizzonte
    // dargli. Il verso giusto in cui sbagliare è tenere un dato di troppo, non
    // cancellarne uno che nessuno ha ancora classificato.
    $sospeso = issueErrore(['ultima_occorrenza_at' => now()->subYears(3)]);
    $sospeso->forceFill(['stato' => 'sospeso'])->save();

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    expect(Errore::whereKey($sospeso->id)->exists())->toBeTrue();
});
