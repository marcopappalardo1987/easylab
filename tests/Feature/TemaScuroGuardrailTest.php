<?php

/**
 * 🔴 Meta-test del **tema scuro**: ogni token semantico ha un valore in tutti e
 * quattro i contesti, e i blocchi duplicati a mano non divergono
 * (🔗 `docs/Design/Design System Base.md` §8.2/§8.3, ADR-034).
 *
 * ## Il guasto che questo file esiste per rendere rumoroso
 *
 * 🔴 **Un token definito solo nel chiaro non dà errore: resta chiaro sul fondo
 * scuro, in silenzio.** È la stessa forma di tutte le trappole di colore di
 * questo progetto — pagina 200, markup giusto, colore sbagliato — ma con un
 * sintomo peggiore, perché un `--surface` dimenticato non produce una card
 * *sbiadita*: produce una **card bianca sul fondo blu notte**, cioè testo chiaro
 * su bianco, cioè una schermata illeggibile che nessun test funzionale vede.
 *
 * 🔴 **E i blocchi sono TRE copie a mano dello stesso elenco.** `app.css`
 * dichiara i valori semantici quattro volte:
 *
 *  1. `:root` — il tema **chiaro**, che è la base (non esiste un
 *     `[data-theme="light"]` coi propri valori: DS §8.3);
 *  2. `@media (prefers-color-scheme: dark) :root:not([data-theme='light'])` —
 *     lo scuro per **chi non ha scelto**;
 *  3. `:root[data-theme='dark']` — lo scuro per **chi ha scelto**;
 *  4. `@media print` — la stampa, che è **sempre chiara** qualunque cosa dica
 *     `data-theme`.
 *
 * I due blocchi scuri sono duplicati **di proposito** (un blocco solo non può
 * servire entrambi i pubblici), e due copie che divergono sono il difetto che
 * questo progetto ha già pagato più volte — le due definizioni di «documenti
 * della macchina», le due di «casella vuota». La differenza è che qui la
 * divergenza non rompe niente: dà semplicemente un colore diverso a chi ha
 * *scelto* lo scuro rispetto a chi lo *subisce* dal sistema operativo, cioè a
 * due utenti che non si parlano mai.
 *
 * 🔴 **Il caso della stampa merita una riga sua, perché era un difetto vero.**
 * Il blocco `@media print` ha lo **stesso selettore** dei blocchi scuri e vince
 * solo perché viene dopo — ma vince **soltanto sulle proprietà che dichiara**.
 * Ciò che non ridichiara conserva il valore scuro. Il 26 Ago 2026 questo file
 * ha trovato **nove token** in quella condizione (gli otto `--chart-*` e
 * `--ring`): stampando col tema scuro attivo, un grafico usciva con la griglia
 * blu notte e una banda `#0E2740` — quasi nera — attraverso il foglio. Si vede
 * **solo** stampando dal tema scuro, cioè quasi mai, finché non capita al
 * cliente. Corretto in `app.css` nello stesso giro.
 *
 * ## Perché tutto si deriva da `app.css`
 *
 * ⚠️ Un elenco di token scritto qui dentro sarebbe la **quinta copia** della
 * tabella di DS §8.2, e la copia che diverge è sempre quella che nessuno guarda.
 * Questo file non dichiara nessun nome: legge i quattro blocchi e li confronta
 * fra loro. Il giorno in cui nascesse un `--surface-raised`, entra nei controlli
 * da solo.
 *
 * ## Ciò che questo file NON copre, e va detto
 *
 * - **I VALORI non sono giudicati**, mai: qui si verifica che ogni nome abbia
 *   *un* valore in ogni contesto, non che il valore sia leggibile. Il contrasto
 *   in tema scuro resta una verifica **visiva e manuale** (DS §8.5), e ADR-034
 *   lo dice fra le proprie conseguenze: «la superficie di verifica raddoppia».
 *   L'unico confronto di valore è **fra i due blocchi scuri**, e non chiede che
 *   siano giusti: chiede che siano **uguali**.
 * - **Non verifica che una vista usi il token giusto**: quello è
 *   `SuperficiTokenizzateGuardrailTest`. Un `bg-white` scritto a mano ha tutti i
 *   token a posto e resta bianco lo stesso.
 * - **Non costruisce il CSS**: legge il sorgente. Che Tailwind generi davvero
 *   `.bg-surface` da `--color-surface` è materia di `PaletteGuardrailTest` e del
 *   build, non di qui.
 */

/**
 * Il corpo del primo blocco `{…}` la cui apertura corrisponde alla regex data.
 *
 * ⚠️ **Conta le graffe invece di fidarsi di una regex**, e non è pignoleria: i
 * blocchi che servono qui sono **annidati** (`@media { :root { … } }`), e un
 * `/@media print\s*\{(.*?)\}/s` si fermerebbe alla prima `}` — cioè alla fine
 * del blocco *interno*, restituendo mezzo elenco. Un mezzo elenco non dà errore:
 * dà un confronto che passa perché non ha visto la parte mancante, che è
 * esattamente il modo in cui questo file potrebbe diventare cieco.
 *
 * @param  string|null  $resto  il foglio **senza** il blocco estratto, per poter
 *                              cercare il successivo senza rischiare di
 *                              ripescare un selettore annidato
 */
function corpoDelBlocco(string $css, string $regexApertura, ?string &$resto = null): string
{
    $trovato = preg_match($regexApertura, $css, $apertura, PREG_OFFSET_CAPTURE);

    // Non un `?? ''`: un blocco che non si trova più darebbe insieme vuoto, e
    // ogni confronto di questo file diventerebbe verde per vuoto. Meglio
    // rompersi qui, dove si legge il perché.
    expect($trovato)->toBe(1, "Blocco non trovato in resources/css/app.css: {$regexApertura}");

    $inizio = $apertura[0][1] + strlen($apertura[0][0]);
    $livello = 1;
    $i = $inizio;

    while ($livello > 0 && $i < strlen($css)) {
        $livello += match ($css[$i]) {
            '{' => 1,
            '}' => -1,
            default => 0,
        };
        $i++;
    }

    expect($livello)->toBe(0, "Graffe non bilanciate dopo {$regexApertura} in resources/css/app.css");

    $resto = substr($css, 0, $apertura[0][1]).substr($css, $i);

    return substr($css, $inizio, $i - 1 - $inizio);
}

/**
 * Le variabili CSS dichiarate in un blocco, `nome => valore`.
 *
 * ⚠️ Il `\s*:` dopo il nome è ciò che distingue una **dichiarazione** da un
 * **riferimento**: in `--border: var(--color-neutral-200);` il nome dichiarato è
 * `--border`, mentre `--color-neutral-200` è solo letto. Senza quel vincolo il
 * secondo entrerebbe nell'elenco e ogni blocco risulterebbe dichiarare token che
 * non dichiara — con la conseguenza che i confronti passerebbero per motivi
 * sbagliati.
 *
 * @return array<string, string>
 */
function variabiliDelBlocco(string $blocco): array
{
    preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $blocco, $trovate, PREG_SET_ORDER);

    $variabili = [];

    foreach ($trovate as $riga) {
        // Lo spazio si normalizza perché un valore può essere spezzato su più
        // righe: due blocchi identici formattati in modo diverso sono identici.
        $variabili[$riga[1]] = preg_replace('/\s+/', ' ', trim($riga[2]));
    }

    ksort($variabili);

    return $variabili;
}

/**
 * I quattro blocchi che dichiarano lo strato semantico, più il `@theme`.
 *
 * Le chiavi sono in italiano perché finiscono nei messaggi d'errore, che li
 * legge chi ha appena rotto qualcosa.
 *
 * @return array<string, array<string, string>>
 */
function blocchiDelTema(?string $css = null): array
{
    $css ??= file_get_contents(resource_path('css/app.css'));

    // ⚠️ **L'ordine non è indifferente.** I due `@media` si tolgono per primi
    // perché contengono a loro volta un `:root`: il blocco di stampa apre con
    // `:root, :root[data-theme='dark']`, quindi cercare `:root[data-theme='dark']`
    // sul foglio intero potrebbe prendere **quello della stampa** al posto del
    // blocco scuro vero, e il confronto «i due scuri sono uguali» diventerebbe
    // «lo scuro è uguale alla stampa» — verde per la ragione sbagliata.
    $scuroMedia = corpoDelBlocco($css, '/@media\s*\(\s*prefers-color-scheme\s*:\s*dark\s*\)\s*\{/', $senzaScuro);
    $stampa = corpoDelBlocco($senzaScuro, '/@media\s+print\s*\{/', $senzaStampa);
    $chiaro = corpoDelBlocco($senzaStampa, '/:root\s*\{/', $senzaChiaro);
    $scuroAttributo = corpoDelBlocco($senzaChiaro, "/:root\[data-theme='dark'\]\s*\{/");

    return [
        '@theme' => variabiliDelBlocco(corpoDelBlocco($css, '/@theme\s*\{/')),
        'chiaro (`:root`)' => variabiliDelBlocco($chiaro),
        'scuro di sistema (`@media prefers-color-scheme: dark`)' => variabiliDelBlocco($scuroMedia),
        "scuro scelto (`:root[data-theme='dark']`)" => variabiliDelBlocco($scuroAttributo),
        'stampa (`@media print`)' => variabiliDelBlocco($stampa),
    ];
}

/** I due blocchi scuri, che devono restare gemelli. @return array<string, array<string, string>> */
function blocchiScuri(?string $css = null): array
{
    return collect(blocchiDelTema($css))->filter(
        fn (array $variabili, string $nome) => str_starts_with($nome, 'scuro')
    )->all();
}

it('finds all four blocks, so no comparison can pass for being empty', function () {
    // 🔴 Tutto questo file è derivato da quattro regex e da un contatore di
    // graffe. Se una smettesse di trovare il proprio blocco, ogni confronto
    // diventerebbe `[] ⊆ []`, cioè verde **per sempre** e proprio mentre il tema
    // scuro va in pezzi. È la stessa rete che `RetentionTest` mette davanti al
    // proprio glob e `SorgentiTailwindGuardrailTest` davanti ai propri `@source`.
    $blocchi = blocchiDelTema();

    expect($blocchi)->toHaveCount(5);

    foreach ($blocchi as $nome => $variabili) {
        // Venti è una soglia grossolana di proposito: non è il numero dei token
        // (che cambierà), è la prova che l'estrazione ha letto un elenco e non
        // le prime due righe di un blocco chiuso male dal contatore di graffe.
        expect(count($variabili))->toBeGreaterThan(20, "Il blocco «{$nome}» dichiara troppo poco: l'estrazione ha probabilmente letto solo una parte");
    }
});

it('keeps the two dark blocks identical, name by name and value by value', function () {
    // ⚠️ **Sono duplicati di proposito e devono restare gemelli.** La media query
    // serve a chi non ha scelto, `[data-theme='dark']` a chi ha scelto: un blocco
    // solo non può fare entrambe le cose (DS §8.3). Ma servono lo **stesso**
    // risultato, quindi una divergenza qui non rompe niente — dà semplicemente
    // due tonalità di scuro a due utenti che non si parleranno mai, e nessuno se
    // ne accorge.
    [$primo, $secondo] = array_values(blocchiScuri());
    [$nomePrimo, $nomeSecondo] = array_keys(blocchiScuri());

    $solo = fn (array $a, array $b) => array_values(array_diff(array_keys($a), array_keys($b)));

    expect($solo($primo, $secondo))->toBe([], "Token dichiarati in «{$nomePrimo}» e non in «{$nomeSecondo}».")
        ->and($solo($secondo, $primo))->toBe([], "Token dichiarati in «{$nomeSecondo}» e non in «{$nomePrimo}».");

    $divergenti = [];

    foreach ($primo as $nome => $valore) {
        if (($secondo[$nome] ?? null) !== $valore) {
            $divergenti[] = $nome.': '.$valore.' ≠ '.($secondo[$nome] ?? '(assente)');
        }
    }

    expect($divergenti)->toBe([],
        "I due blocchi scuri di `resources/css/app.css` danno valori diversi allo stesso token.\n\n".
        "⚠️ Non è un errore visibile: chi ha SCELTO lo scuro e chi lo SUBISCE dal sistema operativo\n".
        "vedrebbero due interfacce leggermente diverse, e non si incontrano mai per accorgersene.\n".
        'Sono due copie a mano dello stesso elenco (DS §8.3): vanno riallineate a mano.'
    );
});

it('never lets a token exist in the light theme and vanish in the dark one', function () {
    // 🔴 Il guasto centrale di ADR-034: un token dichiarato solo nel chiaro
    // **non dà errore**, resta al valore chiaro sul fondo scuro. Un `--surface`
    // dimenticato non è una card sbiadita, è una card BIANCA sul blu notte.
    $chiaro = blocchiDelTema()['chiaro (`:root`)'];

    $mancanti = [];

    foreach (blocchiScuri() as $nome => $variabili) {
        foreach (array_keys(array_diff_key($chiaro, $variabili)) as $token) {
            $mancanti[] = $token.' (manca in: '.$nome.')';
        }
    }

    expect($mancanti)->toBe([],
        "Token dichiarati nel tema chiaro e non ridichiarati nel tema scuro.\n\n".
        "⚠️ Non danno errore: conservano il valore CHIARO sul fondo scuro. Il sintomo è una superficie\n".
        "bianca in mezzo a una pagina blu notte, o un testo `neutral-900` su `#0A1220` — illeggibile,\n".
        "e invisibile a ogni test funzionale perché la pagina risponde 200.\n\n".
        'Rimedio: DS §8.2 dà il valore scuro di ogni token. Va scritto in ENTRAMBI i blocchi scuri.'
    );
});

it('never lets the print block leave a dark value on paper', function () {
    // 🔴 **Il difetto vero trovato da questo file il 26 Ago 2026.** Il blocco di
    // stampa ha lo stesso selettore dei blocchi scuri e vince solo perché viene
    // dopo — ma vince **soltanto sulle proprietà che dichiara**. Ciò che non
    // ridichiara conserva il valore scuro, e si vede solo stampando **col tema
    // scuro attivo**: cioè quasi mai, finché non capita al cliente.
    //
    // Mancavano gli otto `--chart-*` e `--ring`: un grafico stampato dal tema
    // scuro usciva con la griglia blu notte e `--chart-band` a `#0E2740`, una
    // fascia quasi nera attraverso il foglio.
    $stampa = blocchiDelTema()['stampa (`@media print`)'];

    $scoperti = [];

    foreach (blocchiScuri() as $nome => $variabili) {
        foreach (array_keys(array_diff_key($variabili, $stampa)) as $token) {
            $scoperti[$token] = $token;
        }
    }

    expect(array_values($scoperti))->toBe([],
        "Token che il tema scuro sovrascrive e che `@media print` non riporta al chiaro.\n\n".
        "⚠️ Il blocco di stampa condivide il selettore coi blocchi scuri e vince SOLO sulle proprietà\n".
        "che dichiara: ciò che tace conserva il valore scuro. Il foglio esce con una campitura scura\n".
        "che consuma toner e non dice niente — e succede solo a chi stampa dal tema scuro.\n\n".
        'Rimedio: ridichiarare il token in `@media print`, col valore del tema CHIARO (DS §8.4).'
    );
});

it('never lets a theme alias point at a variable nobody declares', function () {
    // ⚠️ **Un alias che punta al nulla non dà errore: dà una proprietà senza
    // valore.** `--color-surface: var(--surface)` con `--surface` mai dichiarato
    // produce `background-color: ;`, che il browser scarta in silenzio — la card
    // eredita il fondo del genitore e la pagina risponde 200. È lo stesso
    // sintomo della tonalità assente, spostato di un livello: là mancava la
    // classe, qui manca il valore dietro la classe.
    $blocchi = blocchiDelTema();
    $tema = $blocchi['@theme'];
    $chiaro = $blocchi['chiaro (`:root`)'];

    $css = file_get_contents(resource_path('css/app.css'));
    preg_match_all(
        '/(--[a-z0-9-]+)\s*:\s*var\((--[a-z0-9-]+)\)\s*;/',
        corpoDelBlocco($css, '/@theme\s*\{/'),
        $alias,
        PREG_SET_ORDER
    );

    // Anti-vuoto locale: `@theme` è quasi tutto alias, e zero alias trovati
    // significherebbe che la regex non riconosce più la forma — con il controllo
    // qui sotto verde per vuoto.
    expect(count($alias))->toBeGreaterThan(20);

    $rotti = [];

    foreach ($alias as [, $token, $bersaglio]) {
        // Due destinazioni legittime, e la distinzione è la sostanza dei due
        // strati di ADR-034: un alias verso `--color-*` punta a una **scala**,
        // che vive in `@theme` e non cambia col tema; ogni altro punta a una
        // variabile **grezza** dello strato semantico, che vive in `:root` (e
        // che gli altri blocchi riscrivono). Accettarle indistintamente
        // renderebbe verde un `--color-surface: var(--color-surface-2)` che non
        // esiste da nessuna parte.
        $atteso = str_starts_with($bersaglio, '--color-') ? '@theme' : ':root';
        $dichiarato = $atteso === '@theme' ? isset($tema[$bersaglio]) : isset($chiaro[$bersaglio]);

        if (! $dichiarato) {
            $rotti[] = $token.' → var('.$bersaglio.') — atteso in '.$atteso;
        }
    }

    expect($rotti)->toBe([],
        "Alias di `@theme` che puntano a una variabile che nessuno dichiara.\n\n".
        "⚠️ Non danno errore: producono una proprietà con valore vuoto, che il browser scarta. La\n".
        "classe esiste, la pagina risponde 200, e il colore semplicemente non c'è.\n\n".
        "Rimedio: la variabile grezza va dichiarata in `:root` (e quindi anche nei due blocchi scuri\n".
        'e in `@media print`), oppure l\'alias va corretto. DS §8.2 dà la tabella completa.'
    );
});

it('keeps the three selectors that make the switch work in both directions', function () {
    // ⚠️ **`:not([data-theme='light'])` non è pedanteria** (DS §8.3): senza,
    // una scelta esplicita di *chiaro* verrebbe sovrascritta dal sistema
    // operativo, cioè l'interruttore funzionerebbe in una sola direzione. E il
    // blocco di stampa deve nominare **entrambi** i selettori, o chi ha scelto
    // lo scuro stamperebbe scuro comunque.
    // ⚠️ `toContain()` è **variadico**: la spiegazione non può stargli dentro
    // come secondo argomento — diventerebbe un secondo ago cercato nel CSS. Sta
    // qui, in un commento.
    $css = file_get_contents(resource_path('css/app.css'));

    $scuroMedia = corpoDelBlocco($css, '/@media\s*\(\s*prefers-color-scheme\s*:\s*dark\s*\)\s*\{/');
    $stampa = corpoDelBlocco($css, '/@media\s+print\s*\{/');

    expect($scuroMedia)->toContain(":root:not([data-theme='light'])")
        ->and($stampa)->toContain(':root,')
        ->and($stampa)->toContain(":root[data-theme='dark']");

    // ⚠️ **`color-scheme` è l'unica riga che parla al browser invece che alla
    // pagina**: senza, scrollbar, `<select>`, date picker e campi nativi restano
    // chiari sul fondo scuro. Non è decorazione, ed è facilissima da perdere in
    // un riordino perché non è un token e non compare in nessuna tabella.
    $blocchi = blocchiDelTema();

    foreach (['chiaro (`:root`)', 'scuro di sistema (`@media prefers-color-scheme: dark`)', "scuro scelto (`:root[data-theme='dark']`)", 'stampa (`@media print`)'] as $nome) {
        expect($blocchi)->toHaveKey($nome);
    }

    foreach ([$scuroMedia, $stampa] as $blocco) {
        expect($blocco)->toContain('color-scheme:');
    }
});

it('would notice a token missing from one dark block, proved on a synthetic stylesheet', function () {
    // 🔴 Il guardrail del guardrail. I confronti qui sopra sono verdi sul foglio
    // vero, quindi da soli non dimostrano di **saper** diventare rossi: un
    // estrattore che restituisse sempre gli stessi nomi per tutti e quattro i
    // blocchi darebbe esattamente lo stesso risultato. Il caso si costruisce, non
    // si spera che il repository lo contenga — è la forma di
    // `ScrittureRbacGuardrailTest` e del foglio sintetico di `PaletteGuardrailTest`.
    $sintetico = <<<'CSS'
    @theme {
        --color-surface: var(--surface);
        --color-ink: var(--fantasma);
    }

    :root {
        color-scheme: light;
        --surface: #ffffff;
        --ink: #0f172a;
    }

    @media (prefers-color-scheme: dark) {
        :root:not([data-theme='light']) {
            color-scheme: dark;
            --surface: #111c2e;
            --ink: #e8eef6;
        }
    }

    :root[data-theme='dark'] {
        color-scheme: dark;
        --surface: #101b2d;
    }

    @media print {
        :root,
        :root[data-theme='dark'] {
            color-scheme: light;
            --surface: #ffffff;
        }
    }
    CSS;

    $blocchi = blocchiDelTema($sintetico);
    $chiaro = $blocchi['chiaro (`:root`)'];
    $scuri = array_values(blocchiScuri($sintetico));

    // Il contatore di graffe ha davvero attraversato l'annidamento: il blocco
    // della media query è quello interno, non `@media { … }` mezzo letto.
    expect(array_keys($chiaro))->toBe(['--ink', '--surface'])
        ->and(array_keys($scuri[0]))->toBe(['--ink', '--surface']);

    // ① un nome che manca a un solo blocco scuro si vede;
    expect(array_keys(array_diff_key($chiaro, $scuri[1])))->toBe(['--ink']);

    // ② due valori diversi per lo stesso nome si vedono;
    expect($scuri[0]['--surface'])->not->toBe($scuri[1]['--surface']);

    // ③ un token che lo scuro sovrascrive e la stampa non riporta si vede;
    expect(array_keys(array_diff_key($scuri[0], $blocchi['stampa (`@media print`)'])))->toBe(['--ink']);

    // ④ e un alias verso una variabile inesistente si vede.
    expect($chiaro)->not->toHaveKey('--fantasma');
});
