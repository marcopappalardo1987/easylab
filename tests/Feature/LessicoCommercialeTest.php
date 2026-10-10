<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Billing\PaginaAbbonamento;
use App\Models\Account;
use App\Models\Piano;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Listino\GovernoListino;
use App\Support\Listino\LessicoCommerciale;
use App\Support\Piani;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/**
 * «Free», «gratis» e «gratuito» non si scrivono davanti al cliente: il piano
 * senza canone è in **comodato d'uso** (🔗 ADR-050, decisione di Marco del
 * 10 Ott 2026).
 *
 * Una regola di parole si perde alla prima frase scritta di fretta, quindi qui
 * non la si prova su una pagina: la si fa rispettare su **tutto ciò che
 * l'applicazione scrive**, leggendo viste, sorgenti e testi delle guide. Poi si
 * prova ciò che la lettura dei sorgenti non vede: l'etichetta di un piano, che
 * vive nel database e la scrive una persona.
 */

/**
 * I letterali stringa di un sorgente PHP: né commenti né codice.
 *
 * @return list<string>
 */
function letteraliScrittiIn(string $php): array
{
    $letterali = [];

    foreach (token_get_all($php) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $letterali[] = stripcslashes(substr($token[1], 1, -1));
        } elseif ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
            $letterali[] = $token[1];
        }
    }

    return $letterali;
}

/**
 * Una frase, e non un identificatore.
 *
 * ⚠️ `free` è il **codice** del piano e `gratuito` il nome di una colonna: come
 * letterali nudi sono chiavi, valori di `accounts.piano`, argomenti di una
 * query. Non si possono distinguere da una parola stampata guardando il solo
 * letterale, quindi quelli si lasciano passare. La rete per una parola nuda
 * finita in pagina sono i test delle pagine, più sotto.
 */
function eUnaFraseScritta(string $letterale): bool
{
    return preg_match('/^[a-z0-9_.\-]*$/', $letterale) !== 1;
}

/**
 * Ciò che una vista scrive: il testo fra i tag, gli attributi che si leggono, e
 * i letterali del suo PHP.
 *
 * Si compila la vista e si leggono i token: i commenti Blade spariscono da sé,
 * e ciò che resta fuori dal PHP è l'HTML che arriverà nel browser.
 *
 * @return array{testo: string, letterali: list<string>}
 */
function scrittoDallaVista(string $blade): array
{
    $compilata = Blade::compileString($blade);
    $html = '';

    foreach (token_get_all($compilata) as $token) {
        if (! is_array($token)) {
            continue;
        }

        // Un segnaposto al posto di ogni blocco di PHP, o un tag spezzato da
        // un `{{ }}` non si richiuderebbe più.
        $html .= match ($token[0]) {
            T_INLINE_HTML => $token[1],
            T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO => '§',
            default => '',
        };
    }

    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<(script|style)\b.*?<\/\1>/si', '', $html);

    preg_match_all('/\s(?:title|placeholder|alt|aria-label|aria-description)="([^"]*)"/', $html, $attributi);

    $testo = preg_replace('/<\/?[a-zA-Z][^\s>]*(?:\s+[^\s=>]+(?:=(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?)*\s*\/?>/', ' ', $html);

    return [
        'testo' => $testo.' '.implode(' ', $attributi[1]),
        'letterali' => letteraliScrittiIn($compilata),
    ];
}

/** @return list<string> le parole vietate trovate, per dirle nel messaggio */
function paroleVietateIn(string $testo): array
{
    preg_match_all(LessicoCommerciale::VIETATE, $testo, $trovate);

    return $trovate[0];
}

// ─── Ciò che l'applicazione scrive ───────────────────────────────────────────

it('writes none of the three words in the text of any view', function () {
    $violazioni = [];

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        $scritto = scrittoDallaVista($file->getContents());

        foreach (paroleVietateIn($scritto['testo']) as $parola) {
            $violazioni[] = "{$file->getRelativePathname()}: «{$parola}» nel testo";
        }

        foreach ($scritto['letterali'] as $letterale) {
            if (eUnaFraseScritta($letterale) && LessicoCommerciale::vietato($letterale)) {
                $violazioni[] = "{$file->getRelativePathname()}: «{$letterale}»";
            }
        }
    }

    expect($violazioni)->toBe([]);
});

it('writes none of the three words in a sentence of the code, the config or the language files', function () {
    $violazioni = [];

    $file = (new Finder)->files()
        ->in([app_path(), config_path(), database_path('seeders'), base_path('routes'), base_path('lang')])
        ->name('*.php')
        // Il solo file in cui quelle parole stanno in una frase: per dire
        // quali sono.
        ->notPath('Support/Listino/LessicoCommerciale.php');

    foreach ($file as $sorgente) {
        foreach (letteraliScrittiIn($sorgente->getContents()) as $letterale) {
            if (eUnaFraseScritta($letterale) && LessicoCommerciale::vietato($letterale)) {
                $violazioni[] = "{$sorgente->getRelativePathname()}: «{$letterale}»";
            }
        }
    }

    expect($violazioni)->toBe([]);
});

it('writes none of the three words in the guides, neither in the prose nor in the captions', function () {
    $violazioni = [];

    foreach ((new Finder)->files()->in(base_path('guide/testi'))->name('*.md') as $file) {
        foreach (paroleVietateIn($file->getContents()) as $parola) {
            $violazioni[] = "testi/{$file->getRelativePathname()}: «{$parola}»";
        }
    }

    // Le didascalie dei video: il primo argomento di ogni gesto del copione.
    foreach ((new Finder)->files()->in(base_path('guide/flussi'))->name('*.spec.ts') as $file) {
        preg_match_all('/\bg\.(?:passo|capitolo|chiusura|scrivi)\(\s*(["\'`])(.*?)\1/s', $file->getContents(), $didascalie);

        foreach ($didascalie[2] as $didascalia) {
            foreach (paroleVietateIn($didascalia) as $parola) {
                $violazioni[] = "flussi/{$file->getRelativePathname()}: «{$parola}»";
            }
        }
    }

    expect($violazioni)->toBe([]);
});

it('reads a view the way a browser would, so the guardrail above cannot pass by looking away', function () {
    // 🧪 La prova che la lettura vede ciò che deve vedere e nient'altro: un
    // guardrail che non trova mai niente è indistinguibile da uno che non
    // guarda.
    $vista = <<<'BLADE'
        {{-- gratis, in un commento Blade --}}
        <!-- free, in un commento HTML -->
        <p class="free" data-gratis="1" wire:model="nuovo.gratuito">Piano {{ $piano->gratuito ? 'Gratuito per sempre' : $codici['free'] }}</p>
        <a href="#" title="È gratis">Apri</a>
        @if ($piano->gratuito) <span>Freezer</span> @endif
        BLADE;

    $scritto = scrittoDallaVista($vista);

    // Nel testo: il `title`, che si legge. Non le classi, i `data-`, i
    // `wire:`, i commenti, né «Freezer».
    expect(paroleVietateIn($scritto['testo']))->toBe(['gratis'])
        // Nei letterali del PHP: la frase stampata. Non la chiave `free`.
        ->and(array_values(array_filter($scritto['letterali'], fn (string $l) => eUnaFraseScritta($l) && LessicoCommerciale::vietato($l))))
        ->toBe(['Gratuito per sempre']);
});

it('knows the three words whole and in any case, and nothing that only looks like them', function (string $testo, bool $vietato) {
    expect(LessicoCommerciale::vietato($testo))->toBe($vietato);
})->with([
    ['Free', true],
    ['Piano FREE', true],
    ['è gratis', true],
    ['Gratuito', true],
    ['gratuita', true],
    ['gratuitamente', true],
    ['la gratuità', true],
    ["Comodato d'uso", false],
    ['Freezer -80', false],
    ['Freemium', false],
    ['Carefree', false],
]);

// ─── L'etichetta del piano, che vive nel database ────────────────────────────

it('calls the plan without a fee comodato d\'uso, from the very first migration', function () {
    expect(Piani::etichetta(Piani::predefinito()))->toBe("Comodato d'uso");

    // Nessun piano di partenza porta una delle tre parole nel nome.
    foreach (Piani::codici() as $codice) {
        expect(LessicoCommerciale::vietato(Piani::etichetta($codice)))->toBeFalse();
    }
});

it('renames the plan where it still carries the old name, and nowhere else', function () {
    $migration = require database_path('migrations/2026_10_10_110000_rinomina_piano_free_in_comodato_duso.php');

    // Un database che esisteva già: l'etichetta è quella di partenza.
    DB::table('piani')->where('codice', 'free')->update(['etichetta' => 'Free']);
    // 🔴 Un altro piano con lo stesso nome non è quello di partenza.
    DB::table('piani')->where('codice', 'saas')->update(['etichetta' => 'Free']);

    $migration->up();

    expect(DB::table('piani')->where('codice', 'free')->value('etichetta'))->toBe("Comodato d'uso")
        ->and(DB::table('piani')->where('codice', 'saas')->value('etichetta'))->toBe('Free');

    // 🔴 E un nome scelto da una persona dal listino non si sovrascrive.
    DB::table('piani')->where('codice', 'free')->update(['etichetta' => 'Base']);

    $migration->up();

    expect(DB::table('piani')->where('codice', 'free')->value('etichetta'))->toBe('Base');
});

it('refuses a plan label that carries one of the three words, when it is born and when it is renamed', function (string $etichetta) {
    // L'etichetta finisce in pagina, nelle email e nel messaggio del tetto: è
    // l'unico posto in cui la parola la scrive una persona e non il codice.
    expect(fn () => GovernoListino::crea(['codice' => 'nuovo_piano', 'etichetta' => $etichetta]))
        ->toThrow(ValidationException::class, LessicoCommerciale::ETICHETTA_RIFIUTATA);

    expect(Piano::query()->where('codice', 'nuovo_piano')->exists())->toBeFalse();

    $saas = Piani::modello('saas');

    expect(fn () => GovernoListino::aggiornaAnagrafica($saas, ['etichetta' => $etichetta]))
        ->toThrow(ValidationException::class, LessicoCommerciale::ETICHETTA_RIFIUTATA);

    expect($saas->fresh()->etichetta)->toBe('SaaS');
})->with(['Free', 'Piano free plus', 'Gratis', 'Gratuito per i partner']);

it('still accepts a label that only looks like one of them', function () {
    expect(GovernoListino::crea(['codice' => 'freezer', 'etichetta' => 'Freezer e ultracongelatori'])->etichetta)
        ->toBe('Freezer e ultracongelatori');
});

// ─── Ciò che il cliente legge davvero ────────────────────────────────────────

/** Il testo che una pagina resa mette sotto gli occhi di chi la apre. */
function testoLettoIn(string $html): string
{
    $html = preg_replace('/<(script|style)\b.*?<\/\1>/si', '', $html);

    return html_entity_decode(strip_tags($html), ENT_QUOTES);
}

it('never shows a customer on the plan without a fee any of the three words', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Il cliente com'è alla nascita: sul piano predefinito, il cui codice è
    // `free`. È la parola che non deve arrivare in pagina.
    $account = Account::factory()->create(['ragione_sociale' => 'Laboratorio Verdi']);
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => 'Sede Verdi']);
    $admin = User::factory()->create(['tenant_id' => $sede->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');
    $account->aggiungiMembro($admin);

    expect($account->fresh()->piano)->toBe('free');

    $abbonamento = Livewire::actingAs($admin)->test(PaginaAbbonamento::class)
        ->assertSee("Comodato d'uso")
        // `false`: la frase sta scritta nella vista, e il suo apostrofo non
        // passa da `e()` come quello dell'etichetta qui sopra.
        ->assertSee("Un piano in comodato d'uso non ha un portale di fatturazione", false);

    // L'albero, dove il tetto di sedi nomina il piano.
    $albero = Livewire::actingAs($admin)->test(Albero::class)->assertSee("Comodato d'uso");

    $pagine = [
        'abbonamento' => $abbonamento->html(),
        'anagrafica' => $albero->html(),
        'dashboard' => $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent(),
        'strumenti' => $this->actingAs($admin)->get(route('strumenti.index'))->assertOk()->getContent(),
    ];

    foreach ($pagine as $nome => $html) {
        expect(paroleVietateIn(testoLettoIn($html)))->toBe([], "La pagina «{$nome}» scrive una parola vietata.");
    }
});

it('gives the same sentence when the billing portal is asked for by hand', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $account = Account::factory()->create();
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();
    $admin = User::factory()->create(['tenant_id' => $sede->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');
    $account->aggiungiMembro($admin);

    // Con le chiavi di Stripe al loro posto: senza, il rifiuto è un altro e
    // arriva prima.
    config(['cashier.secret' => 'sk_test_finta']);

    // Il bottone non c'è, ma la rotta sì: chi la chiama legge un rifiuto, e
    // anche quello è testo per il cliente.
    $this->actingAs($admin)
        ->post(route('abbonamento.portale'))
        ->assertSessionHas('erroreAbbonamento', "Un piano in comodato d'uso non ha un portale di fatturazione: non c'è un abbonamento da gestire.");
});
