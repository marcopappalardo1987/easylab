<?php

use App\Models\Errore;
use App\Models\OccorrenzaErrore;
use App\Support\Errori\CatturaErrori;
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

/*
|--------------------------------------------------------------------------
| 🔴 L'oscuramento del messaggio a 180 giorni (24 Ago 2026)
|--------------------------------------------------------------------------
|
| Una issue `ignorato` non si pota mai, e il suo `messaggio` è **interpolato**
| («Utente 42 non trovato»): senza questo gesto resterebbe a database senza
| scadenza. Il rimedio non cancella la riga — classe, file, riga, impronta e
| contatore non riguardano nessuno e servono a riconoscere l'errore — svuota il
| solo campo che può portare un dato riferito a una persona.
|
| ⚠️ **E passa dallo stesso `model:prune`**, non da un comando nuovo: è la
| lezione del blocco 7, cioè il difetto T6 (una misura dichiarata e inerte).
| Tutti i test qui sotto lo invocano da lì, non chiamando il metodo a mano.
*/

it('blanks the message of an issue that outlives the pruning, and keeps everything else', function () {
    // 🔴 Il caso che ha motivato la decisione: `ignorato` non si pota **mai**,
    // quindi senza oscuramento questo messaggio vivrebbe per sempre.
    $zittita = issueErrore([
        'stato' => 'ignorato',
        'messaggio' => 'Utente 42 non trovato',
        'classe' => 'App\\Exceptions\\Boom',
        'file' => 'app/Support/Prova.php',
        'riga' => 42,
        'occorrenze' => 1234,
        'ultima_occorrenza_at' => now()->subYears(3),
    ]);

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    $dopo = Errore::find($zittita->id);

    // La riga resta, e resta **riconoscibile**: è la metà della decisione che
    // un `delete()` avrebbe buttato via insieme al dato personale.
    expect($dopo)->not->toBeNull()
        ->and($dopo->stato)->toBe('ignorato')
        ->and($dopo->classe)->toBe('App\\Exceptions\\Boom')
        ->and($dopo->file)->toBe('app/Support/Prova.php')
        ->and($dopo->riga)->toBe(42)
        ->and($dopo->impronta)->toBe($zittita->impronta)
        ->and($dopo->occorrenze)->toBe(1234);

    // E il messaggio non c'è più.
    expect($dopo->messaggio)->not->toContain('Utente 42')
        ->and($dopo->messaggio)->toBe(Errore::MESSAGGIO_OSCURATO);
});

it('tells a blanked message apart from an empty one', function () {
    $this->freezeTime();

    // 🔴 **Il vuoto esiste davvero**: `CatturaErrori` scrive `''` quando
    // l'eccezione non porta messaggio. Svuotare a `''` renderebbe le due cose
    // indistinguibili, e chi legge la scheda non saprebbe se il messaggio non
    // c'è mai stato o se gli è stato tolto — che è la differenza fra «non c'è
    // niente da cercare» e «la storia è più lunga di così».
    $natoMuto = issueErrore(['stato' => 'ignorato', 'messaggio' => '', 'ultima_occorrenza_at' => now()]);
    $svuotato = issueErrore(['stato' => 'ignorato', 'messaggio' => '', 'ultima_occorrenza_at' => now()->subDays(200)]);

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    expect(Errore::find($natoMuto->id)->messaggio)->toBe('')
        ->and(Errore::find($svuotato->id)->messaggio)->toBe(Errore::MESSAGGIO_OSCURATO)
        ->and(Errore::find($svuotato->id)->messaggio)->not->toBe(Errore::find($natoMuto->id)->messaggio);
});

it('keeps the message until the hundred-and-eightieth day, and not one minute more', function () {
    $this->freezeTime();

    // Il confine appartiene a chi resta, come per la potatura: `<` e non `<=`.
    // ⚠️ Su SQLite questi confronti sono lessicografici su stringhe e su
    // Postgres sono date vere: il file si rilegge su `easylab_test`.
    $sulConfine = issueErrore(['stato' => 'ignorato', 'ultima_occorrenza_at' => now()->subDays(180)]);
    $oltre = issueErrore(['stato' => 'ignorato', 'ultima_occorrenza_at' => now()->subDays(180)->subMinute()]);

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    expect(Errore::find($sulConfine->id)->messaggio)->toBe('Qualcosa non ha funzionato')
        ->and(Errore::find($oltre->id)->messaggio)->toBe(Errore::MESSAGGIO_OSCURATO);
});

it('blanks whatever the state, because a recurring issue is never pruned either', function () {
    $this->freezeTime();

    // 🔴 **Il rischio non è dove sembra.** Sarebbe comodo restringere
    // l'oscuramento a `ignorato` — «è l'unico stato che non si pota» — ma
    // `ultima_occorrenza_at` si rinfresca a **ogni** avvenimento, quindi una
    // issue che continua a ripetersi non viene mai potata comunque, senza che
    // nessuno tocchi niente. Un filtro per stato avrebbe coperto la casella
    // rara lasciando aperta la frequente.
    //
    // Lo stato `sospeso` — che la potatura non conosce e per fail-closed non
    // tocca — è il modo di provarlo su una riga che sopravvive alla passata:
    // una `aperto` oltre i 180 giorni verrebbe oscurata e poi cancellata nella
    // stessa esecuzione, e non ci sarebbe niente da guardare.
    $sconosciuta = issueErrore(['ultima_occorrenza_at' => now()->subDays(200)]);
    $sconosciuta->forceFill(['stato' => 'sospeso'])->save();

    $recente = issueErrore(['stato' => 'aperto', 'ultima_occorrenza_at' => now()->subDays(179)]);

    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    expect(Errore::find($sconosciuta->id))->not->toBeNull()
        ->and(Errore::find($sconosciuta->id)->stato)->toBe('sospeso')
        ->and(Errore::find($sconosciuta->id)->messaggio)->toBe(Errore::MESSAGGIO_OSCURATO)
        // E chi è ancora vivo tiene il proprio messaggio: l'oscuramento è un
        // orizzonte, non un'amnesia.
        ->and(Errore::find($recente->id)->messaggio)->toBe('Qualcosa non ha funzionato');
});

/*
|--------------------------------------------------------------------------
| 🔴 Il budget dei contesti, e la potatura che se lo portava via (25 Ago 2026)
|--------------------------------------------------------------------------
|
| **Il difetto stava nell'INCROCIO fra il campionamento (blocco 4) e la
| potatura (blocco 7), e nessuno degli otto blocchi lo copriva** — ciascuno era
| corretto per conto proprio.
|
| Misurato: una issue `aperto` con `contesti = 20` e ultima occorrenza a 100
| giorni. Le occorrenze hanno l'orizzonte più corto (90 giorni), la issue no
| (180): dopo `model:prune` le prove sono **0** e il contatore dice ancora
| **20**. `CatturaErrori::daCampionare()` legge quel contatore, quindi torna
| `false` **per sempre** — nemmeno se l'errore succede mille volte al giorno.
|
| Cioè: una issue mai chiusa, che ha raccolto le sue venti prove nei primi
| giorni, dopo tre mesi è un contatore **senza una sola prova**, e non ne
| catturerà mai più. Ed è il caso peggiore, non il raro: `ultima_occorrenza_at`
| si rinfresca a ogni avvenimento, quindi una issue che *continua a ripetersi*
| non viene mai potata — resta viva, muta, e all'occhio di chi la guarda dice
| «venti contesti» mostrandone zero.
|
| È la stessa forma del difetto che il blocco 3 aveva risolto azzerando il
| budget alla riapertura («l'ho corretto, perché succede ancora?»), spostata da
| «dopo un tentativo di correzione» a «dopo novanta giorni».
|
| ## Il rimedio scelto, e quello scartato
|
| Si riallinea `contesti` alle prove **superstiti** dentro
| `OccorrenzaErrore::pruneAll()`. L'alternativa era derivare `daCampionare()` da
| `occorrenzeErrore()->count()` invece che dal contatore: sarebbe il rimedio più
| radicale — il contatore non potrebbe più mentire perché non esisterebbe — ma
| aggiunge una `SELECT count(*)` **dentro il gestore delle eccezioni**, cioè sul
| percorso più caldo che il progetto abbia, e su ogni eccezione riportata,
| comprese quelle che la finestra scarterebbe un istante dopo. `CatturaErroriTest`
| congela «due query sul percorso caldo» apposta. Il riallineamento costa invece
| due query **una volta al giorno**, dentro un comando di manutenzione, e
| ripristina l'INVARIANTE che il docblock di `Errore` già dichiarava — «`contesti`
| = quante prove se ne sono **conservate**» — che oggi era vero solo finché
| nessuno potava.
*/

it('gives an issue its context budget back when the pruning takes its evidence away', function () {
    config([
        'easylab.errori.contesti_per_errore' => 3,
        // Finestra a zero: qui si prova il **tetto**, non il ritmo. Con la
        // finestra viva le tre catture andrebbero a un contesto solo e il test
        // proverebbe l'altra guardia.
        'easylab.errori.finestra_contesto_secondi' => 0,
    ]);

    // ⚠️ **La stessa istanza di eccezione**, come in `CatturaErroriTest`:
    // l'impronta nasce da dove il `throw` è avvenuto, quindi due `new` su due
    // righe diverse sarebbero — correttamente — due issue.
    $eccezione = new RuntimeException('succede da cento giorni');

    $this->travelTo(now()->subDays(100));

    foreach (range(1, 3) as $volta) {
        CatturaErrori::cattura($eccezione);
    }

    $errore = Errore::query()->where('classe', RuntimeException::class)
        ->where('messaggio', 'succede da cento giorni')->sole();

    // Il budget è esaurito: al tetto, con le sue tre prove a database.
    expect($errore->contesti)->toBe(3)
        ->and(OccorrenzaErrore::query()->where('errore_id', $errore->id)->count())->toBe(3);

    $this->travelBack();

    $this->artisan('model:prune', ['--model' => [OccorrenzaErrore::class]])->assertSuccessful();

    // Le prove se ne sono andate — è il loro orizzonte, ed è giusto così: sono
    // la parte che porta dati personali (Privacy §T8).
    expect(OccorrenzaErrore::query()->where('errore_id', $errore->id)->count())->toBe(0)
        // 🔴 **E il contatore deve dire la verità su ciò che RESTA.** Qui stava
        // il difetto: `contesti` restava a 3 su zero prove.
        ->and($errore->fresh()->contesti)->toBe(0)
        // ⚠️ E anche il timestamp, o `daCampionare()` passerebbe dal ramo della
        // finestra invece che da quello della prima prova — che è il ramo che
        // dice «questa vale più delle altre, si prende sempre».
        ->and($errore->fresh()->ultimo_contesto_at)->toBeNull();

    // 🔴 **Ciò che si vede davvero**: la issue torna a raccogliere prove. Senza
    // il riallineamento questa cattura lascerebbe crescere il solo contatore
    // delle occorrenze, e la tabella dei contesti resterebbe vuota per sempre.
    CatturaErrori::cattura($eccezione);

    expect(OccorrenzaErrore::query()->where('errore_id', $errore->id)->count())->toBe(1)
        ->and($errore->fresh()->contesti)->toBe(1)
        // Il contatore delle occorrenze non c'entra e non si tocca: dice quante
        // volte è successo, e continua a dirlo attraverso la potatura.
        ->and($errore->fresh()->occorrenze)->toBe(4);
});

it('only gives back the budget that was actually spent, never the whole of it', function () {
    // ⚠️ **Il rovescio, e senza di lui il rimedio sarebbe peggio del male.** Un
    // azzeramento secco («la potatura è passata → contesti = 0») regalerebbe il
    // budget intero anche alle issue che hanno perso solo le prove vecchie, e il
    // tetto dichiarato in Privacy §T8 non sarebbe più un tetto: basterebbe
    // aspettare novanta giorni per ricominciare da capo. Il contatore si
    // riallinea a **quante prove restano**, non a zero.
    $this->freezeTime();

    $issue = issueErrore(['stato' => 'aperto', 'ultima_occorrenza_at' => now()]);

    $vecchia = occorrenza($issue, now()->subDays(91));
    $recente = occorrenza($issue, now()->subDays(89));

    $issue->forceFill(['contesti' => 2, 'ultimo_contesto_at' => now()->subDays(89)])->save();

    $this->artisan('model:prune', ['--model' => [OccorrenzaErrore::class]])->assertSuccessful();

    expect(OccorrenzaErrore::find($vecchia->id))->toBeNull()
        ->and(OccorrenzaErrore::find($recente->id))->not->toBeNull()
        ->and($issue->fresh()->contesti)->toBe(1)
        // Il timestamp resta quello della prova superstite: la potatura porta
        // via le **più vecchie**, quindi la più recente è per costruzione
        // l'ultima conservata.
        ->and($issue->fresh()->ultimo_contesto_at->toDateTimeString())->toBe(now()->subDays(89)->toDateTimeString());
});
