<?php

/**
 * 🔴 Meta-test della **palette**: nessuna vista usa un colore che `@theme` non
 * definisce (🔗 `docs/Design/Design System Base.md` §2, §6 e §8, ADR-033/034).
 *
 * ## Il guasto che questo file esiste per rendere rumoroso
 *
 * **Un colore assente non dà errore.** Non c'è nessun punto della catena —
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
 * ## Due metà, perché da ADR-034 i token sono due strati
 *
 * ⚠️ **I token semantici non hanno un gradino.** `--color-surface`,
 * `--color-ink-2`, `--color-brand-soft-ink`, `--color-chart-verde`: nessuno di
 * questi porta un numero a due o tre cifre, quindi fino al 26 Ago 2026 erano
 * invisibili a **entrambe** le metà di questo file — né fra i definiti né fra
 * gli usati. La rete sarebbe diventata cieca **proprio mentre le viste
 * migrano** (F2–F5 del restyling), cioè nel momento esatto in cui serve, e in
 * silenzio: un `bg-surface` scritto in una vista e non definito in `@theme` è un
 * nome **nostro**, quindi non genera nessuna regola — card senza fondo.
 *
 * Da qui in poi il file lavora su due insiemi paralleli:
 *
 *  1. le **tonalità di scala** (`famiglia-gradino`, DS §2) — la metà storica;
 *  2. i **token semantici** (DS §8.2) — nomi senza gradino, ed è lo strato che
 *     le viste devono usare.
 *
 * ## Perché tutti gli insiemi si DERIVANO
 *
 * ⚠️ Un elenco di colori ammessi scritto qui dentro sarebbe una **seconda
 * copia** di `@theme` da tenere allineata a mano, cioè lo stesso difetto un
 * livello più su — e la copia che diverge è sempre quella che nessuno guarda. Il
 * `@theme` è la fonte, le viste sono l'uso: questo file legge tutti e due e non
 * dichiara niente per conto proprio.
 *
 * ⚠️ **Anche le famiglie sono derivate**, sia quelle di scala
 * (`tonalitaDefiniteNelTema()` / `famiglieDelTema()`, in `tests/Pest.php` perché
 * le condivide con `SuperficiTokenizzateGuardrailTest`) sia quelle semantiche:
 * il giorno in cui nascesse un `--color-brand-500` o un `--color-surface-raised`
 * entrerebbero nel controllo senza che nessuno debba ricordarsene.
 *
 * ## Ciò che questo file NON copre, e va detto
 *
 * - 🔗 Nessun test guarda i **valori** dei colori (lo dice ADR-033 fra le sue
 *   conseguenze): qui si verifica che il token **esista**, non che sia bello né
 *   che contrasti. La verifica del valore resta visiva e manuale.
 * - **Una famiglia intera che sparisse da `@theme` sparirebbe anche dal
 *   rilevatore**, in tutte e due le metà: le famiglie sono derivate, quindi
 *   `bg-pippo-500` non è «una tonalità non definita», è una classe che questo
 *   file non riconosce come colore. È il prezzo della derivazione, ed è pagato
 *   volentieri: la copia scritta a mano diverge sempre, la derivazione no.
 * - **I due strati sono in blocchi diversi ma nello stesso `@theme`**, che deve
 *   restare **uno solo e piatto**: l'estrazione legge il *primo* blocco fino
 *   alla *prima* `\n}`.
 */

/**
 * Tutti i nomi `--color-*` dichiarati in `@theme`, senza distinguere lo strato.
 *
 * @return list<string> es. `['primary-600', 'surface', 'brand-soft-ink', …]`
 */
function nomiColoreDefinitiNelTema(?string $css = null): array
{
    $css ??= file_get_contents(resource_path('css/app.css'));

    preg_match('/@theme\s*\{(.*?)\n\}/s', $css, $blocco);

    expect($blocco)->not->toBeEmpty('Blocco @theme non trovato in resources/css/app.css');

    // ⚠️ `\s*:` non è decorativo: senza, il `var(--color-primary-600)` che sta
    // **a destra** di `--color-info-500` verrebbe letto come una definizione. Un
    // riferimento non è una dichiarazione, e confonderli è lo stesso errore che
    // il test `reads the theme and not the rest of the stylesheet` sorveglia un
    // livello più su.
    preg_match_all('/--color-([a-z0-9]+(?:-[a-z0-9]+)*)\s*:/', $blocco[1], $token, PREG_SET_ORDER);

    $nomi = array_map(fn (array $t) => $t[1], $token);
    sort($nomi);

    return array_values(array_unique($nomi));
}

/**
 * I **token semantici** definiti in `@theme` (DS §8.2): i nomi `--color-*` che
 * non finiscono con un gradino di scala.
 *
 * ⚠️ **La partizione si fa togliendo, non elencando.** «Semantico» qui vuol dire
 * *«tutto ciò che non è `famiglia-gradino`»*, così che un token nuovo nasca
 * dentro il controllo invece che fuori. L'alternativa — un elenco di prefissi
 * semantici — sarebbe la terza copia della tabella di DS §8.2.
 *
 * ⚠️ **`--color-ink-2` resta un semantico**, e non è un caso fortunato: il
 * gradino vuole 2–3 cifre (`\d{2,3}`), quindi il `2` di `ink-2` non lo è. È la
 * stessa soglia che `tonalitaDefiniteNelTema()` usa dall'altro lato, e le due
 * devono restare la stessa — se divergessero, `ink-2` finirebbe o in entrambi
 * gli insiemi o in nessuno.
 *
 * @return list<string> es. `['bad-dot', 'brand-soft-ink', 'ink-2', 'surface', …]`
 */
function tokenSemanticiDefiniti(?string $css = null): array
{
    return array_values(array_filter(
        nomiColoreDefinitiNelTema($css),
        fn (string $nome) => preg_match('/^[a-z]+-\d{2,3}$/', $nome) !== 1,
    ));
}

/**
 * Le **famiglie semantiche** (`surface`, `ink`, `brand`, `ok`, `chart`…),
 * derivate dai token semantici definiti.
 *
 * 🔴 **Perché serve una famiglia, se poi si confronta il nome intero.** Il
 * rilevatore non può cercare *l'insieme dei nomi definiti*: cercherebbe solo ciò
 * che esiste, e un `bg-surface-raised` inventato — o un `bg-surface` rimasto in
 * pagina dopo che il token è stato tolto da `@theme` — sarebbe **invisibile**,
 * cioè esattamente il guasto che questo file esiste per vedere. Si riconosce
 * quindi per **famiglia** (`surface` è un nome nostro) e si giudica sul **nome
 * intero** (`surface-raised` non è fra i definiti → rosso).
 *
 * @return list<string>
 */
function famiglieSemantiche(?string $css = null): array
{
    $famiglie = array_map(
        fn (string $token) => explode('-', $token)[0],
        tokenSemanticiDefiniti($css),
    );
    sort($famiglie);

    return array_values(array_unique($famiglie));
}

/**
 * Le tonalità di **scala** che un sorgente usa, limitate alle famiglie date.
 *
 * @param  list<string>  $famiglie
 * @return list<string>
 */
function tonalitaUsateNelSorgente(string $sorgente, array $famiglie): array
{
    if ($famiglie === []) {
        return [];
    }

    $sorgente = sorgenteSenzaStileNeCommenti($sorgente);

    $nomi = implode('|', array_map(fn (string $f) => preg_quote($f, '/'), $famiglie));

    // ⚠️ `(?<![\w-])` davanti: senza, `--color-neutral-300` dentro un foglio di
    // stile o `hover:border-primary-300` verrebbero comunque presi — il primo è
    // rumore, il secondo è un uso vero e deve passare, quindi il confine si mette
    // sul **prefisso** e non sulla famiglia. Le varianti (`hover:`, `md:`,
    // `dark:`) finiscono in `:` e non in `-`, quindi non sono d'ostacolo.
    preg_match_all(
        '/(?<![\w-])(?:'.prefissiDiColore().')-('.$nomi.')-(\d{2,3})(?![\w-])/',
        $sorgente,
        $trovate,
        PREG_SET_ORDER
    );

    $tonalita = array_map(fn (array $t) => $t[1].'-'.$t[2], $trovate);
    sort($tonalita);

    return array_values(array_unique($tonalita));
}

/**
 * I **token semantici** che un sorgente usa, limitati alle famiglie date.
 *
 * ⚠️ **Il riconoscimento è per famiglia, il giudizio sul nome intero** (si veda
 * `famiglieSemantiche()`): è ciò che permette a un token *usato e non definito*
 * di diventare rosso invece che invisibile.
 *
 * ⚠️ **I nomi si sovrappongono** — `surface` e `surface-sunken`, `ink` e
 * `ink-2`, `brand` e `brand-soft-ink` — quindi la coda `(?:-[a-z0-9]+)*` è
 * **greedy** e il confine `(?![\w-])` chiude la classe: `bg-surface-sunken` dà
 * `surface-sunken`, non `surface`. Riconoscere per *prefisso* darebbe la
 * risposta sbagliata su tre famiglie su tredici.
 *
 * ⚠️ **Ciò che finisce con un gradino non è affare di questa metà**: se domani
 * nascesse `--color-brand-500`, `brand` sarebbe famiglia in tutti e due gli
 * strati e `bg-brand-500` verrebbe visto da entrambi i rilevatori — qui darebbe
 * un falso positivo («`brand-500` non è fra i semantici»). Lo si lascia
 * all'altra metà, che sa giudicarlo. *Il buco che resta è `bg-surface-500`, cioè
 * un gradino su una famiglia che non ne ha: nessuna delle due metà lo vede. È
 * dichiarato, non risolto — costerebbe più codice di quanto vale un caso che
 * nessuno ha mai scritto.*
 *
 * @param  list<string>  $famiglie
 * @return list<string>
 */
function tokenSemanticiUsatiNelSorgente(string $sorgente, array $famiglie): array
{
    if ($famiglie === []) {
        return [];
    }

    $sorgente = sorgenteSenzaStileNeCommenti($sorgente);

    $nomi = implode('|', array_map(fn (string $f) => preg_quote($f, '/'), $famiglie));

    preg_match_all(
        '/(?<![\w-])(?:'.prefissiDiColore().')-((?:'.$nomi.')(?:-[a-z0-9]+)*)(?![\w-])/',
        $sorgente,
        $trovati,
        PREG_SET_ORDER
    );

    $token = array_filter(
        array_map(fn (array $t) => $t[1], $trovati),
        fn (string $t) => preg_match('/-\d{2,3}$/', $t) !== 1,
    );
    sort($token);

    return array_values(array_unique($token));
}

/**
 * @return array<string, list<string>> tonalità di scala → i file che la usano
 *
 * ⚠️ L'elenco dei sorgenti vive in `sorgentiDiStile()` (`tests/Pest.php`) e
 * **non qui**: è lo stesso insieme che `SorgentiTailwindGuardrailTest` verifica
 * essere davvero scansionato da Tailwind. Tenerne due copie significherebbe
 * lasciare un angolo del progetto in cui una classe non produce nulla senza che
 * nessuno dei due test se ne accorga.
 */
function usiDelleTonalita(): array
{
    // ⚠️ **Tutta `resources/` e non le sole viste**, ed è una correzione pagata:
    // questa rete leggeva `views/` e `app/`, e una classe di colore scritta in
    // `resources/js/app.js` finiva nel bundle mentre il guardrail restava verde.
    //
    // ⚠️ *La ragione di allora era «Tailwind scansiona l'intero repo», e dal 25
    // Ago 2026 **non è più vera**: `app.css` importa con `source(none)` e le
    // sorgenti sono dichiarate. La conclusione regge lo stesso — anzi meglio,
    // perché ora i due insiemi sono lo stesso insieme, e a tenerli tali è
    // `SorgentiTailwindGuardrailTest`.*
    return usiDeiColori(fn (string $sorgente) => tonalitaUsateNelSorgente($sorgente, famiglieDelTema()));
}

/** @return array<string, list<string>> token semantico → i file che lo usano */
function usiDeiTokenSemantici(): array
{
    return usiDeiColori(fn (string $sorgente) => tokenSemanticiUsatiNelSorgente($sorgente, famiglieSemantiche()));
}

/**
 * @param  callable(string): list<string>  $rilevatore
 * @return array<string, list<string>>
 */
function usiDeiColori(callable $rilevatore): array
{
    $usi = [];

    foreach (sorgentiDiStile() as $file) {
        foreach ($rilevatore(file_get_contents($file)) as $colore) {
            $usi[$colore][] = str_replace(base_path().'/', '', $file);
        }
    }

    ksort($usi);

    return $usi;
}

it('finds every set it derives, so no comparison can pass for being empty', function () {
    // 🔴 Ogni metà di questo file è derivata da una regex, e una regex che smette
    // di trovare qualcosa non lo dice: `[] ⊆ []` è verde, e resterebbe verde per
    // sempre. È la stessa rete che `RetentionTest` mette davanti al proprio glob.
    expect(tonalitaDefiniteNelTema())->not->toBeEmpty()
        ->and(famiglieDelTema())->toContain('primary', 'neutral', 'danger', 'success', 'warning')
        ->and(usiDelleTonalita())->not->toBeEmpty()
        ->and(tokenSemanticiDefiniti())->not->toBeEmpty()
        ->and(famiglieSemantiche())->toContain('surface', 'ink', 'brand', 'border', 'chart');

    // ⚠️ **`usiDeiTokenSemantici()` NON si asserisce non vuoto, e va detto
    // perché.** Oggi vale `[]`: nessuna vista è ancora migrata (F2–F5 del
    // restyling non sono partite), quindi pretendere qui un uso vero renderebbe
    // rosso il guardrail per lo stato normale del repository. La conseguenza
    // onesta è che la metà semantica di questo file è provata **solo dai casi
    // sintetici** qui sotto, non dai dati veri — esattamente come lo stripping
    // dei `<style>`. Dal primo file migrato l'insieme si popola da sé.
    expect(usiDeiTokenSemantici())->toBeArray();
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

it('never lets a view use a semantic token the theme does not define', function () {
    $definiti = tokenSemanticiDefiniti();

    $mancanti = collect(usiDeiTokenSemantici())->except($definiti);

    expect($mancanti->keys()->all())->toBe([], $mancanti->isEmpty() ? '' :
        "Token SEMANTICI usati nelle viste e non definiti in `resources/css/app.css`.\n\n".
        "⚠️ `surface`, `ink`, `brand`, `ok`… sono nomi NOSTRI: una classe che li nomina senza che il\n".
        "token esista non genera NESSUNA regola. Non è un colore sbagliato, è una card senza fondo o\n".
        "un testo che eredita il colore del genitore — e la pagina risponde 200.\n\n".
        "Due rimedi, e la scelta si fa su DS §8.2 (la tabella dei token, coi due valori affiancati):\n".
        "  · il token è nella tabella → si dichiara in `@theme` COME ALIAS (`--color-x: var(--x)`) e\n".
        "    la variabile grezza `--x` va dichiarata in `:root` E nei due blocchi scuri E in\n".
        "    `@media print`. 🛡️ TemaScuroGuardrailTest verifica che non se ne dimentichi uno;\n".
        "  · il token NON è nella tabella → è la VISTA a sbagliare nome. Inventare qui un ruolo che\n".
        "    il Design System non ha significa decidere lo strato semantico senza verifica visiva.\n\n".
        'Trovati: '.$mancanti->map(fn (array $file, string $t) => $t.' ('.implode(', ', array_unique($file)).')')->implode("\n           ")
    );
});

it('reads the theme and not the rest of the stylesheet', function () {
    // ⚠️ **Il guardrail del guardrail.** `app.css` USA `var(--color-neutral-200)`
    // fuori da `@theme` — nel `:root` dello strato semantico, dove `--border` vi
    // attinge — e se l'estrazione leggesse tutto il foglio un token soltanto
    // *usato* passerebbe per *definito*, rendendo verde il test più importante di
    // questo file proprio sul caso che deve vedere.
    // ⚠️ *La premessa si è spostata il 26 Ago 2026*: prima l'uso stava dentro
    // `@utility tabella-a-card`, che ADR-034 ha tokenizzato. È la premessa ad
    // essere stata riverificata, non l'asserzione ad essere stata adattata — se
    // un giorno nessun `var(--color-*)` vivesse più fuori da `@theme`, questo
    // test andrebbe **cancellato dicendo che il caso non esiste più**, non
    // reso verde su un altro token.
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
    // sarebbe verde per assenza di caso. Vale per **entrambi** gli strati.
    $sintetico = <<<'CSS'
    @theme {
        --color-brand-500: #123456;
        --color-surface: var(--surface);
    }

    :root {
        --color-brand-900: #000000;
        --color-surface-sunken: #eeeeee;
    }
    CSS;

    expect(tonalitaDefiniteNelTema($sintetico))->toBe(['brand-500'])
        ->and(tokenSemanticiDefiniti($sintetico))->toBe(['surface']);
});

it('keeps the single flat @theme block that every extraction here assumes', function () {
    // 🔴 **Il contratto su cui poggiano tre reti, e che finora nessuno
    // verificava.** `tonalitaDefiniteNelTema()` e `nomiColoreDefinitiNelTema()`
    // estraggono con `/@theme\s*\{(.*?)\n\}/s`: leggono il **primo** blocco e
    // si fermano alla **prima** `\n}`. Due conseguenze, entrambe silenziose:
    //
    //  · un **secondo** `@theme` sarebbe ignorato per intero, e ogni token
    //    dichiarato lì dentro risulterebbe «non definito» — o, peggio, i suoi usi
    //    resterebbero invisibili se ci finisse un'intera famiglia;
    //  · una graffa **annidata** (un `@media`, un `&:hover`) rompe l'estrazione in
    //    un modo che dipende dall'indentazione, ed è la parte insidiosa: con la
    //    formattazione di questo progetto — la graffa interna rientrata — il
    //    blocco non si tronca affatto, si **allarga**, e i token dichiarati dentro
    //    la media query risultano definiti **sempre**. La rete direbbe «c'è» su un
    //    colore che esiste solo sopra i 640px. Con la graffa interna a colonna 0
    //    l'errore è l'opposto: il blocco si tronca a metà elenco.
    //
    // In tutti e due i casi il rimedio sarebbe cambiare l'estrazione; questo test
    // esiste perché il giorno in cui serve, qualcuno lo sappia. Il commento in
    // testa ad `app.css` dice la stessa cosa a chi scrive il CSS: qui diventa una
    // riga che si rompe.
    $css = file_get_contents(resource_path('css/app.css'));

    // ⚠️ Si contano le **aperture di blocco**, non le occorrenze della parola:
    // `@theme` compare sei volte in `app.css`, cinque delle quali nei commenti
    // che spiegano proprio questa regola. Un contatore ingenuo sarebbe rosso il
    // giorno stesso, e verrebbe cancellato invece che capito.
    expect(preg_match_all('/@theme\s*\{/', $css))->toBe(1, 'resources/css/app.css deve avere UN SOLO blocco @theme');

    preg_match('/@theme\s*\{(.*?)\n\}/s', $css, $blocco);

    expect($blocco)->not->toBeEmpty()
        // ⚠️ `toContain()` è **variadico** e non prende un messaggio: la
        // spiegazione starebbe dentro l'ago. Sta nel commento qui sopra.
        ->and($blocco[1])->not->toContain('{');

    // E la prova che il guasto è reale, non temuto: su un foglio annidato con la
    // formattazione di questo progetto, `brand-600` — che esiste solo dentro la
    // media query — risulta definito senza condizioni.
    $annidato = <<<'CSS'
    @theme {
        --color-brand-500: #123456;
        @media (min-width: 40rem) {
            --color-brand-600: #000000;
        }
    }
    CSS;

    expect(tonalitaDefiniteNelTema($annidato))->toBe(['brand-500', 'brand-600']);
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

it('tells overlapping semantic names apart instead of matching on a prefix', function () {
    // 🔴 Il caso per cui questa metà poteva nascere già rotta. `surface` e
    // `surface-sunken`, `ink` e `ink-2`, `brand` e `brand-soft-ink` **si
    // sovrappongono**: un rilevatore per prefisso leggerebbe `bg-surface-sunken`
    // come un uso di `surface`, cioè direbbe «definito» su un token che nessuno
    // ha dichiarato — e il test più importante di questa metà diventerebbe verde
    // proprio sul caso che deve vedere.
    $famiglie = ['surface', 'ink', 'brand', 'ok', 'chart', 'ring'];

    expect(tokenSemanticiUsatiNelSorgente('<div class="bg-surface">x</div>', $famiglie))->toBe(['surface'])
        ->and(tokenSemanticiUsatiNelSorgente('<div class="bg-surface-sunken">x</div>', $famiglie))->toBe(['surface-sunken'])
        ->and(tokenSemanticiUsatiNelSorgente('<p class="text-ink">x</p>', $famiglie))->toBe(['ink'])
        // ⚠️ Il numero a UNA cifra: `ink-2` è un token semantico, non la tonalità
        // `2` di una famiglia `ink`. Verificato e non dato per buono — è la
        // differenza fra `\d{2,3}` e `\d+`, e con `\d+` questo uso corretto
        // risulterebbe una violazione.
        ->and(tokenSemanticiUsatiNelSorgente('<p class="text-ink-2">x</p>', $famiglie))->toBe(['ink-2'])
        ->and(tonalitaUsateNelSorgente('<p class="text-ink-2">x</p>', ['ink']))->toBe([])
        ->and(tokenSemanticiUsatiNelSorgente('<p class="text-brand-soft-ink">x</p>', $famiglie))->toBe(['brand-soft-ink'])
        // Varianti, lati, opacità e `!`: la classe resta la stessa.
        ->and(tokenSemanticiUsatiNelSorgente('<a class="md:hover:bg-brand-hover">x</a>', $famiglie))->toBe(['brand-hover'])
        ->and(tokenSemanticiUsatiNelSorgente('<div class="border-t-brand-line">x</div>', $famiglie))->toBe(['brand-line'])
        ->and(tokenSemanticiUsatiNelSorgente('<div class="bg-ok-soft/50">x</div>', $famiglie))->toBe(['ok-soft'])
        ->and(tokenSemanticiUsatiNelSorgente('<div class="ring-ring">x</div>', $famiglie))->toBe(['ring'])
        ->and(tokenSemanticiUsatiNelSorgente('<svg class="stroke-chart-grid fill-chart-band">x</svg>', $famiglie))->toBe(['chart-band', 'chart-grid']);

    // 🔴 Il caso che dà senso a tutta la metà: un token **inventato** su una
    // famiglia nostra viene visto, quindi può essere giudicato «non definito».
    // Cercare l'insieme dei nomi definiti lo renderebbe invisibile.
    expect(tokenSemanticiUsatiNelSorgente('<div class="bg-surface-raised">x</div>', $famiglie))->toBe(['surface-raised']);

    // Ciò che NON deve essere visto.
    expect(tokenSemanticiUsatiNelSorgente('<style>.bg-surface{background:#fff}</style>', $famiglie))->toBe([])
        ->and(tokenSemanticiUsatiNelSorgente('{{-- non usiamo più `bg-surface-code` --}}', $famiglie))->toBe([])
        // Una famiglia che non è nostra: `ink` sì, `inkscape` no.
        ->and(tokenSemanticiUsatiNelSorgente('<p class="text-inkscape">x</p>', $famiglie))->toBe([])
        // Le utility native che cominciano per un nome nostro senza esserlo.
        ->and(tokenSemanticiUsatiNelSorgente('<div class="ring-offset-2 border-collapse">x</div>', $famiglie))->toBe([])
        // Ciò che finisce con un gradino è affare dell'altra metà.
        ->and(tokenSemanticiUsatiNelSorgente('<div class="bg-brand-500">x</div>', $famiglie))->toBe([]);
});
