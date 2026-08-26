<?php

use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Errore;
use App\Models\Strumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Monta la scheda strumento come utente dato. Vive qui e non in un singolo file
 * di test perché la usano più suite (azioni interventi, forzatura semaforo).
 */
function scheda(User $user, Strumento $strumento): Testable
{
    return Livewire\Livewire::actingAs($user)->test(SchedaStrumento::class, ['strumento' => $strumento]);
}

/**
 * Estrae il primo `wire:snapshot` dall'HTML, come fa il vendor stesso: serve a
 * costruire un POST **reale** a `/livewire/update`, che è l'unica strada per
 * provare i middleware persistenti — `Livewire::test()` li disabilita.
 *
 * Vive qui e non nel file che l'ha introdotta (`LockoutEnforcementTest`) perché
 * la usa anche `AccessoPiattaformaTest`: finché stava là, quel file eseguito da
 * solo con `--filter` andava in **fatal per funzione non definita**. I file di
 * test si caricano solo se selezionati, quindi una funzione condivisa fra due
 * suite non può abitare in una delle due.
 */
function snapshotDa(string $html, ?string $componente = null): string
{
    // ⚠️ **Senza `$componente` si prende il PRIMO snapshot della pagina, che
    // quasi mai è quello che interessa.** Misurato il 23 Ago 2026 su
    // `/piattaforma/errori`: l'ordine è `tenancy.switcher-ente`,
    // `notifiche.campanella`, `piattaforma.errori` — cioè i test che credevano
    // di rinfrescare la pagina gatata stavano rinfrescando lo **switcher di
    // ente**, montato dal layout su ogni schermata.
    //
    // Il test non era del tutto vuoto — Livewire rimatcha sul `memo.path` e
    // riapplica i middleware persistenti, che è il meccanismo vero — ma non
    // toccava il componente, quindi sarebbe rimasto verde qualunque cosa fosse
    // successa alle sue azioni. Chi prova il gate di una pagina passi il nome.
    if ($componente !== null) {
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $trovati);

        foreach ($trovati[1] as $grezzo) {
            $decodificato = html_entity_decode($grezzo, ENT_QUOTES);

            if ((json_decode($decodificato, true)['memo']['name'] ?? null) === $componente) {
                return $decodificato;
            }
        }

        return '';
    }

    $grezzo = str($html)->betweenFirst('wire:snapshot="', '"')->toString();

    return html_entity_decode($grezzo, ENT_QUOTES);
}

/**
 * Chi entra nelle pagine di piattaforma e chi no, come **dataset condivisi**.
 *
 * ⚠️ Vivono qui e non in `AccessoPiattaformaTest` per la stessa ragione — e con
 * la stessa cicatrice — di `snapshotDa()` qui sopra, un gradino più in là: là
 * era una **funzione** condivisa fra due suite, qui sono **costanti**. I file di
 * test si caricano solo se selezionati, e comunque in ordine alfabetico *prima*
 * che qualunque test giri: una costante definita in `AccessoPiattaformaTest` non
 * esiste ancora quando Pest risolve i `->with()` di `AccessoEditorRuoliTest`, e
 * non esiste affatto se si lancia il solo `AccessoRegistroAuditTest`.
 *
 * Il sintomo non è un errore leggibile: è `Pest\Exceptions\DatasetMissing`,
 * cioè la stessa classe di guasto della trappola dei closure nei dataset —
 * **test che non girano**. Verificato il 23 Ago 2026:
 * `php artisan test tests/Feature/Piattaforma/AccessoRegistroAuditTest.php`
 * falliva così, e nessuno se n'era accorto perché la suite si lancia intera.
 *
 * Gli elenchi restano **costanti e non closure** (i closure si risolvono prima
 * del boot di Laravel, quindi `config('rbac.roles')` sarebbe vuoto e il dataset
 * nascerebbe vuoto), e un test per suite li tiene onesti contro la matrice RBAC:
 * elencare a mano senza quella rete lascia scoperto il Developer per omissione.
 */
const RUOLI_SENZA_PIATTAFORMA = ['Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico'];
const RUOLI_CON_PIATTAFORMA = ['Developer', 'Superadmin'];

/** Un utente del ruolo dato, con 2FA già confermata (Admin e i due di piattaforma la richiedono). */
function utenteConRuolo(string $ruolo): User
{
    $utente = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    return $utente->fresh();
}

/**
 * Il `<tr>` di una riga-permesso dell'editor ruoli, estratto per la sua `wire:key`.
 *
 * ⚠️ Si estrae il **blocco** invece di fare `assertSee` sull'HTML intero, e la
 * ragione ha una cicatrice recente: in questo stesso lavoro un
 * `assertSee('Clienti')` sulla pagina intera era verde *anche con la voce di
 * menù rimossa*, perché quella parola compare altrove. Un'asserzione che non può
 * fallire è peggio di nessuna asserzione, perché occupa il posto di quella vera.
 *
 * ⚠️ **Vive qui e non in `GrigliaRuoliTest`, che l'ha introdotta**, per la
 * ragione già scritta sopra per `snapshotDa()`: i file di test si caricano solo
 * se selezionati, quindi una funzione condivisa fra due suite non può abitare in
 * una delle due — `RiconciliazioneRuoliTest` eseguito da solo andrebbe in fatal
 * per funzione non definita.
 */
function rigaDelPermesso(string $html, string $permesso): string
{
    preg_match('/<tr wire:key="permesso-'.preg_quote($permesso, '/').'".*?<\/tr>/s', $html, $blocco);

    return $blocco[0] ?? '';
}

/** I permessi che un ruolo ha **a database**, letti senza passare dal registrar. */
function permessiDiRuolo(string $ruolo): array
{
    return Role::findByName($ruolo, 'web')->permissions()->pluck('name')->sort()->values()->all();
}

/**
 * Una **issue** dell'error tracker, coi campi che `$fillable` non lascia passare.
 *
 * ⚠️ **`forceFill()` e non `Errore::create()`**, e non è pignoleria: il
 * `$fillable` del model elenca solo ciò che il tracker scrive alla *nascita*
 * di una issue, quindi `stato`, `occorrenze`, `contesti`, `risolto_da` e i
 * timestamp di chiusura vengono **scartati in silenzio** dall'assegnazione di
 * massa. Una fixture scritta con `create(['stato' => 'risolto'])` nasce
 * `aperto` e il test che credeva di provare il filtro proverebbe il default.
 *
 * ⚠️ **L'impronta è casuale a ogni chiamata.** La colonna è `unique`, e il
 * tracker è **acceso durante la suite** (`bootstrap/app.php`): una qualunque
 * eccezione riportata da un altro test scrive righe in questa stessa tabella.
 * Da cui anche la regola dei test che la usano: si conta **per impronta**, mai
 * con `Errore::count()`.
 *
 * ⚠️ **Vive qui e non in uno dei due file di test degli errori** per la ragione
 * già scritta sopra per `snapshotDa()` e `rigaDelPermesso()`: la usano due suite
 * (`ElencoErroriTest`, `SchedaErroreTest`), e i file di test si caricano solo se
 * selezionati — una funzione condivisa fra due suite non può abitare in una
 * delle due, o l'altra eseguita da sola va in fatal.
 *
 * @param  array<string, mixed>  $attributi
 */
function issueErrore(array $attributi = []): Errore
{
    $errore = new Errore;

    $errore->forceFill(array_merge([
        'impronta' => sha1(uniqid('', true)),
        'classe' => 'RuntimeException',
        'messaggio' => 'Qualcosa non ha funzionato',
        'file' => 'app/Support/Prova.php',
        'riga' => 42,
        'stato' => 'aperto',
        'occorrenze' => 1,
        'contesti' => 0,
        'prima_occorrenza_at' => now(),
        'ultima_occorrenza_at' => now(),
    ], $attributi))->save();

    return $errore;
}

/**
 * Il blocco `<nav>` della sub-nav di piattaforma, estratto dall'HTML di pagina.
 *
 * ⚠️ Vive in fondo a **questo** file e non in `tests/Pest.php` di proposito: la
 * usano due test di questa sola suite, e la disciplina che ha portato là
 * `snapshotDa()` e `rigaDelPermesso()` è la reciproca — ci si sale quando una
 * funzione serve a **due suite**, non per simmetria. Se un domani
 * `AccessoEditorRuoliTest` volesse la stessa estrazione (oggi ne ha una copia
 * inline), è quello il momento di spostarla.
 */
function navDiPiattaforma(string $html): string
{
    preg_match('/<nav[^>]*aria-label="Sezioni della piattaforma".*?<\/nav>/s', $html, $blocco);

    // Non `?? ''`: un blocco assente e un blocco vuoto vanno distinti, o un
    // `not->toContain()` sarebbe verde proprio quando la nav è sparita.
    expect($blocco)->not->toBeEmpty();

    return $blocco[0];
}

/**
 * I file che **possono contenere una classe di stile**, cioè quelli che Tailwind
 * deve scansionare e che i meta-test della palette ispezionano.
 *
 * ⚠️ **Sta qui e non in uno dei due file** perché è precisamente l'insieme su
 * cui i due devono concordare: `PaletteGuardrailTest` chiede «ogni classe usata
 * qui dentro esiste nel tema?» e `SorgentiTailwindGuardrailTest` chiede «ogni
 * file qui dentro è davvero scansionato?». Due copie della stessa lista si
 * separerebbero, e la metà che diverge lascerebbe un angolo del progetto in cui
 * una classe non produce nulla **senza che nessuno dei due se ne accorga** — che
 * è il guasto che entrambi esistono per rendere rumoroso.
 *
 * `resources/css/app.css` è escluso: è la **fonte** dei colori, non un uso.
 *
 * @return list<string> percorsi assoluti
 */
function sorgentiDiStile(): array
{
    return collect(File::allFiles(resource_path()))
        ->reject(fn ($f) => str_contains($f->getPathname(), '/css/app.css'))
        ->merge(File::allFiles(app_path()))
        // ⚠️ **Anche `js`/`ts`/`vue`**: Tailwind non guarda l'estensione per
        // decidere se un file può contenere una classe, e una stringa in un
        // sorgente JavaScript finisce nel bundle come da una vista.
        ->filter(fn ($f) => in_array($f->getExtension(), ['php', 'html', 'js', 'ts', 'vue', 'jsx', 'tsx'], true))
        ->map(fn ($f) => $f->getRealPath())
        ->values()
        ->all();
}

/**
 * Le tonalità di **scala** dichiarate in `@theme`, come `famiglia-gradino`.
 *
 * ⚠️ **Sta qui e non in un file di test** per la stessa ragione di
 * `sorgentiDiStile()` e di `snapshotDa()`: la usano **due** suite —
 * `PaletteGuardrailTest` («ogni tonalità usata esiste?») e
 * `SuperficiTokenizzateGuardrailTest` («nessuna vista usa più una tonalità di
 * scala per una superficie?»). Una funzione condivisa da due suite non può
 * abitare in una delle due: i file di test si caricano solo se selezionati,
 * quindi `php artisan test tests/Feature/SuperficiTokenizzateGuardrailTest.php`
 * andrebbe in **fatal per funzione non definita**. Verificato il 26 Ago 2026,
 * non dedotto.
 *
 * ⚠️ Si legge **solo il blocco `@theme`** e non tutto il foglio: più in basso
 * `app.css` *usa* `var(--color-neutral-200)` dentro `:root` (per `--border`), e
 * un uso non è una definizione. Confonderli renderebbe verde il guardrail della
 * palette su una variabile che nessuno ha mai dichiarato.
 *
 * ⚠️ **Il gradino vuole 2–3 cifre**, e non è pedanteria: `--color-ink-2` è un
 * token **semantico** con un numero d'ordine a una cifra, non la tonalità `2`
 * della famiglia `ink`. Con `\d+` finirebbe qui dentro e `ink` diventerebbe una
 * «famiglia di scala», con la conseguenza che `text-ink-2` — l'uso corretto —
 * risulterebbe una violazione.
 *
 * @return list<string> ordinate, es. `['danger-100', 'danger-500', …]`
 */
function tonalitaDefiniteNelTema(?string $css = null): array
{
    // ⚠️ **Il foglio si può passare, e non è per comodità**: è il solo modo di
    // provare che l'estrazione legge davvero il **solo** `@theme`. Oggi
    // `app.css` non ha nessuna definizione `--color-*` fuori da quel blocco,
    // quindi la mutazione «leggi tutto il foglio» è un no-op e il test che la
    // cercava era verde per assenza di caso — non per merito. Con un foglio
    // sintetico il caso esiste. È la stessa forma di `CatturaErrori::identifica()`,
    // che prende le posizioni già estratte per poter provare l'impronta contro
    // un deploy diverso.
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

/**
 * Le famiglie di colore **di scala** (`primary`, `neutral`, `danger`…), derivate
 * dalle tonalità definite.
 *
 * ⚠️ **Derivate e non elencate**: il giorno in cui nascesse un `--color-brand-500`,
 * `brand` entrerebbe nei controlli senza che nessuno debba ricordarsene, e il
 * giorno in cui `obsolete` sparisse ne uscirebbe. È la differenza fra una rete
 * che segue il progetto e una che va aggiornata a mano dopo.
 *
 * @return list<string>
 */
function famiglieDelTema(?string $css = null): array
{
    $famiglie = array_map(fn (string $t) => explode('-', $t)[0], tonalitaDefiniteNelTema($css));
    sort($famiglie);

    return array_values(array_unique($famiglie));
}

/**
 * Toglie da un sorgente ciò che **sembra** markup e non lo è.
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
 * già a posto. ⚠️ **Ma è falsificabile solo dai test sintetici qui sotto**, non
 * dal repository: togliendola, il resto della suite resta verde. Dirlo qui è il
 * punto — una guardia che si crede coperta dai dati veri e non lo è vale meno di
 * una dichiarata inerte.
 *
 * ⚠️ **I commenti Blade si tolgono** per la ragione già imparata dal guardrail
 * della copertura audit: un docblock che spiega *perché* una classe non si usa
 * più non è un uso di quella classe, e un meta-test che legge il testo invece
 * del codice punisce chi documenta.
 */
function sorgenteSenzaStileNeCommenti(string $sorgente): string
{
    $sorgente = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $sorgente);

    return preg_replace('/\{\{--.*?--\}\}/s', '', $sorgente);
}

/**
 * I prefissi delle utility che prendono un colore.
 *
 * La lista è di **prefissi** e non di colori: è stabile quanto Tailwind, e non è
 * la copia di niente che viva nel progetto.
 *
 * ⚠️ **`border` e `divide` prendono anche il lato**, e senza il suffisso
 * opzionale questa rete era cieca proprio alla classe di guasto per cui è nata:
 * verificato, `border-t-neutral-950` passava indisturbato mentre
 * `text-neutral-950` era rosso — e `border-t-neutral-950` genera davvero una
 * regola col grigio **acromatico** di Tailwind, cioè il difetto che questo file
 * esiste per rendere rumoroso.
 */
function prefissiDiColore(): string
{
    return '(?:border|divide)(?:-[trblxyse])?|bg|text|ring|from|to|via|fill|stroke|outline|decoration|accent|caret|placeholder|shadow';
}
