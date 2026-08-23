<?php

use App\Livewire\Piattaforma\Errori;
use App\Models\Errore;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * 🔴 L'elenco dell'error tracker interno: cosa mostra, in che ordine, e a che
 * prezzo (S6, blocco 5).
 *
 * `AccessoErroriTest` risponde a *chi entra*; questo file a *cosa trova*. Sono
 * due suite e non una perché la prima è nata col guscio vuoto e vale ancora
 * identica: mescolarle avrebbe rimesso in discussione dei 403 già provati ogni
 * volta che cambia una colonna della tabella.
 *
 * ⚠️ **Il tracker è acceso durante la suite** — `CatturaErrori` è agganciato in
 * `bootstrap/app.php`, e ogni eccezione riportata da un qualunque test scrive in
 * `errori`. Da cui la regola di questo file: si conta e si cerca **per
 * impronta** o per id, **mai** con `Errore::count()`, che misurerebbe anche il
 * rumore di fondo.
 *
 * ⚠️ Le fixture passano da `issueErrore()` (in `tests/Pest.php`), che fa
 * `forceFill()`: il `$fillable` di `Errore` elenca solo ciò che il tracker
 * scrive alla nascita di una issue, quindi `stato`, `occorrenze` e `contesti`
 * verrebbero **scartati in silenzio** da un `create()` — e il test del filtro
 * proverebbe il default invece del filtro.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->developer = utenteConRuolo('Developer');
});

/**
 * Il `<tr>` di una issue, estratto per la sua `wire:key`.
 *
 * ⚠️ Si estrae il **blocco** invece di asserire sull'HTML intero, ed è la
 * cicatrice che questo lavoro ha già pagato tre volte: `assertSee` su una parola
 * che compare anche altrove nella pagina è un'asserzione **che non può
 * fallire**, e occupa il posto di quella vera. Vive in questo file e non in
 * `tests/Pest.php` perché la usa una suite sola — la disciplina è la reciproca
 * di quella che là ha portato `snapshotDa()` e `rigaDelPermesso()`.
 */
function rigaErrore(string $html, int $id): string
{
    preg_match('/<tr wire:key="errore-'.$id.'".*?<\/tr>/s', $html, $blocco);

    // Non `?? ''`: un blocco assente e un blocco vuoto vanno distinti, o un
    // `not->toContain()` sarebbe verde proprio quando la riga è sparita.
    expect($blocco)->not->toBeEmpty();

    return $blocco[0];
}

/**
 * L'HTML dell'elenco montato come Developer, coi filtri passati **in query
 * string**.
 *
 * ⚠️ `withQueryParams()` e non `set()`, e non è equivalente: `set()` monta il
 * componente col default e poi lo **rirenderizza**, cioè due render e il doppio
 * delle query — il conteggio esatto misurerebbe due pagine invece di una. La
 * query string è anche la strada da cui i filtri arrivano davvero, essendo
 * `#[Url]`.
 */
function elenco(array $query = []): string
{
    return Livewire::withQueryParams($query)
        ->actingAs(test()->developer)
        ->test(Errori::class)
        ->html();
}

// ─── Le due cifre ────────────────────────────────────────────────────────────

it('says the two figures in the same sentence', function () {
    // 🔴 **Il test dell'intero blocco.** `occorrenze` conta *tutti* gli
    // avvenimenti, `contesti` quante prove se ne sono conservate — al più venti.
    // Stampare la prima da sola manda chi legge a cercare 10.412 schede di
    // dettaglio che non esistono, e a concludere che il tracker perde dati.
    //
    // ⚠️ **Si asserisce sulla frase composta**, non sui due numeri separati: la
    // pagina stampa `20` anche nel cartiglio in cima («al più 20 per errore») e
    // `10.412` anche nel piè di pagina della paginazione, quindi due
    // `toContain('20')` sarebbero verdi anche con la seconda cifra tolta dalla
    // riga. È l'unica forma che diventa rossa se qualcuno le separa.
    $errore = issueErrore(['occorrenze' => 10412, 'contesti' => 20]);

    expect(rigaErrore(elenco(), $errore->id))
        ->toContain('occorrenze: 10.412 · contesti conservati: 20');
});

it('formats both figures in Italian, thousands included', function () {
    // Il separatore delle migliaia è il punto: `10412` nudo si legge male
    // proprio sulle cifre grandi, che sono quelle per cui il contatore esiste.
    $errore = issueErrore(['occorrenze' => 1234567, 'contesti' => 0]);

    expect(rigaErrore(elenco(), $errore->id))
        ->toContain('occorrenze: 1.234.567 · contesti conservati: 0');
});

// ─── L'ordine ────────────────────────────────────────────────────────────────

it('puts the most recently seen issue first', function () {
    // Su un tracker «cosa si sta rompendo adesso» è la sola domanda che ci si
    // pone aprendo la pagina: l'ordine è parte della risposta, non estetica.
    $vecchia = issueErrore(['classe' => 'App\\Guasti\\Vecchia', 'ultima_occorrenza_at' => now()->subDays(3)]);
    $mezzo = issueErrore(['classe' => 'App\\Guasti\\Mezzo', 'ultima_occorrenza_at' => now()->subDay()]);
    $recente = issueErrore(['classe' => 'App\\Guasti\\Recente', 'ultima_occorrenza_at' => now()]);

    $html = elenco();

    // Sulle **posizioni** e non su una collection del componente: ciò che deve
    // stare in cima è ciò che l'utente legge in cima.
    $dove = fn (Errore $e): int => (int) strpos($html, 'wire:key="errore-'.$e->id.'"');

    expect(rigaErrore($html, $recente->id))->toContain('Recente')
        ->and($dove($recente))->toBeLessThan($dove($mezzo))
        ->and($dove($mezzo))->toBeLessThan($dove($vecchia));
});

// ─── Le colonne ──────────────────────────────────────────────────────────────

it('shows the class, the relative file and the line', function () {
    $errore = issueErrore([
        'classe' => 'Illuminate\\Database\\QueryException',
        'file' => 'app/Support/Errori/CatturaErrori.php',
        'riga' => 361,
    ]);

    // Il percorso è **relativo** alla base del progetto: su Cloud la directory
    // di deploy cambia a ogni release, e un percorso assoluto renderebbe la
    // colonna illeggibile il giorno dopo.
    expect(rigaErrore($html = elenco(), $errore->id))
        ->toContain('QueryException')
        ->toContain('app/Support/Errori/CatturaErrori.php:361')
        // Il namespace per esteso sotto il nome corto: due `RuntimeException`
        // di librerie diverse sono due bug diversi.
        ->toContain('Illuminate\\Database\\QueryException')
        ->and($html)->toContain(route('piattaforma.errori.mostra', $errore));
});

it('names who closed an issue, and survives their deletion', function () {
    // ⚠️ `risolto_da` è una FK `nullOnDelete`: la storia dei bug sopravvive a
    // chi l'ha scritta, quindi la vista deve reggere una relazione **vuota** su
    // una riga che dice «risolto». Se ci cadesse, l'intera pagina cadrebbe con
    // lei — e succederebbe mesi dopo, cancellando un utente.
    $chiusa = issueErrore(['stato' => 'risolto', 'risolto_da' => User::factory()->create(['name' => 'Marta Riva'])->id]);
    $orfana = issueErrore(['stato' => 'risolto', 'risolto_da' => null]);

    $html = elenco(['stato' => 'risolto']);

    expect(rigaErrore($html, $chiusa->id))->toContain('Marta Riva')
        ->and(rigaErrore($html, $orfana->id))->toContain('un utente non più presente');
});

// ─── Il filtro di stato ──────────────────────────────────────────────────────

it('shows only the open issues by default', function () {
    // Il default è `aperto` e non «tutti», come dichiara l'indice della
    // migration: chi apre questa pagina cerca cosa è rotto **adesso**. È anche
    // ciò che rende `ignorato` un vero interruttore di silenzio invece di
    // un'etichetta in mezzo al rumore.
    $aperta = issueErrore(['stato' => 'aperto']);
    $risolta = issueErrore(['stato' => 'risolto']);
    $ignorata = issueErrore(['stato' => 'ignorato']);

    $html = elenco();

    expect($html)->toContain('wire:key="errore-'.$aperta->id.'"')
        ->and($html)->not->toContain('wire:key="errore-'.$risolta->id.'"')
        ->and($html)->not->toContain('wire:key="errore-'.$ignorata->id.'"');
});

it('shows exactly the issues of the state asked for', function (string $stato) {
    $atteso = issueErrore(['stato' => $stato]);
    $altri = collect(['aperto', 'risolto', 'ignorato'])
        ->reject(fn (string $s) => $s === $stato)
        ->map(fn (string $s) => issueErrore(['stato' => $s]));

    $html = elenco(['stato' => $stato]);

    expect($html)->toContain('wire:key="errore-'.$atteso->id.'"');

    foreach ($altri as $altro) {
        expect($html)->not->toContain('wire:key="errore-'.$altro->id.'"');
    }
})->with(['aperto', 'risolto', 'ignorato']);

it('shows every state when the filter is cleared', function () {
    $issue = collect(['aperto', 'risolto', 'ignorato'])->map(fn (string $s) => issueErrore(['stato' => $s]));

    $html = elenco(['stato' => '']);

    foreach ($issue as $riga) {
        expect($html)->toContain('wire:key="errore-'.$riga->id.'"');
    }
});

it('ignores a state that is not in the catalogue, instead of blowing up', function () {
    // ⚠️ Il valore arriva dalla **query string** (`#[Url]`) e finisce in una
    // clausola `where`: si valida contro la whitelist. E si **ignora in
    // silenzio** invece di lanciare, perché un link con `?stato=pippo` deve
    // mostrare l'elenco senza quel filtro, non una pagina di errore.
    //
    // Si passa dalla rotta vera e non da `set()`: è la strada da cui il valore
    // arriva davvero, e l'unica che prova il binding di `#[Url]`.
    $issue = collect(['aperto', 'risolto', 'ignorato'])->map(fn (string $s) => issueErrore(['stato' => $s]));

    $risposta = $this->actingAs($this->developer)
        ->get(route('piattaforma.errori', ['stato' => 'pippo']))
        ->assertOk();

    foreach ($issue as $riga) {
        expect($risposta->getContent())->toContain('wire:key="errore-'.$riga->id.'"');
    }

    // E il `select` mostra ciò che è stato **applicato**, non la property: una
    // tendina che dice «Aperti» davanti a un elenco non filtrato è una bugia
    // piccola, e per questo credibile.
    expect($risposta->getContent())->toContain('<option value="">Tutti</option>');
});

// ─── Il vuoto, che è due fatti diversi ───────────────────────────────────────

it('tells an empty tracker from a filter that finds nothing', function () {
    // Due messaggi, perché mandano a fare due cose opposte: «nessun errore con
    // questo filtro» davanti a un tracker vuoto manda a cercare un filtro che
    // non c'è, e «nessun errore registrato» davanti a un filtro attivo dice che
    // va tutto bene quando magari non è vero.
    expect(elenco(['stato' => '']))->toContain('Nessun errore registrato.');

    issueErrore(['stato' => 'aperto']);

    expect(elenco(['stato' => 'ignorato']))->toContain('Nessun errore con questo filtro.');
});

// ─── Il costo ────────────────────────────────────────────────────────────────

it('costs the same number of queries on a full page as on two rows', function () {
    // 🔴 **Con risolutori diversi, o la prova non può fallire.** La colonna
    // «Stato» nomina chi ha chiuso una issue: senza `with('risoltoDa')` è una
    // query per riga risolta. Ma se in fixture `risolto_da` fosse sempre `null`,
    // Eloquent salterebbe del tutto l'eager load (nessuna chiave da caricare) e
    // il conteggio sarebbe identico con e senza — cioè un anti-N+1 verde su
    // codice rotto. È la stessa cicatrice di `RegistroAuditTest`, dove la prima
    // stesura creava righe nude e l'N+1 sul causer passava inosservato.
    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        elenco(['stato' => '']);
        $n = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'from "errori"')
                || str_contains($q['query'], 'from "users"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    // Scaldare la cache dei permessi prima di misurare, o si confronta il primo
    // render col secondo invece del numero di righe.
    elenco();

    $issue = function (int $n): void {
        for ($i = 0; $i < $n; $i++) {
            issueErrore([
                // Metà risolte, **ciascuna da un utente diverso**: è ciò che
                // rende l'N+1 misurabile.
                'stato' => $i % 2 === 0 ? 'risolto' : 'aperto',
                'risolto_da' => $i % 2 === 0 ? User::factory()->create()->id : null,
                'ultima_occorrenza_at' => now()->subMinutes($i),
            ]);
        }
    };

    $issue(2);
    $due = $conta();

    // Oltre la pagina (25), così che la pagina sia **piena**: la prima stesura
    // di prove come questa misurava tre righe, cioè un caso in cui perfino un
    // N+1 vero costa poco.
    $issue(38);
    $quaranta = $conta();

    expect($due)->toBe(3)
        ->and($quaranta)->toBe($due);
});

it('never lets the page grow past its own page size', function () {
    for ($i = 0; $i < 30; $i++) {
        issueErrore(['ultima_occorrenza_at' => now()->subMinutes($i)]);
    }

    $html = elenco();

    expect(substr_count($html, 'wire:key="errore-'))->toBe(25)
        ->and($html)->toContain('1–25 di 30 errori');
});

it('shows the state and the dates a person can read, not only the markers', function () {
    // 🔴 **Quarta ricomparsa dello stesso difetto in questa feature**: i test
    // identificavano le righe per `wire:key` — un marcatore che esiste solo per
    // loro — e nessuno guardava ciò che l'utente legge. Verificato: facendo
    // dire «Aperto» a tutti e tre i badge, e svuotando l'intera colonna delle
    // date, la suite restava tutta verde.
    $aperto = issueErrore([
        'classe' => 'RuntimeException',
        'prima_occorrenza_at' => now()->subDays(3),
        'ultima_occorrenza_at' => now()->subHour(),
    ]);
    $risolto = issueErrore(['classe' => 'LogicException', 'stato' => 'risolto']);
    $ignorato = issueErrore(['classe' => 'DomainException', 'stato' => 'ignorato']);
    $riaperto = issueErrore([
        'classe' => 'TypeError',
        'riaperto_automaticamente_at' => now()->subMinutes(5),
    ]);

    $html = elenco(['stato' => '']);

    // Ogni stato dice **il proprio nome**, e non quello di un altro.
    expect(rigaErrore($html, $aperto->id))->toContain('Aperto')
        ->and(rigaErrore($html, $risolto->id))->toContain('Risolto')
        ->and(rigaErrore($html, $risolto->id))->not->toContain('Aperto')
        ->and(rigaErrore($html, $ignorato->id))->toContain('Ignorato')
        ->and(rigaErrore($html, $ignorato->id))->not->toContain('Aperto')
        // La riapertura automatica si vede: è il segnale che una correzione
        // non ha tenuto, e senza di esso la riga è indistinguibile da un errore
        // mai chiuso.
        ->and(rigaErrore($html, $riaperto->id))->toContain('riaperto il');

    // E le date si leggono davvero, nel formato italiano.
    expect(rigaErrore($html, $aperto->id))
        ->toContain($aperto->prima_occorrenza_at->format('d/m/Y'))
        ->toContain($aperto->ultima_occorrenza_at->format('d/m/Y H:i'));
});
