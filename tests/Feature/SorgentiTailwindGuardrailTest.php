<?php

/**
 * 🔴 Meta-test delle **sorgenti di Tailwind**: il foglio di stile costruito
 * dipende solo dal commit, e ogni file che può contenere una classe è davvero
 * scansionato (🔗 `docs/Design/Design System Base.md` §2, `resources/css/app.css`).
 *
 * ## Il guasto che questo file esiste per rendere rumoroso
 *
 * È il **gemello** di `PaletteGuardrailTest`, e il sintomo che si vede è lo
 * stesso: una classe scritta in pagina che non colora niente. Solo che la causa
 * è all'altro capo della catena — lì il token non è definito, qui il **file non
 * è stato letto**. Tailwind genera le utility solo per le classi che *trova*:
 * un file fuori dalle sorgenti dichiarate è un file le cui classi non esistono,
 * e come sempre in questa catena nessuno si lamenta. Pagina 200, markup giusto,
 * colore assente.
 *
 * ## E il verso opposto, che è quello che abbiamo davvero pagato
 *
 * ⚠️ Misurato il 25 Ago 2026 costruendo il bundle due volte e confrontando i
 * selettori, non a occhio. La scoperta automatica di Tailwind parte dalla radice
 * del progetto e legge **tutto ciò che non è gitignorato**, quindi anche
 * `tests/`: una classe scritta in una fixture finiva nel CSS dei clienti. Fra
 * quelle c'erano `border-t-neutral-950` e `md:dark:hover:border-primary-300`,
 * cioè le **mutazioni** scritte per provare il guardrail della palette — il test
 * che verifica i colori stava spedendo colori in produzione.
 *
 * 🔴 E `@source '../../storage/framework/views/*.php'` aggiungeva la metà
 * peggiore, quella **intermittente**: quella cartella contiene le viste
 * compilate, cioè ciò che l'ultima esecuzione ha reso. Il bundle conteneva
 * quindi le classi delle pagine *visitate di recente* — **due build dallo stesso
 * commit davano due CSS diversi** a seconda che si fosse lanciata la suite prima
 * o no. 176 selettori su 738 sparivano passando alle sorgenti dichiarate, e il
 * foglio calava del 21% senza che una sola classe usata da una vista vera
 * mancasse all'appello.
 *
 * `source(none)` è la riga che chiude entrambe le metà: spegne la scoperta
 * automatica e lascia valere **solo** i `@source` scritti sotto.
 *
 * ## Perché l'insieme si deriva, di nuovo
 *
 * ⚠️ Un elenco di cartelle scritto qui dentro sarebbe la terza copia della stessa
 * lista (dopo `app.css` e il guardrail della palette), e la copia che diverge è
 * sempre quella che nessuno guarda. Qui si legge `app.css` e si confronta con
 * `sorgentiDiStile()`: il giorno in cui nascesse `resources/moduli/` con delle
 * viste dentro, questo test diventa rosso da solo.
 *
 * 🔗 Nessun test costruisce il bundle — sarebbe `npm run build` dentro la suite,
 * cioè minuti per una verifica che il confronto fra globe e file dà in
 * millisecondi. Ciò che questo file **non** garantisce è che Tailwind interpreti
 * i globe come li interpreta `regexDaGlob()`; la prova è stata fatta a mano, una
 * volta, col metodo descritto sopra.
 */

/**
 * I globe dichiarati in `app.css`, risolti in percorsi assoluti.
 *
 * ⚠️ Il foglio si può passare per poter provare l'estrazione su un caso che il
 * repository non contiene — stessa forma di `tonalitaDefiniteNelTema()`.
 *
 * @return list<string>
 */
function sorgentiDichiarate(?string $css = null): array
{
    $css ??= file_get_contents(resource_path('css/app.css'));

    preg_match_all("/@source\s+(?:not\s+)?'([^']+)'/", $css, $trovati);

    return array_map(
        fn (string $glob) => risolviDaCss($glob),
        $trovati[1]
    );
}

/**
 * Un percorso relativo a `resources/css/` reso assoluto, **senza `realpath()`**:
 * il glob contiene `*` e `**`, che non corrispondono a nessun file esistente.
 */
function risolviDaCss(string $glob): string
{
    $pezzi = [];

    foreach (explode('/', resource_path('css').'/'.$glob) as $pezzo) {
        if ($pezzo === '..') {
            array_pop($pezzi);
        } elseif ($pezzo !== '.') {
            $pezzi[] = $pezzo;
        }
    }

    return implode('/', $pezzi);
}

/**
 * Un glob in stile Tailwind tradotto in espressione regolare.
 *
 * ⚠️ **`**` non è `*` con più asterischi.** In `resources/**\/*.php` la parte
 * `**\/` significa «zero o più cartelle», quindi deve tradursi in un gruppo
 * ripetuto e **opzionale**: tradotta come `.*` non coprirebbe `resources/x.php`,
 * e tradotta come `[^/]*` non coprirebbe le sottocartelle. È lo stesso `**`
 * che in PHP `glob()` **non** è ricorsivo e che ha già lasciato scoperto un
 * meta-test di questo progetto (🔗 `LocalizzazioneTest`).
 *
 * Le virgole si trattano come alternative: fuori da `{…}` un percorso con una
 * virgola dentro sarebbe tradotto male, e nessuno in questo repository ne ha.
 */
function regexDaGlob(string $glob): string
{
    $regex = '';

    for ($i = 0; $i < strlen($glob); $i++) {
        $c = $glob[$i];

        $regex .= match (true) {
            $c === '*' && ($glob[$i + 1] ?? '') === '*' && ($glob[$i + 2] ?? '') === '/' => (function () use (&$i) {
                $i += 2;

                return '(?:[^/]+/)*';
            })(),
            $c === '*' && ($glob[$i + 1] ?? '') === '*' => (function () use (&$i) {
                $i++;

                return '.*';
            })(),
            $c === '*' => '[^/]*',
            $c === '?' => '[^/]',
            $c === '{' => '(?:',
            $c === '}' => ')',
            $c === ',' => '|',
            default => preg_quote($c, '#'),
        };
    }

    return '#^'.$regex.'$#';
}

/** Se almeno una sorgente dichiarata copre il file dato. */
function scansionatoDaTailwind(string $file, ?array $sorgenti = null): bool
{
    foreach ($sorgenti ?? sorgentiDichiarate() as $glob) {
        if (preg_match(regexDaGlob($glob), $file) === 1) {
            return true;
        }
    }

    return false;
}

it('finds the declared sources, so the checks cannot pass for being empty', function () {
    // 🔴 Tutto questo file è derivato da una regex sola: se `@source` cambiasse
    // forma (le virgolette doppie, per dire) l'elenco si svuoterebbe e ogni
    // confronto qui sotto diventerebbe verde per vuoto — per sempre.
    expect(sorgentiDichiarate())->not->toBeEmpty()
        ->and(sorgentiDiStile())->not->toBeEmpty();
});

it('turns globs into patterns that match the same files Tailwind would', function () {
    // ⚠️ Su casi **sintetici**: è la sola traduzione di cui questo file si fida,
    // e provarla solo contro il repository significherebbe provarla contro i due
    // globe che oggi ci sono. Il caso che conta è `**/`, che deve coprire tanto
    // la radice quanto le sottocartelle.
    $glob = regexDaGlob('/base/risorse/**/*.{php,js}');

    expect(preg_match($glob, '/base/risorse/a.php'))->toBe(1)
        ->and(preg_match($glob, '/base/risorse/viste/molto/in/fondo/a.js'))->toBe(1)
        ->and(preg_match($glob, '/base/risorse/a.css'))->toBe(0)
        ->and(preg_match($glob, '/base/altro/a.php'))->toBe(0);

    // `*` singolo si ferma alla cartella, `**` no: è la differenza che rende
    // `glob('Livewire/**/*.php')` non ricorsivo in PHP.
    expect(preg_match(regexDaGlob('/base/*.php'), '/base/dentro/a.php'))->toBe(0);

    // 🔴 **E `**\/` non è nemmeno `.*`**, che è l'errore nell'altra direzione —
    // quella *permissiva*, cioè quella che darebbe un falso verde al test «nessun
    // file resta fuori dalla scansione»: questo file crederebbe coperto un file
    // che Tailwind non legge, e il guasto tornerebbe invisibile esattamente dove
    // questo test dovrebbe vederlo.
    //
    // ⚠️ Il caso serve **anche perché il glob del progetto non lo distingue**:
    // in `resources/**\/*.php` il jolly è in fondo, quindi `.*` e `(?:[^/]+/)*`
    // fanno lo stesso, la mutazione è un no-op e il test sarebbe verde per
    // assenza di caso. Con la cartella *in mezzo* la differenza esiste: `.*`
    // mangerebbe mezzo nome, e `/base/xviste/a.php` passerebbe.
    $inMezzo = regexDaGlob('/base/**/viste/*.php');

    expect(preg_match($inMezzo, '/base/dentro/viste/a.php'))->toBe(1)
        ->and(preg_match($inMezzo, '/base/viste/a.php'))->toBe(1)
        ->and(preg_match($inMezzo, '/base/xviste/a.php'))->toBe(0);
});

it('never lets the build depend on anything but the commit', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    // 🔴 Senza `source(none)` Tailwind riparte dalla scoperta automatica, e da
    // lì rientra `tests/` — cioè le fixture tornano nel CSS dei clienti. Non è
    // una preferenza di stile: è l'unica riga che rende il bundle funzione del
    // solo commit.
    // ⚠️ `toContain()` prende **più aghi**, non un messaggio: passandogli la
    // spiegazione come secondo argomento la cercherebbe dentro il foglio di
    // stile, e il test fallirebbe sul proprio messaggio d'errore. Verificato.
    expect(str_contains($css, "@import 'tailwindcss' source(none);"))->toBeTrue(
        "resources/css/app.css deve importare Tailwind con `source(none)`.\n\n".
        "⚠️ Senza, la scoperta automatica legge tutto ciò che non è gitignorato:\n".
        "  · `tests/` incluso — le classi delle fixture finiscono nel bundle di produzione;\n".
        '  · e ogni cartella nuova entra senza che nessuno l\'abbia dichiarata.'
    );

    // 🔴 E la cartella delle viste **compilate** non torna fra le sorgenti: è
    // il residuo dell'ultima esecuzione, quindi renderebbe il bundle diverso a
    // seconda di cosa è stato visitato prima del build.
    $compilate = collect(sorgentiDichiarate())
        ->filter(fn (string $g) => str_contains($g, 'storage/framework/views'));

    expect($compilate->all())->toBe([],
        "`storage/framework/views` non può essere una sorgente di Tailwind: contiene le viste\n".
        "compilate dall'ultima esecuzione, quindi due build dallo stesso commit darebbero due CSS\n".
        'diversi. Le viste vere sono già coperte da `resources/`.'
    );
});

it('never lets a test fixture reach the production stylesheet', function () {
    $fixture = collect(File::allFiles(base_path('tests')))
        ->map(fn ($f) => $f->getRealPath())
        ->filter(fn (string $f) => scansionatoDaTailwind($f))
        ->map(fn (string $f) => str_replace(base_path().'/', '', $f))
        ->values();

    expect($fixture->all())->toBe([],
        "Sorgenti di test scansionate da Tailwind: le classi scritte lì dentro finiscono nel CSS\n".
        "che scaricano i clienti.\n\n".
        '⚠️ Fra queste ci sono le **mutazioni** del guardrail della palette, cioè classi scritte '.
        'apposta per essere sbagliate.'
    );
});

it('never lets a source file go unscanned, so a class there would be silent', function () {
    $sorgenti = sorgentiDichiarate();

    $invisibili = collect(sorgentiDiStile())
        ->reject(fn (string $f) => scansionatoDaTailwind($f, $sorgenti))
        ->map(fn (string $f) => str_replace(base_path().'/', '', $f))
        ->values();

    expect($invisibili->all())->toBe([],
        "File che possono contenere una classe e che Tailwind non legge.\n\n".
        "⚠️ Non danno errore: la classe è scritta nel markup, la pagina risponde 200 e il colore\n".
        "non c'è — lo stesso sintomo di una tonalità non definita, all'altro capo della catena.\n\n".
        'Rimedio: aggiungere un `@source` in `resources/css/app.css`.'
    );
});
