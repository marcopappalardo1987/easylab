<?php

use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piattaforma\SerieMensile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * 🔴 I tre componenti grafico della cabina di regia: accessibilità e token
 * (🔗 `docs/Design/Design System Base.md` §2.5/§4/§8.2, ADR-034).
 *
 * ## Perché un file di test per tre SVG
 *
 * Il vincolo di ADR-034 sui grafici è una frase — *mai il solo colore* — e una
 * frase non è una rete. In tema scuro la coppia peggiore dei token dei grafici,
 * verde ↔ arancione, scende a **ΔE 6,9** (DS §2.5): sotto la soglia di
 * sicurezza. Quel ΔE è accettato **solo** perché ogni voce porta anche il glifo
 * e l'etichetta, esattamente come `x-ui.semaforo`. Senza le asserzioni di questo
 * file, quella clausola vivrebbe in un commento — e il giorno in cui qualcuno
 * «semplifica» la legenda a soli quadratini colorati, la suite resta verde.
 *
 * ## Il guardrail sui token, e i buchi che chiude
 *
 * 🔴 **Un SVG tradisce ADR-034 in tre modi, e nessuna rete esistente ne vede
 * neanche uno.** `PaletteGuardrailTest` cerca **classi** — i suoi prefissi
 * includono già `fill` e `stroke` — quindi una tinta scritta in un *attributo*
 * lo rende cieco. `SuperficiTokenizzateGuardrailTest` cerca classi di **scala**,
 * non stili, e lo dichiara fra i propri buchi: un esadecimale crudo dentro un
 * `fill=` non lo vede **nessuno**. E la variante di tema scritta a mano è un
 * divieto che il progetto tiene *senza rete*, per assenza di casi.
 *
 * Qui i casi ci sono, quindi la rete si scrive.
 *
 * ⚠️ **Le asserzioni negative vanno un ago alla volta**: `toContain()` è
 * VARIADICO e un secondo argomento diventa un secondo ago, non un messaggio.
 * Questo progetto ci ha già perso un guardrail di privacy intero, due volte
 * nello stesso giorno. La spiegazione sta nel nome del test e nei commenti.
 */

/** I tre componenti nuovi, letti da disco: è il sorgente che si giudica. */
const COMPONENTI_GRAFICO = [
    'resources/views/components/ui/sparkline.blade.php',
    'resources/views/components/ui/grafico-barre.blade.php',
    'resources/views/components/ui/barra-composizione.blade.php',
];

function serieDiProva(): SerieMensile
{
    return new SerieMensile(
        ['2026-06', '2026-07', '2026-08'],
        ['Giu', 'Lug', 'Ago'],
        [4, 0, 9],
    );
}

// ─── L'accessibilità, che qui è la funzione e non la rifinitura ──────────────

it('hides the decorative sparkline from screen readers', function () {
    // La sparkline non porta informazione che non sia già scritta a parole
    // accanto («+7 in 12 mesi»): darle un `role="img"` la farebbe annunciare due
    // volte, e la seconda senza numeri.
    $html = Blade::render('<x-ui.sparkline :valori="[1, 2, 3]" />');

    expect($html)->toContain('aria-hidden="true"');
    expect($html)->toContain('focusable="false"');
    expect($html)->not->toContain('role="img"');
});

it('draws nothing at all when the sparkline has no data', function () {
    // Una linea piatta sul fondo si legge come un dato. L'assenza di dato non è
    // un dato.
    expect(trim(Blade::render('<x-ui.sparkline :valori="[]" />')))->toBe('');
});

it('gives the data chart a role and a label', function () {
    $html = Blade::render('<x-ui.grafico-barre :serie="$serie" titolo="Nuovi clienti per mese" unita="clienti" />', [
        'serie' => serieDiProva(),
    ]);

    expect($html)->toContain('role="img"');
    // L'etichetta dice **cosa racconta** il grafico, non solo come si chiama:
    // chi la sente deve poter decidere se vale la pena aprire la tabella.
    expect($html)->toContain('Nuovi clienti per mese: da Giu a Ago, minimo 0, massimo 9, totale 13 clienti');
});

it('offers the chart data as a table too', function () {
    $html = Blade::render('<x-ui.grafico-barre :serie="$serie" titolo="Nuovi clienti per mese" unita="clienti" />', [
        'serie' => serieDiProva(),
    ]);

    expect($html)->toContain('Vedi i dati');
    expect($html)->toContain('<table');
    // I dodici mesi e i dodici valori — qui tre e tre, ma la forma è quella.
    expect($html)->toContain('Giu 2026');
    expect($html)->toContain('Lug 2026');
    expect($html)->toContain('Ago 2026');
});

it('never leaves a chart series to colour alone', function () {
    // 🔴 È la sola difesa del ΔE 6,9 fra verde e arancione in tema scuro: ogni
    // voce della legenda porta l'ETICHETTA testuale **e** il GLIFO, non solo la
    // classe di colore. Il quadratino colorato è `aria-hidden`, perché è
    // ridondanza visiva e non informazione.
    $html = Blade::render('<x-ui.barra-composizione :voci="$voci" titolo="Clienti per piano" />', [
        'voci' => [
            ['etichetta' => 'SaaS', 'valore' => 3, 'classe' => 'bg-chart-brand', 'glifo' => '●'],
            ['etichetta' => 'Free', 'valore' => 1, 'classe' => 'bg-chart-verde', 'glifo' => '◆'],
        ],
    ]);

    expect($html)->toContain('SaaS');
    expect($html)->toContain('Free');
    expect($html)->toContain('●');
    expect($html)->toContain('◆');

    // E la barra in sé è muta: i suoi numeri sono già nella legenda, e farli
    // leggere due volte è peggio che non leggerli.
    //
    // ⚠️ L'ago identifica **la barra**, non un `aria-hidden` qualunque. Nello
    // stesso HTML l'attributo compare anche sul quadratino di colore e sul
    // glifo di ogni voce: un `toContain('aria-hidden="true"')` nudo resterebbe
    // verde anche togliendolo alla barra — cioè non potrebbe diventare rosso,
    // che è la stessa forma di guardrail vuoto già pagata due volte da questo
    // progetto, qui non per `toContain()` variadico ma per un ago generico.
    expect($html)->toContain('aria-hidden="true" class="mt-3 flex h-2');
});

it('reads the unit out loud in the legend, which is all a screen reader gets', function () {
    // La barra è `aria-hidden`, quindi la legenda è l'**intero** contenuto
    // accessibile del componente: senza unità di misura si sente «SaaS 12
    // 50,0%», un numero senza nome, mentre il grafico a barre accanto annuncia
    // «…, totale 13 clienti». Il prop `unita` era dichiarato in `@props` e mai
    // reso — e un prop dichiarato e mai usato Blade lo toglie da `$attributes`,
    // quindi la modifica di chi domani passasse `unita="account"` sparirebbe
    // senza errore e senza traccia.
    $html = Blade::render('<x-ui.barra-composizione :voci="$voci" titolo="Clienti per piano" unita="clienti" />', [
        'voci' => [
            ['etichetta' => 'SaaS', 'valore' => 12, 'classe' => 'bg-chart-brand', 'glifo' => '●'],
        ],
    ]);

    expect($html)->toContain('<span class="sr-only"> clienti</span>');
});

it('says nothing about a unit when it has not been given one', function () {
    // Il prop ha un default vuoto e non deve inventarsi una parola: un
    // `<span class="sr-only"> </span>` sarebbe rumore per chi ascolta.
    $html = Blade::render('<x-ui.barra-composizione :voci="$voci" titolo="Clienti per piano" />', [
        'voci' => [
            ['etichetta' => 'SaaS', 'valore' => 12, 'classe' => 'bg-chart-brand', 'glifo' => '●'],
        ],
    ]);

    expect($html)->not->toContain('sr-only');
});

it('keeps the composition bar closed when the percentages do not divide evenly', function () {
    // Tre voci da 1 su 3: 33,33 tre volte lascerebbe una fessura in coda, che si
    // legge come una quarta categoria senza nome.
    $html = Blade::render('<x-ui.barra-composizione :voci="$voci" titolo="Clienti per piano" />', [
        'voci' => [
            ['etichetta' => 'SaaS', 'valore' => 1, 'classe' => 'bg-chart-brand', 'glifo' => '●'],
            ['etichetta' => 'Free', 'valore' => 1, 'classe' => 'bg-chart-verde', 'glifo' => '◆'],
            ['etichetta' => 'Fuori catalogo', 'valore' => 1, 'classe' => 'bg-chart-rosso', 'glifo' => '■'],
        ],
    ]);

    preg_match_all('/width: ([\d.]+)%/', $html, $m);

    // ⚠️ **Con una tolleranza, non con `toBe(100.0)`.** L'identità stretta fra
    // float passa qui per fortuna aritmetica e diventerebbe rossa su codice
    // **corretto** appena cambia il numero di voci: sette voci da 1 danno
    // 99.99999999999999, e la barra nel browser chiude lo stesso perché le
    // percentuali stampate in decimale sommano a 100,00 esatti. La tolleranza
    // è mille volte più stretta del difetto che sorveglia (la fessura da 0,01
    // di una barra che non chiude), quindi la rete regge.
    expect(array_sum(array_map('floatval', $m[1])))->toEqualWithDelta(100.0, 0.000001);
});

it('keeps the composition bar closed with a number of slices that no float sums cleanly', function () {
    // Sette voci da 1: 14,29 sei volte e 14,26 all'ultima. È il caso che
    // smaschera l'identità stretta fra float — la barra chiude, la somma dei
    // float no — e vale anche come promessa che l'invariante non dipende dal
    // numero di piani a catalogo, che è destinato a cambiare.
    $voci = [];

    foreach (range(1, 7) as $n) {
        $voci[] = ['etichetta' => 'Piano '.$n, 'valore' => 1, 'classe' => 'bg-chart-brand', 'glifo' => '●'];
    }

    $html = Blade::render('<x-ui.barra-composizione :voci="$voci" titolo="Clienti per piano" />', ['voci' => $voci]);

    preg_match_all('/width: ([\d.]+)%/', $html, $m);

    expect($m[1])->toHaveCount(7)
        ->and(array_sum(array_map('floatval', $m[1])))->toEqualWithDelta(100.0, 0.000001);
});

// ─── Il guardrail sui token, un ago per chiamata ─────────────────────────────

it('never writes a raw hexadecimal colour inside a chart component', function () {
    // ⛔ Un esadecimale in un `fill=` non lo vede **nessuna** rete del progetto:
    // `PaletteGuardrailTest` cerca classi, `SuperficiTokenizzateGuardrailTest`
    // cerca classi di scala. E resterebbe chiaro sul fondo scuro, in silenzio.
    foreach (COMPONENTI_GRAFICO as $percorso) {
        $sorgente = file_get_contents(base_path($percorso));

        expect(preg_match('/#[0-9a-fA-F]{3,8}\b/', $sorgente))->toBe(
            0,
            "{$percorso} contiene un colore esadecimale: i colori dei grafici sono CLASSI (fill-chart-*, stroke-chart-*), che il tema scuro e il blocco di stampa riscrivono da soli."
        );
    }
});

it('never writes a chart colour as a CSS variable in an attribute', function () {
    // ⛔ È la forma del campione `docs/Design/design-system.html`, che è un file
    // standalone senza Tailwind. Copiata qui, rende cieco `PaletteGuardrailTest`
    // — che cerca classi, e i suoi prefissi includono già `fill` e `stroke`.
    foreach (COMPONENTI_GRAFICO as $percorso) {
        $sorgente = file_get_contents(base_path($percorso));

        expect($sorgente)->not->toContain('var(--chart-');
    }
});

it('never writes a hand-rolled dark theme variant inside a chart component', function () {
    // ⛔ Vietato da DS §8.1: i token dei grafici hanno già le tre terne in
    // `app.css` **e** un blocco di stampa che li riporta al chiaro — senza quel
    // blocco, stampando dal tema scuro la griglia usciva blu notte. Una variante
    // scritta a mano qui romperebbe proprio ciò che funziona.
    foreach (COMPONENTI_GRAFICO as $percorso) {
        $sorgente = file_get_contents(base_path($percorso));

        expect($sorgente)->not->toContain('dark:');
    }
});

it('never writes a colour in an inline style, where only a width may go', function () {
    // La percentuale è l'unica cosa che va in `style`, perché Tailwind non
    // genera larghezze arbitrarie da un valore calcolato a runtime. Tutto il
    // resto — il colore in particolare — resta una classe.
    foreach (COMPONENTI_GRAFICO as $percorso) {
        $sorgente = file_get_contents(base_path($percorso));

        preg_match_all('/style="([^"]*)"/', $sorgente, $m);

        foreach ($m[1] as $stile) {
            expect($stile)->toStartWith('width: ');
        }
    }
});

// ─── La pagina vera ──────────────────────────────────────────────────────────

it('renders the cabin with the charts for a Superadmin', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    $cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create();

    Livewire::actingAs($superadmin->fresh())
        ->test(Cabina::class)
        ->assertOk()
        ->assertSee('Nuovi clienti per mese')
        ->assertSee('Clienti per piano')
        ->assertSee('Vedi i dati');
});

it('renders the cabin on a platform with no customers at all', function () {
    // ⛔ Il caso del **primo giorno**: dodici mesi tutti a zero, cioè
    // `max === min`, cioè una divisione per zero — che in PHP 8 non è un
    // `d="M NaN"`, è un 500. Il giorno in cui succede, nessuno sta guardando.
    $this->seed(RolesAndPermissionsSeeder::class);

    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    Livewire::actingAs($superadmin->fresh())
        ->test(Cabina::class)
        ->assertOk()
        ->assertSee('Nuovi clienti per mese');
});

it('says «nessun dato» instead of drawing a flat line on an empty platform', function () {
    // 🔴 `GeometriaGrafico` fa cadere la serie piatta a **metà altezza** per non
    // dividere per zero: dodici mesi a zero disegnerebbero quindi la stessa
    // identica sparkline — linea orizzontale, area sotto, pallino in coda — che
    // si vedrebbe con 5.000 strumenti fermi da un anno. Un'assenza di dato che
    // si legge come un dato. `SerieMensile::eVuota()` è la distinzione, e questo
    // test è ciò che la tiene **collegata**: scritta e mai chiamata varrebbe
    // quanto il commento che la descrive.
    $this->seed(RolesAndPermissionsSeeder::class);

    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    $html = Livewire::actingAs($superadmin->fresh())
        ->test(Cabina::class)
        ->assertOk()
        ->html();

    // `fill-chart-band` è l'area sotto la linea, e vive **solo** dentro
    // `x-ui.sparkline`: se compare, una sparkline è stata disegnata.
    expect($html)->toContain('Nessun dato in 12 mesi');
    expect($html)->not->toContain('fill-chart-band');
});

it('draws the sparkline again as soon as there is something to draw', function () {
    // Il contro-caso, senza il quale il test qui sopra sarebbe soddisfatto anche
    // da una card che non disegna **mai** una sparkline.
    $this->seed(RolesAndPermissionsSeeder::class);

    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    $cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create();

    $html = Livewire::actingAs($superadmin->fresh())
        ->test(Cabina::class)
        ->assertOk()
        ->html();

    expect($html)->toContain('fill-chart-band');
    expect($html)->toContain('+1 in 12 mesi');
});
