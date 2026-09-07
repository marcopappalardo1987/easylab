<?php

use App\Support\Guide\TestiScritti;

/**
 * 🔴 Meta-test dei **testi scritti** delle guide.
 *
 * ## Che cosa può rompersi, e perché in silenzio
 *
 * La prosa della pagina sta in `guide/testi/{slug}.md`, la sequenza dei passi
 * nel `manifest.json` che produce il copione: due file, e quindi due modi di
 * sfasarsi.
 *
 * - **Un passo aggiunto al copione** e non qui → quel passo, in pagina, torna a
 *   mostrare la didascalia del video. `Manuale::componi()` ripiega apposta, per
 *   non lasciare una riga vuota: la degradazione è voluta ed è **muta**, quindi
 *   senza questo test nessuno se ne accorgerebbe.
 * - **Un passo tolto dal copione** e non qui → un testo orfano, che non
 *   comparirà mai in nessuna pagina e resterà a invecchiare nel file.
 *
 * ## Perché si contano i copioni e non i manifest
 *
 * ⚠️ Perché il `manifest.json` vive **sul disco delle guide** insieme agli mp4,
 * cioè su un bucket, e in CI non c'è. Un test che lo leggesse sarebbe verde per
 * assenza di dati — la forma di test peggiore, perché sembra una rete. I
 * copioni invece sono nel repository, e sono anche la vera fonte della
 * numerazione: `Regista::passo()` incrementa un contatore a ogni chiamata.
 */
it('has a written text file for every guide in the catalogue', function () {
    foreach (config('guide.guide') as $voce) {
        expect(base_path("guide/testi/{$voce['slug']}.md"))
            ->toBeFile("La guida «{$voce['slug']}» è a catalogo ma non ha i testi scritti.");
    }
});

it('numbers the written texts exactly like the shooting scripts', function () {
    foreach (config('guide.guide') as $voce) {
        $slug = $voce['slug'];

        $copione = file_get_contents(base_path("guide/flussi/{$slug}.spec.ts"));
        $passi = substr_count((string) $copione, 'g.passo(');

        expect($passi)->toBeGreaterThan(0);

        $testi = TestiScritti::per($slug);

        // Le due direzioni, e sbagliano in modo diverso: a sinistra un passo
        // che in pagina resterebbe la sola riga breve, a destra un testo che
        // non vedrà mai nessuno.
        expect(array_keys($testi['passi']))->toBe(range(1, $passi));

        // Stessa cosa per i cartelli: `g.capitolo()` è la loro numerazione.
        $capitoli = substr_count((string) $copione, 'g.capitolo(');

        expect(array_keys($testi['capitoli']))->toBe(range(1, $capitoli));
        expect($testi['premessa'])->not->toBeNull();
    }
});

it('writes something longer than a caption, which is the whole point', function () {
    // La soglia non misura la qualità, misura l'INTENZIONE: un testo copiato
    // dalla didascalia del video sta sotto, uno scritto per essere letto senza
    // l'immagine accanto sta sopra. Serve a impedire che, aggiungendo una guida
    // di fretta, i due testi tornino a essere lo stesso.
    foreach (config('guide.guide') as $voce) {
        foreach (TestiScritti::per($voce['slug'])['passi'] as $n => $testo) {
            expect(mb_strlen($testo))
                ->toBeGreaterThan(150, "Il passo {$n} di «{$voce['slug']}» è lungo come una didascalia.");
        }
    }
});

it('drops the preamble and folds each block into one paragraph', function () {
    $testi = TestiScritti::analizza(<<<'MD'
        <!-- Questo commento spiega il file a chi lo apre, e non va in pagina. -->

        ## premessa

        A chi serve.

        ## capitolo 1

        Perché questa parte esiste.

        ## 1

        Prima riga.
        Seconda riga.

        ## 2

        Solo questo.
        MD);

    expect($testi)->toBe([
        'premessa' => 'A chi serve.',
        'capitoli' => [1 => 'Perché questa parte esiste.'],
        'passi' => [1 => 'Prima riga. Seconda riga.', 2 => 'Solo questo.'],
    ]);
});

it('never confuses a chapter number with a step number', function () {
    // ⚠️ `## capitolo 1` e `## 1` sono due blocchi diversi con lo stesso numero:
    // un parser che leggesse la sola cifra li sovrascriverebbe a vicenda, e in
    // pagina comparirebbe l'apertura del capitolo al posto del primo passo.
    $testi = TestiScritti::analizza("## capitolo 1\nIl cartello.\n\n## 1\nIl passo.");

    expect($testi['capitoli'])->toBe([1 => 'Il cartello.'])
        ->and($testi['passi'])->toBe([1 => 'Il passo.']);
});

it('ignores a step left empty instead of publishing a blank line', function () {
    // Un `## 2` senza niente sotto è una dimenticanza di chi scrive, non la
    // volontà di un passo muto: tenendolo, la pagina mostrerebbe una riga
    // vuota; buttandolo, si ripiega sulla didascalia e il guardrail sopra
    // diventa rosso.
    expect(TestiScritti::analizza("## 1\nC'è.\n\n## 2\n\n")['passi'])->toBe([1 => "C'è."]);
});
