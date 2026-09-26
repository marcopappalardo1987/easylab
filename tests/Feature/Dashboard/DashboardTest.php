<?php

use App\Enums\StatoSemaforo;
use App\Livewire\Dashboard\Home;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * La pagina di atterraggio, per tutti i ruoli (S6 — Wireframe §1).
 *
 * ⚠️ **Le parole della sidebar sono già verdi sullo stub**, e questo file esiste
 * anche per non caderci: «Strumenti», «Ricambi», «Campo» e i loro `route()`
 * vivono nel layout, su ogni pagina. Peggio, `route('strumenti.index')` è
 * **prefisso** di `/strumenti/modelli`, `/strumenti/import` e `/strumenti/{id}`,
 * quindi lo soddisfa anche un link sbagliato. Ogni asserzione qui dentro guarda
 * il **riquadro estratto**, mai la pagina intera.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ospedale San Giovanni']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->macchina = fn (string $nome) => Strumento::factory()->forNode($this->dept)
        ->create(['nome' => $nome, 'data_installazione' => today()->subYear()->toDateString()]);

    /**
     * I riquadri della pagina, estratti per **struttura**: le ancore che
     * avvolgono un riquadro.
     *
     * ⚠️ **Nessun marcatore `data-*` messo lì per i test.** Ciò che deve
     * esistere è il link che l'utente clicca, e su quello si asserisce — un
     * attributo scritto per la suite porterebbe via l'asserzione insieme a sé
     * il giorno in cui qualcuno lo togliesse.
     *
     * @return array<string, array{testo: string, href: string}>
     */
    $this->riquadri = function (string $html): array {
        $dom = new DOMDocument;
        // Livewire inserisce commenti condizionali e HTML non stretto: gli
        // avvisi del parser non sono difetti del markup.
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);

        $trovati = [];

        // ⚠️ **Si aggancia al CONTRATTO, non alla presentazione.** La prima
        // stesura cercava `//a[.//p[contains(@class,'text-3xl')]]`, cioè una
        // classe di stile: rinominarla in `text-4xl` — una modifica puramente
        // estetica — rendeva rossi tre test con un messaggio che non nomina la
        // causa, e **svuotava in silenzio** gli altri due, fra cui quello sul
        // permesso, perché `toBeEmpty()` su un selettore che non aggancia più
        // nulla è soddisfatto per definizione. Ciò che deve esistere è il link
        // verso l'elenco, e su quello ci si aggancia.
        foreach ($xpath->query('//a[contains(@href, "/strumenti")]') as $ancora) {
            $testo = preg_replace('/\s+/', ' ', trim($ancora->textContent));

            // Il link della sidebar non è un riquadro: porta allo stesso posto
            // ma non ha un numero sotto l'etichetta.
            if (! preg_match('/\d/', $testo)) {
                continue;
            }

            $trovati[$testo] = [
                'testo' => $testo,
                'href' => $ancora->getAttribute('href'),
            ];
        }

        return $trovati;
    };

    /** I parametri di un href, decodificati come li leggerebbe un browser. */
    $this->parametriDi = function (string $href): array {
        // ⚠️ `html_entity_decode` obbligatorio: Blade escapa `&` in `&amp;`, e
        // senza decodifica `parse_str` produrrebbe un parametro chiamato
        // `amp;sortBy` — cioè il test resterebbe VERDE senza aver verificato
        // l'ordinamento che crede di verificare.
        parse_str(parse_url(html_entity_decode($href, ENT_QUOTES), PHP_URL_QUERY) ?? '', $parametri);

        return $parametri;
    };
});

// ─── I numeri, ciascuno sotto la propria etichetta ───────────────────────────

it('puts each number under its own label', function () {
    // Numeri VOLUTAMENTE diversi: con tre volte lo stesso valore uno scambio fra
    // due riquadri passerebbe inosservato.
    foreach (range(1, 3) as $n) {
        ($this->macchina)("Sana {$n}");
    }
    foreach (range(1, 2) as $n) {
        $s = ($this->macchina)("Scaduta {$n}");
        Intervento::factory()->forStrumento($s)->scaduto()->create();
    }
    ($this->macchina)('Ferma')->forzaSemaforo(StatoSemaforo::Rosso, 'Guasto');
    ($this->macchina)('Vecchia')->update(['data_installazione' => today()->subYears(12)->toDateString()]);

    $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();
    $riquadri = ($this->riquadri)($html);

    // ⚠️ Si asserisce sul testo del **singolo riquadro**, non della pagina: su
    // tutto il documento `assertSeeInOrder` con cifre nude passa per una classe
    // CSS — correzione già pagata da `KpiPiattaformaTest`.
    // ⚠️ I glifi fanno parte dell'asserzione, e non per pignoleria: il Design
    // System §4 impone colore **+ forma + etichetta**, e un riquadro che
    // perdesse il simbolo resterebbe leggibile solo a chi distingue i colori.
    expect(array_keys($riquadri))->toEqualCanonicalizing([
        '● In regola 4',            // 3 sane + la vecchia, che è verde e obsoleta
        '◐ Azione richiesta 2',
        '■ Non idoneo 1',
        '⏳ Obsoleti 1 oltre 10 anni',
    ]);
});

it('says how many machines the three states cover, so nobody sums the fourth in', function () {
    // ADR-014: l'obsolescenza «non tocca il semaforo». Quattro riquadri in fila
    // si leggono come una partizione e non lo sono, quindi la pagina lo dice.
    ($this->macchina)('Una');
    ($this->macchina)('Vecchia')->update(['data_installazione' => today()->subYears(12)->toDateString()]);

    $testo = preg_replace('/\s+/', ' ', strip_tags(
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent()
    ));

    expect($testo)->toContain('Le prime tre coprono tutte le 2 macchine che vedi.')
        ->and($testo)->toContain('sono già contati in una delle tre');
});

// ─── Il link porta dove promette, e conta ciò che dice ───────────────────────

it('leads where it promises, and counts what it says', function () {
    foreach (range(1, 3) as $n) {
        $s = ($this->macchina)("Scaduta {$n}");
        Intervento::factory()->forStrumento($s)->scaduto()->create();
    }
    ($this->macchina)('Sana');

    $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();
    $riquadri = ($this->riquadri)($html);

    expect($riquadri)->toHaveKey('◐ Azione richiesta 3');

    $parametri = ($this->parametriDi)($riquadri['◐ Azione richiesta 3']['href']);

    // I NOMI dei parametri sono un contratto fra le due pagine: un `?stato=`
    // diventato `?semaforo=` deve far cadere questo test, mentre un
    // `->set('stato', …)` non se ne accorgerebbe.
    expect($parametri)->toHaveKeys(['stato', 'sortBy', 'sortDir'])
        ->and($parametri['stato'])->toBe(StatoSemaforo::Arancione->value)
        ->and($parametri['sortBy'])->toBe('prossima_scadenza');

    // 🔴 E si arriva davvero a TRE righe. `$this->get()->viewData()` non
    // raggiunge i dati di un componente full-page — legge il layout — quindi si
    // monta l'elenco con gli stessi parametri, che è la strada da cui il filtro
    // arriva davvero (`#[Url]`).
    $trovate = Livewire::withQueryParams($parametri)
        ->actingAs($this->admin)->test(ElencoStrumenti::class)
        ->viewData('strumenti');

    expect($trovate->total())->toBe(3);
});

it('leads the obsolete tile to a list that really is filtered', function () {
    ($this->macchina)('Recente');
    ($this->macchina)('Vecchia')->update(['data_installazione' => today()->subYears(12)->toDateString()]);

    $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();
    $riquadri = ($this->riquadri)($html);
    $chiave = collect(array_keys($riquadri))->first(fn (string $k) => str_contains($k, 'Obsoleti'));

    // ⚠️ La forma con cui `soloObsoleti` viaggia in query string è stata LETTA
    // dall'applicazione, non indovinata: un `=1` scritto a occhio è ciò che
    // l'elenco potrebbe non riconoscere, lasciando il riquadro che porta a una
    // lista non filtrata.
    $parametri = ($this->parametriDi)($riquadri[$chiave]['href']);
    expect($parametri)->toHaveKey('soloObsoleti');

    $trovate = Livewire::withQueryParams($parametri)
        ->actingAs($this->admin)->test(ElencoStrumenti::class)
        ->viewData('strumenti');

    expect($trovate->total())->toBe(1)
        ->and($chiave)->toBe('⏳ Obsoleti 1 oltre 10 anni');
});

// ─── Il vuoto ────────────────────────────────────────────────────────────────

it('says one sentence instead of four zeros when there is nothing to see', function () {
    $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain('Nessuno strumento visibile.')
        ->and(($this->riquadri)($html))->toBeEmpty();

    $testo = preg_replace('/\s+/', ' ', strip_tags($html));
    expect($testo)->not->toContain('Azione richiesta');
});

it('says the same true sentence to a Responsabile with no assignments', function () {
    // Il suo Ente ha macchine: «Nessuno strumento in questo Ente» sarebbe falsa.
    $s = ($this->macchina)('C\'è, ma non per lui');
    Intervento::factory()->forStrumento($s)->scaduto()->create();

    $resp = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $resp->assignRole('Responsabile Reparto');

    $this->actingAs($resp->fresh())->get(route('dashboard'))->assertOk()
        ->assertSee('Nessuno strumento visibile.');
});

// ─── Il perimetro si nomina ──────────────────────────────────────────────────

it('names the Ente whose machines it is counting', function () {
    ($this->macchina)('Una');

    // ⚠️ Non basta `assertSee('Ospedale San Giovanni')`: quel nome lo stampa già
    // lo switcher di Ente in top bar, su OGNI pagina. Si asserisce la frase
    // composta, che vive solo qui.
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Lo stato delle macchine di Ospedale San Giovanni.');
});

it('uses the other sentence for someone who has no Ente', function () {
    $tecnico = User::factory()->create(['tenant_id' => null, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');

    $this->actingAs($tecnico->fresh())->get(route('dashboard'))->assertOk()
        ->assertSee('Lo stato delle macchine che segui.')
        ->assertDontSee('Lo stato delle macchine di ');
});

// ─── Il permesso, blocco per blocco ──────────────────────────────────────────

it('drops the parco block for whoever cannot see the strumenti', function () {
    ($this->macchina)('Invisibile');

    // Il caso è reale: `strumenti.view` NON è nel set bloccato, quindi
    // `/piattaforma/ruoli` può revocarlo a runtime. Si usa quella strada.
    Role::findByName('Admin')->revokePermissionTo('strumenti.view');

    $html = $this->actingAs($this->admin->fresh())->get(route('dashboard'))->assertOk()->getContent();
    $testo = preg_replace('/\s+/', ' ', strip_tags($html));

    expect(($this->riquadri)($html))->toBeEmpty()
        ->and($testo)->not->toContain('Azione richiesta')
        ->and($testo)->not->toContain('Nessuno strumento visibile.');
});

it('does not even compute the numbers for whoever cannot see them', function () {
    ($this->macchina)('Invisibile');

    $statementSuStrumenti = function (User $utente): int {
        // Un giro a vuoto scalda la cache dei permessi di spatie, che
        // altrimenti finirebbe nella misura.
        $this->actingAs($utente)->get(route('dashboard'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($utente)->get(route('dashboard'));
        // ⚠️ Con le virgolette: `strumenti` nudo matcherebbe anche
        // `spostamenti_strumento` e ogni `strumento_id`.
        $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"strumenti"'))->count();
        DB::disableQueryLog();

        return $n;
    };

    // 🔴 **Il gemello positivo è obbligatorio.** Un'asserzione «zero» da sola è
    // soddisfatta da un 403, da un `render()` che esce presto e da un bug che
    // passa `null` a tutti: misurerebbe l'assenza di una pagina, non la presenza
    // di una guardia.
    expect($statementSuStrumenti($this->admin->fresh()))->toBeGreaterThan(0);

    Role::findByName('Admin')->revokePermissionTo('strumenti.view');

    expect($statementSuStrumenti($this->admin->fresh()))->toBe(0);
});

// ─── Il confine, dal vero ────────────────────────────────────────────────────

it('never counts the machines of another Ente, through a real request', function () {
    ($this->macchina)('Mia');

    $altro = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($altro)->create();
    $sua = Strumento::factory()->forNode($altroDept)->create(['nome' => 'Sua']);
    Intervento::factory()->forStrumento($sua)->scaduto()->create();

    $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

    expect(array_keys(($this->riquadri)($html)))->toContain('● In regola 1', '◐ Azione richiesta 0');
});

// ─── Chi non ha macchine, ma ha un mestiere ──────────────────────────────────

it('gives a Superadmin an honest page instead of an empty one', function () {
    $superadmin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');

    // Nessuna macchina nel suo Ente: il blocco parco è legittimamente vuoto, e
    // senza la card la sua pagina di atterraggio non direbbe niente.
    $this->actingAs($superadmin->fresh())->get(route('dashboard'))->assertOk()
        ->assertSee('Nessuno strumento visibile.')
        // Stringa che vive SOLO nella card: l'URL da solo non basterebbe, lo
        // emette anche la voce di sidebar sullo stesso layout.
        ->assertSee('Vai alla cabina di regia');
});

it('never shows the way into the cabina to whoever cannot walk it', function () {
    ($this->macchina)('Una');

    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertDontSee('Vai alla cabina di regia');
});

// ─── Privacy: il pallino è un aggregato, la sua causa no ─────────────────────

it('never breaks the orange down by cause', function () {
    // ⛔ ADR-020 legittima il PALLINO come aggregato dovuto a tutti, non la sua
    // scomposizione: «di cui N da garanzie ricambio» direbbe a un Ente su
    // `nascosta` quanti pezzi sostituiti ha, e lo direbbe senza passare da
    // nessuno scope — perché un numero non è una riga.
    //
    // 🔴 **La prima stesura di questo test era COMPLETAMENTE VUOTA**, e la
    // ragione vale oltre questo file: `expect()->not->toContain()` è
    // **variadico**, non accetta un messaggio. Il testo che credevo di passare
    // come spiegazione era un **secondo ago** — e non essendo mai in pagina,
    // l'asserzione era soddisfatta per qualunque parola. Misurato: aggiungendo
    // «di cui N da garanzie ricambio» alla vista, tutti e quattordici i test
    // restavano verdi.
    //
    // ⚠️ E si guarda il markup del **solo componente**, non la pagina: con
    // `strip_tags` del documento intero la sidebar stampa già «Ricambi» a un
    // Admin, quindi il test sarebbe rosso per il layout invece che per la
    // dashboard — e verrebbe «aggiustato» finché non dice più niente. È la
    // stessa trappola che `RetentionTest` e `ContestoErroriTest` spiegano per
    // nome.
    $s = ($this->macchina)('Scaduta');
    Intervento::factory()->forStrumento($s)->scaduto()->create();
    ($this->macchina)('Vecchia')->update(['data_installazione' => today()->subYears(12)->toDateString()]);

    $testo = mb_strtolower(preg_replace('/\s+/', ' ', strip_tags(
        Livewire::actingAs($this->admin)->test(Home::class)->html()
    )));

    foreach (['ricambio', 'ricambi', 'garanzia', 'garanzie', 'intervento', 'interventi'] as $parola) {
        expect($testo)->not->toContain($parola);
    }
});
