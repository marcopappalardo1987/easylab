<?php

use App\Livewire\Guida\Manuale;
use App\Support\Guide\Manuale as Libreria;
use App\Support\Guide\TestiScritti;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * L'indice, il filtro e il legame fra video e testo.
 *
 * 🔗 `app/Support/Guide/Manuale.php`. Le guide non sono righe di database: sono
 * i `manifest.json` che lo studio (`guide/`) pubblica in `public/guide/`, quindi
 * questi test leggono ciò che è davvero pubblicato — se un giorno non lo fosse
 * niente, il primo test qui sotto diventa rosso invece di restare vuoto.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    discoGuideVuoto();
    guidaFinta('accesso-finto', 'Il primo accesso');
    guidaFinta('sedi-finte', 'Passare da una sede all\'altra');
    guidaFinta('intervento-finto', 'Registrare un intervento');
    $this->pagina = fn () => Livewire::actingAs(utenteConRuolo('Superadmin'))->test(Manuale::class);
});

it('reads every published guide', function () {
    expect(Libreria::tutte()->pluck('slug')->all())
        ->toBe(['accesso-finto', 'sedi-finte', 'intervento-finto']);
});

it('ignores a guide whose video has not been published', function () {
    // ⚠️ La regola è il **silenzio**: una voce senza mp4 sparisce dall'indice
    // invece di offrire un lettore vuoto.
    //
    // ⛔ Il manifest va scritto DAVVERO. La prima versione di questo test
    // aggiungeva uno slug che non aveva né manifest né video, quindi restava
    // verde anche togliendo del tutto il controllo sull'mp4: misurava la
    // condizione sbagliata. Provato mutando `Manuale::manifest()`.
    guidaFinta('senza-video', 'Guida senza video', conVideo: false);

    expect(Libreria::disco()->exists(Libreria::percorso('senza-video', 'manifest')))->toBeTrue();
    expect(Libreria::tutte()->pluck('slug'))->not->toContain('senza-video');
});

it('puts the written prose next to the caption, not in its place', function () {
    // ⚠️ Slug **vero** con manifest **finto**: è l'unico modo di provare
    // l'innesto senza dipendere da un mp4 pubblicato. `guide/testi/intervento.md`
    // è quello di produzione, il manifest lo scrive la fixture.
    guidaFinta('intervento', 'Registrare un intervento');

    $passo = collect(Libreria::trova('intervento')['capitoli'])
        ->flatMap(fn (array $c) => $c['passi'])
        ->first();

    expect($passo['dettaglio'])->toBe(TestiScritti::per('intervento')['passi'][1])
        // La riga che regge il test: senza, un approfondimento rimasto uguale
        // alla didascalia passerebbe la prima asserzione il giorno in cui i due
        // tornassero a coincidere.
        ->and($passo['dettaglio'])->not->toBe('Primo passo di intervento.')
        // La riga breve, su cui si clicca, resta quella del video.
        ->and($passo['testo'])->toBe('Primo passo di intervento.');
});

it('opens the guide and each chapter with the written premises', function () {
    // ⚠️ Il numero del capitolo non sta nel manifest: lo conta `componi()`
    // sull'ordine dei cartelli. Un contatore sfasato di uno non rompe nulla —
    // mostra l'apertura sbagliata, o nessuna — quindi va provato qui.
    guidaFinta('intervento', 'Registrare un intervento');

    $guida = Libreria::trova('intervento');
    $testi = TestiScritti::per('intervento');

    expect($testi['premessa'])->not->toBeNull()
        ->and($guida['premessa'])->toBe($testi['premessa'])
        ->and($guida['capitoli'][0]['premessa'])->toBe($testi['capitoli'][1]);
});

it('shows the bare caption when a guide has no written text', function () {
    // La degradazione voluta: senza approfondimento resta la riga breve, e la
    // pagina non stampa un paragrafo vuoto (`@if ($passo['dettaglio'])`).
    // `TestiScrittiGuardrailTest` è ciò che impedisce che diventi la normalità.
    $passo = collect(Libreria::trova('accesso-finto')['capitoli'])
        ->flatMap(fn (array $c) => $c['passi'])
        ->first();

    expect(TestiScritti::per('accesso-finto')['passi'])->toBe([])
        ->and($passo['dettaglio'])->toBeNull()
        ->and($passo['testo'])->toBe('Primo passo di accesso-finto.');
});

it('renders even when the cache still holds the previous shape', function () {
    // 🔴 **Il 7 Set 2026 la pagina è morta esattamente così.** Il deploy ha
    // portato le chiavi nuove (`premessa`, `dettaglio`) con la versione della
    // chiave di cache ferma, quindi staging ha riletto per un'ora le voci della
    // forma precedente e la vista è esplosa su `Undefined array key`. La suite
    // era verde perché in `testing` il ramo con la cache non gira mai.
    //
    // Alzare la versione è il rimedio; questo test sorveglia la cintura, cioè
    // che la vista legga col `??` le chiavi che una voce vecchia non ha.
    Cache::store('file')->clear();
    config()->set('cache.default', 'file');
    app()->detectEnvironment(fn () => 'staging');

    $vecchie = Libreria::tutte()->map(function (array $guida): array {
        unset($guida['premessa']);

        $guida['capitoli'] = array_map(function (array $capitolo): array {
            unset($capitolo['premessa']);

            $capitolo['passi'] = array_map(function (array $passo): array {
                unset($passo['dettaglio']);

                return $passo;
            }, $capitolo['passi']);

            return $capitolo;
        }, $guida['capitoli']);

        return $guida;
    })->all();

    Cache::put((new ReflectionClass(Libreria::class))->getConstant('CHIAVE'), $vecchie, now()->addHour());

    ($this->pagina)()->assertOk();

    Cache::store('file')->clear();
});

it('narrows the index to the search text', function () {
    $tutte = Libreria::tutte();
    $bersaglio = $tutte->first();
    $risultati = Libreria::cerca($bersaglio['titolo']);

    // Senza questa riga il test resterebbe verde anche con un filtro che non
    // filtra nulla: `assertDontSee` su un titolo che il filtro non ha escluso
    // non prova niente.
    expect($risultati->count())->toBeLessThan($tutte->count());

    $escluso = $tutte->first(fn (array $g) => ! $risultati->contains('slug', $g['slug']));

    ($this->pagina)()
        ->set('ricerca', $bersaglio['titolo'])
        ->assertSee($bersaglio['titolo'])
        ->assertDontSee($escluso['titolo']);
});

it('searches inside the steps, not only the titles', function () {
    // Chi cerca una parola che sta in una didascalia deve trovarla: è metà del
    // motivo per cui il testo della guida esiste accanto al video.
    $guida = Libreria::tutte()->first();
    $parola = collect($guida['capitoli'])->flatMap(fn ($c) => $c['passi'])->first()['testo'];

    expect(Libreria::cerca(mb_substr($parola, 0, 24))->pluck('slug'))->toContain($guida['slug']);
});

it('moves to a surviving guide when the open one falls out of the results', function () {
    $tutte = Libreria::tutte();
    $ultima = $tutte->last();

    // Aperta la prima, si cerca il titolo dell'ultima: restare sulla prima
    // significherebbe mostrare una guida che l'indice non elenca più.
    ($this->pagina)()
        ->set('slug', $tutte->first()['slug'])
        ->set('ricerca', $ultima['titolo'])
        ->assertSet('slug', $ultima['slug']);
});

it('says nothing was found instead of showing an empty index', function () {
    ($this->pagina)()
        ->set('ricerca', 'zzz-nessuna-guida-parla-di-questo')
        ->assertSee('Nessuna guida');
});

it('refuses to open a slug that is not published', function () {
    $aperta = Libreria::tutte()->first()['slug'];

    ($this->pagina)()
        ->set('slug', $aperta)
        ->call('apri', 'guida-inesistente')
        ->assertSet('slug', $aperta);
});

it('gives every written step the instant it starts in the video', function () {
    // È il legame fra le due metà della pagina: cliccando un passo il video
    // salta lì. Gli istanti li scrive il montaggio nel manifest, perché è
    // l'unico a sapere quanto dura la testata.
    $guida = Libreria::tutte()->first();
    $passi = collect($guida['capitoli'])->flatMap(fn ($c) => $c['passi']);

    expect($passi)->not->toBeEmpty();
    expect($passi->pluck('inizio')->every(fn ($t) => $t > 0))->toBeTrue();
    expect($passi->pluck('inizio')->sort()->values()->all())->toBe($passi->pluck('inizio')->all());
    expect($passi->last()['inizio'])->toBeLessThan($guida['durata']);
});
