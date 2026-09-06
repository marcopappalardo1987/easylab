<?php

use Illuminate\Support\Carbon;

/**
 * 🔴 Meta-test del **fuso orario** (🔗 ADR-041).
 *
 * ## Le due metà, e sbagliano in modo opposto
 *
 * L'app è italiana e i suoi confini sono **giorni civili italiani**: la data di
 * esecuzione di un intervento, la soglia a trenta giorni del semaforo,
 * l'obsolescenza in anni. Da qui `Europe/Rome` in `config/app.php`.
 *
 * ⛔ Ma la conversione non deve **mai** entrare nella logica di dominio, e le
 * due metà falliscono in modi diversi:
 *
 * - **Fuso sbagliato in config** → fra mezzanotte e le 02:00 italiane `today()`
 *   torna a essere ieri, e un tecnico che chiude un intervento a tarda sera non
 *   può registrarlo con la data di oggi (`before_or_equal:today`). Rumoroso per
 *   chi ci sbatte, invisibile a chiunque altro: capita due ore su ventiquattro.
 * - **`setTimezone()` dentro il dominio** → una colonna castata `date` è un
 *   giorno puro, cioè una mezzanotte: convertirla la sposta di un giorno, e una
 *   scadenza cambia data. Silenzioso e permanente.
 *
 * ## Perché l'insieme si deriva
 *
 * L'elenco dei file da sorvegliare **non** è scritto a mano: si legge da
 * `app/Models` e dai due punti in cui vivono i confronti con `today()`. Un
 * elenco copiato invecchierebbe al primo modello nuovo, e il guardrail
 * resterebbe verde proprio dove ha smesso di guardare.
 *
 * ⚠️ Non sorveglia `app/Support/Piattaforma/AndamentiPiattaforma.php`, che i
 * confini di mese li costruisce apposta: quelli sono istanti, non giorni di
 * dominio, e ora sono istanti italiani come il resto.
 */
it('runs on Italian time, without depending on the environment', function () {
    // Il default sta in config/app.php e non solo in .env, come per `locale`:
    // una dimenticanza al deploy riporterebbe l'app due ore indietro senza che
    // nessuno se ne accorga finché non lo legge un cliente.
    expect(config('app.timezone'))->toBe('Europe/Rome')
        ->and(date_default_timezone_get())->toBe('Europe/Rome');
});

it('makes `today()` the Italian day, which is the whole point', function () {
    // 🔴 Il caso vero, e l'unico che distingue questo fuso da UTC: l'01:30
    // italiana del 15 giugno è ancora il 14 giugno a Greenwich. Con l'app a UTC
    // `today()` qui dava il 14, cioè il giorno prima di quello che il tecnico
    // ha sull'orologio.
    Carbon::setTestNow(Carbon::create(2026, 6, 15, 1, 30, 0, 'Europe/Rome'));

    expect(today()->toDateString())->toBe('2026-06-15')
        ->and(now()->format('H:i'))->toBe('01:30');

    // E in ora solare, dove lo scarto è di un'ora sola: la finestra si
    // stringe ma non sparisce, quindi il caso va provato in entrambe le stagioni
    // o si proverebbe solo l'ora legale.
    Carbon::setTestNow(Carbon::create(2026, 1, 15, 0, 30, 0, 'Europe/Rome'));

    expect(today()->toDateString())->toBe('2026-01-15');

    Carbon::setTestNow();
});

it('keeps the timezone out of the domain, where it would move a whole day', function () {
    $colpevoli = [];

    foreach (fileDiDominio() as $file) {
        $sorgente = file_get_contents($file);

        // `setTimezone(`, `->tz(` e il letterale del fuso: le tre forme con cui
        // una conversione può entrare in un confronto di dominio.
        if (preg_match('/->setTimezone\(|->tz\(|Europe\/Rome/', $sorgente) === 1) {
            $colpevoli[] = str_replace(base_path().'/', '', $file);
        }
    }

    // ⚠️ `toBeEmpty()` e non `toContain()`: quest'ultimo è **variadico** e un
    // testo passato come secondo argomento diventerebbe un secondo ago, non un
    // messaggio — l'errore che questo progetto ha già pagato due volte con un
    // guardrail di privacy vuoto. La spiegazione sta nel nome del test e qui
    // sopra, mai dentro l'asserzione.
    expect($colpevoli)->toBeEmpty();
});

it('derives the watched set instead of listing it, so a new model cannot slip through', function () {
    $file = fileDiDominio();

    // Se la derivazione si rompesse, l'insieme sarebbe vuoto e il test qui
    // sopra passerebbe **senza guardare niente**: è la forma di falso verde che
    // un guardrail per grep deve escludere per prima.
    expect($file)->not->toBeEmpty()
        ->and(count($file))->toBeGreaterThan(5);

    $nomi = array_map(fn (string $f) => basename($f), $file);

    // I due punti in cui vivono i confronti con `today()` fuori dai modelli.
    expect($nomi)->toContain('Semaforo.php', 'ScadenzarioParco.php', 'Intervento.php');
});

/**
 * I file in cui una conversione di fuso sarebbe un difetto: i modelli, che
 * portano i cast `date`, più i due luoghi che confrontano quelle colonne con
 * `today()`.
 *
 * @return list<string>
 */
function fileDiDominio(): array
{
    return [
        ...glob(app_path('Models/*.php')),
        app_path('Support/Semaforo.php'),
        app_path('Support/Piattaforma/ScadenzarioParco.php'),
    ];
}
