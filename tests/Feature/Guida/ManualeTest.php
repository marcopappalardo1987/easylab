<?php

use App\Livewire\Guida\Manuale;
use App\Support\Guide\Manuale as Libreria;
use Database\Seeders\RolesAndPermissionsSeeder;
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
