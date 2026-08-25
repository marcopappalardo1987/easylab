<?php

/**
 * 🔴 Meta-test della **palette**: nessuna vista usa una tonalità che `@theme`
 * non definisce (🔗 `docs/Design/Design System Base.md` §2 e §6, ADR-033).
 *
 * ## Il guasto che questo file esiste per rendere rumoroso
 *
 * **Una tonalità assente non dà errore.** Non c'è nessun punto della catena —
 * Blade, Vite, Tailwind, il browser — in cui una classe di colore inesistente
 * si lamenti: la pagina risponde 200, il markup è quello giusto, e il colore è
 * un altro o non c'è. È la stessa forma dell'`<img src>` verso un file assente
 * che ha lasciato il marchio vecchio in pagina per due mesi.
 *
 * 🔴 **E le due metà si comportano in modo OPPOSTO**, che è la ragione per cui
 * la deriva è durata mesi senza che nessuno la vedesse. Misurato il 25 Ago 2026
 * sul bundle costruito, non a occhio:
 *
 *  - `neutral` è anche il nome di una palette **nativa** di Tailwind, quindi
 *    `text-neutral-500` **rende** — con `oklch(55.6% 0 0)`, cioè croma zero, il
 *    grigio acromatico di Tailwind invece dello Slate del progetto. Il risultato
 *    è **due famiglie di grigio nella stessa pagina**, e nessuna delle due
 *    abbastanza sbagliata da farsi notare. Erano 154 usi fra `neutral-300`,
 *    `neutral-500` e `neutral-700`, sparsi su diciotto viste;
 *  - `primary`, `danger`, `success`, `warning` sono nomi **nostri**: la tonalità
 *    assente non genera nessuna regola, e la classe è muta. Fra queste c'era
 *    `hover:bg-danger-700` sul pulsante «Chiudi la porta» della cabina di regia,
 *    cioè un pulsante distruttivo che al passaggio del mouse non si colorava.
 *
 * ## Perché entrambi gli insiemi si DERIVANO
 *
 * ⚠️ Un elenco di tonalità ammesse scritto qui dentro sarebbe una **seconda
 * copia** di `@theme` da tenere allineata a mano, cioè lo stesso difetto un
 * livello più su — e la copia che diverge è sempre quella che nessuno guarda. Il
 * `@theme` è la fonte, le viste sono l'uso: questo file legge tutti e due e non
 * dichiara niente per conto proprio.
 *
 * ⚠️ **Le famiglie sono derivate anche loro.** «Quali nomi sono nostri» si
 * ricava da `@theme`: il giorno in cui nascesse un `--color-brand-500`, `brand`
 * entrerebbe nel controllo senza che nessuno debba ricordarsene, e il giorno in
 * cui `obsolete` sparisse ne uscirebbe. È la differenza fra una rete che segue
 * il progetto e una che va aggiornata a mano dopo.
 *
 * 🔗 Nessun test guarda i colori (lo dice ADR-033 fra le sue conseguenze):
 * questo non li guarda nemmeno lui — verifica che il token esista, non che sia
 * bello. La verifica del *valore* resta visiva e manuale.
 */

/**
 * Le tonalità dichiarate in `@theme`, come `famiglia-gradino`.
 *
 * ⚠️ Si legge **solo il blocco `@theme`** e non tutto il foglio: più in basso
 * `app.css` usa `var(--color-neutral-200)` dentro `@utility tabella-a-card`, e
 * un uso non è una definizione. Confonderli renderebbe questo test verde su una
 * variabile che nessuno ha mai dichiarato.
 *
 * @return list<string> ordinate, es. `['danger-100', 'danger-500', …]`
 */
function tonalitaDefiniteNelTema(?string $css = null): array
{
    // ⚠️ **Il foglio si può passare, e non è per comodità**: è il solo modo di
    // provare che l'estrazione legge davvero il **solo** `@theme`. Oggi
    // `app.css` non ha nessuna definizione fuori da quel blocco, quindi la
    // mutazione «leggi tutto il foglio» è un no-op e il test che la cercava era
    // verde per assenza di caso — non per merito. Con un foglio sintetico il
    // caso esiste. È la stessa forma di `CatturaErrori::identifica()`, che
    // prende le posizioni già estratte per poter provare l'impronta contro un
    // deploy diverso.
    $css ??= file_get_contents(resource_path('css/app.css'));

    preg_match('/@theme\s*\{(.*?)\n\}/s', $css, $blocco);

    // Non `?? ''`: un `@theme` che non si trova più darebbe insieme vuoto, e il
    // confronto diventerebbe «tutto è indefinito» oppure — peggio — verde per
    // vuoto dall'altro verso. Meglio rompersi qui, dove si legge il perché.
    expect($blocco)->not->toBeEmpty('Blocco @theme non trovato in resources/css/app.css');

    preg_match_all('/--color-([a-z]+)-(\d{2,3})\s*:/', $blocco[1], $token, PREG_SET_ORDER);

    $tonalita = array_map(fn (array $t) => $t[1].'-'.$t[2], $token);
    sort($tonalita);

    return array_values(array_unique($tonalita));
}

/** Le famiglie di colore **nostre**, derivate dalle tonalità definite. */
function famiglieDelTema(): array
{
    $famiglie = array_map(fn (string $t) => explode('-', $t)[0], tonalitaDefiniteNelTema());
    sort($famiglie);

    return array_values(array_unique($famiglie));
}

/**
 * Le tonalità che un sorgente **usa**, limitate alle famiglie date.
 *
 * **Due difese contro il CSS letto come markup, e va detto quale delle due
 * lavora davvero.** Il repository *contiene* un foglio di stile dentro una
 * vista: `welcome.blade.php` — la pagina di benvenuto di Laravel, che questo
 * progetto non instrada da nessuna parte — porta inlinato un **intero build di
 * Tailwind v4.0.7**.
 *
 * ⚠️ **Misurato il 25 Ago 2026, e il risultato non è quello che sembrava**: di
 * quel foglio, ciò che nomina le nostre famiglie sono le **dichiarazioni**
 * `--color-neutral-300: …` nel `:root`, non delle utility — quel build genera
 * `.text-neutral-*` solo se la pagina le usa, e non le usa. A tenerle fuori è
 * quindi il vincolo sul **prefisso** (`bg|text|border|…`), che una dichiarazione
 * di variabile non ha; lo stripping dei `<style>` oggi non toglie **niente**.
 *
 * Resta lo stesso, ed è una scelta: costa una `preg_replace`, la regola che
 * esprime è vera in generale («il CSS non è markup») e il giorno in cui quella
 * pagina — o una futura email in HTML — inlinasse davvero delle utility sarebbe
 * già a posto. ⚠️ **Ma è falsificabile solo dal test sintetico qui sotto**, non
 * dal repository: togliendola, il resto della suite resta verde. Dirlo qui è il
 * punto — una guardia che si crede coperta dai dati veri e non lo è vale meno di
 * una dichiarata inerte.
 *
 * ⚠️ **I commenti Blade si tolgono** per la ragione già imparata dal guardrail
 * della copertura audit: un docblock che spiega *perché* una classe non si usa
 * più non è un uso di quella classe, e un meta-test che legge il testo invece
 * del codice punisce chi documenta.
 *
 * @param  list<string>  $famiglie
 * @return list<string>
 */
function tonalitaUsateNelSorgente(string $sorgente, array $famiglie): array
{
    if ($famiglie === []) {
        return [];
    }

    $sorgente = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $sorgente);
    $sorgente = preg_replace('/\{\{--.*?--\}\}/s', '', $sorgente);

    // I prefissi delle utility che prendono un colore. La lista è di **prefissi**
    // e non di colori: è stabile quanto Tailwind, e non è la copia di niente che
    // viva nel progetto.
    // ⚠️ **`border` e `divide` prendono anche il lato**, e senza il suffisso
    // opzionale questa rete era cieca proprio alla classe di guasto per cui è
    // nata: verificato, `border-t-neutral-950` passava indisturbato mentre
    // `text-neutral-950` era rosso — e `border-t-neutral-950` genera davvero una
    // regola col grigio **acromatico** di Tailwind, cioè il difetto che questo
    // file esiste per rendere rumoroso.
    $prefissi = '(?:border|divide)(?:-[trblxyse])?|bg|text|ring|from|to|via|fill|stroke|outline|decoration|accent|caret|placeholder|shadow';
    $nomi = implode('|', array_map(fn (string $f) => preg_quote($f, '/'), $famiglie));

    // ⚠️ `(?<![\w-])` davanti: senza, `--color-neutral-300` dentro un foglio di
    // stile o `hover:border-primary-300` verrebbero comunque presi — il primo è
    // rumore, il secondo è un uso vero e deve passare, quindi il confine si mette
    // sul **prefisso** e non sulla famiglia. Le varianti (`hover:`, `md:`,
    // `dark:`) finiscono in `:` e non in `-`, quindi non sono d'ostacolo.
    preg_match_all(
        '/(?<![\w-])(?:'.$prefissi.')-('.$nomi.')-(\d{2,3})(?![\w-])/',
        $sorgente,
        $trovate,
        PREG_SET_ORDER
    );

    $tonalita = array_map(fn (array $t) => $t[1].'-'.$t[2], $trovate);
    sort($tonalita);

    return array_values(array_unique($tonalita));
}

/**
 * @return array<string, list<string>> tonalità → i file che la usano
 *
 * ⚠️ L'elenco dei sorgenti vive in `sorgentiDiStile()` (`tests/Pest.php`) e
 * **non qui**: è lo stesso insieme che `SorgentiTailwindGuardrailTest` verifica
 * essere davvero scansionato da Tailwind. Tenerne due copie significherebbe
 * lasciare un angolo del progetto in cui una classe non produce nulla senza che
 * nessuno dei due test se ne accorga.
 */
function usiDelleTonalita(): array
{
    $famiglie = famiglieDelTema();

    // ⚠️ **Tutta `resources/` e non le sole viste**, ed è una correzione pagata:
    // questa rete leggeva `views/` e `app/`, e una classe di colore scritta in
    // `resources/js/app.js` finiva nel bundle mentre il guardrail restava verde.
    //
    // ⚠️ *La ragione di allora era «Tailwind scansiona l'intero repo», e dal 25
    // Ago 2026 **non è più vera**: `app.css` importa con `source(none)` e le
    // sorgenti sono dichiarate. La conclusione regge lo stesso — anzi meglio,
    // perché ora i due insiemi sono lo stesso insieme, e a tenerli tali è
    // `SorgentiTailwindGuardrailTest`.*
    $usi = [];

    foreach (sorgentiDiStile() as $file) {
        foreach (tonalitaUsateNelSorgente(file_get_contents($file), $famiglie) as $tonalita) {
            $usi[$tonalita][] = str_replace(base_path().'/', '', $file);
        }
    }

    ksort($usi);

    return $usi;
}

it('finds both sets, so the comparison cannot pass for being empty', function () {
    // 🔴 Le due metà di questo file sono derivate da una regex ciascuna, e una
    // regex che smette di trovare qualcosa non lo dice: `[] ⊆ []` è verde, e
    // resterebbe verde per sempre. È la stessa rete che `RetentionTest` mette
    // davanti al proprio glob.
    expect(tonalitaDefiniteNelTema())->not->toBeEmpty()
        ->and(famiglieDelTema())->toContain('primary', 'neutral', 'danger', 'success', 'warning')
        ->and(usiDelleTonalita())->not->toBeEmpty();
});

it('never lets a view use a shade the theme does not define', function () {
    $definite = tonalitaDefiniteNelTema();

    // `except()` e non un `reject()` sulla chiave: toglie per chiave, che è
    // esattamente la domanda («questa tonalità è fra le definite?»), e non lascia
    // in giro un parametro che non si usa.
    $mancanti = collect(usiDelleTonalita())->except($definite);

    expect($mancanti->keys()->all())->toBe([], $mancanti->isEmpty() ? '' :
        "Tonalità usate nelle viste e non definite in `resources/css/app.css`.\n\n".
        "⚠️ Non danno errore, e le due metà sbagliano in modo diverso:\n".
        "  · `neutral` è anche una palette NATIVA di Tailwind, quindi la classe RENDE — col grigio\n".
        "    acromatico di Tailwind al posto dello Slate del progetto: due grigi nella stessa pagina;\n".
        "  · `primary`/`danger`/`success`/`warning` sono nomi NOSTRI, quindi la classe non genera\n".
        "    nessuna regola ed è muta (un hover che non colora, un badge senza sfondo).\n\n".
        "Due rimedi, e la scelta si fa sul Design System (docs/Design/Design System Base.md §2, §6):\n".
        "  · la tonalità è nel DS → si definisce in `@theme`, con l'hex del DS;\n".
        "  · la tonalità NON è nel DS → è la VISTA a doverla cambiare, con un gradino che esiste.\n".
        "    Inventare qui un token che il documento non ha significa decidere la palette di\n".
        "    passaggio, che è ciò che ADR-033 chiede di non fare senza verifica visiva.\n\n".
        'Trovate: '.$mancanti->map(fn (array $file, string $t) => $t.' ('.implode(', ', array_unique($file)).')')->implode("\n           ")
    );
});

it('reads the theme and not the rest of the stylesheet', function () {
    // ⚠️ **Il guardrail del guardrail.** `app.css` USA `var(--color-neutral-200)`
    // fuori da `@theme` (dentro `@utility tabella-a-card`): se l'estrazione
    // leggesse tutto il foglio, un token soltanto *usato* passerebbe per
    // *definito* e il test più importante di questo file diventerebbe verde
    // proprio sul caso che deve vedere.
    // 🔴 **E si asserisce sulla funzione vera, non su una regex ribattuta qui.**
    // La prima stesura ri-derivava l'estrazione dentro il test e verificava il
    // testo di `app.css`: non chiamava mai `tonalitaDefiniteNelTema()`, quindi
    // era l'unico test del file **incapace di fallire per la ragione che
    // dichiara** — verificato, facendo leggere all'estrazione l'intero foglio
    // restava verde.
    $css = file_get_contents(resource_path('css/app.css'));

    // La premessa: il token è **usato** fuori da `@theme` e non vi è definito.
    $fuoriTema = preg_replace('/@theme\s*\{.*?\n\}/s', '', $css);

    expect($fuoriTema)->toContain('var(--color-neutral-200)')
        ->and($fuoriTema)->not->toContain('--color-neutral-200:');

    // La tesi, provata su un foglio **sintetico**: un token definito fuori da
    // `@theme` non si conta come definito. Sul foglio vero il caso non esiste
    // ancora (nessuna definizione è fuori dal blocco), quindi cercarlo lì
    // sarebbe verde per assenza di caso.
    $sintetico = <<<'CSS'
    @theme {
        --color-brand-500: #123456;
    }

    :root {
        --color-brand-900: #000000;
    }
    CSS;

    expect(tonalitaDefiniteNelTema($sintetico))->toBe(['brand-500']);
});

it('sees a use through a variant and ignores a stylesheet that only looks like one', function () {
    // 🔴 Il guardrail del guardrail, secondo verso: il rilevatore si prova sui
    // sorgenti sintetici invece che sperare che il repo contenga i casi giusti.
    // La forma è quella di `ScrittureRbacGuardrailTest`.
    $famiglie = ['primary', 'neutral', 'danger'];

    // Ciò che DEVE essere visto.
    expect(tonalitaUsateNelSorgente('<p class="text-neutral-500">x</p>', $famiglie))->toBe(['neutral-500'])
        ->and(tonalitaUsateNelSorgente('<a class="hover:bg-danger-800">x</a>', $famiglie))->toBe(['danger-800'])
        ->and(tonalitaUsateNelSorgente('<a class="md:dark:hover:border-primary-300">x</a>', $famiglie))->toBe(['primary-300'])
        // L'`!` di Tailwind v4 sta in coda alla classe e non deve nasconderla.
        ->and(tonalitaUsateNelSorgente('<p class="text-neutral-500!">x</p>', $famiglie))->toBe(['neutral-500']);

    // Ciò che NON deve essere visto.
    expect(tonalitaUsateNelSorgente('<style>.text-neutral-300{color:#d4d4d4}</style>', $famiglie))->toBe([])
        ->and(tonalitaUsateNelSorgente('<style>:root{--color-primary-950:#041d33}</style>', $famiglie))->toBe([])
        ->and(tonalitaUsateNelSorgente('{{-- niente `text-danger-900`, non esiste --}}', $famiglie))->toBe([])
        // Una famiglia che non è nostra non riguarda questo test: `neutral` sì,
        // `neutrale` no — il confine di parola serve a non confonderle.
        ->and(tonalitaUsateNelSorgente('<p class="text-neutrale-500">x</p>', $famiglie))->toBe([])
        // Le utility che portano un numero e non un colore.
        ->and(tonalitaUsateNelSorgente('<p class="text-3xl border-t-2 divide-y-2 duration-300">x</p>', $famiglie))->toBe([]);
});
