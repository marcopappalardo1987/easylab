<?php

use App\Models\Piano;
use App\Support\Listino\CatalogoPiani;
use App\Support\Piani;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 La forma dello schema del listino, quella che `PianiTest` dichiara di aver
 * delegato altrove (🔗 ADR-035).
 *
 * ## Perché questo file esiste, e perché è nominato altrove
 *
 * `PianiTest::no longer lets a plan be declared by halves at all` racconta una
 * sostituzione: la guardia che in PHP rifiutava un piano senza prezzo è
 * diventata un **vincolo di schema**, «la forma migliore, perché non si può
 * dimenticare» — e rimanda qui per la prova. Il rimando è rimasto senza il file,
 * cioè con una frase rassicurante e nessuna rete dietro: è la stessa forma di
 * difetto che il progetto ha già pagato altrove, un commento che dice «un test
 * lo prende» quando quel test non esiste.
 *
 * ⚠️ **E la prova va fatta sul MOTORE, non sul modello.** `Piano` dichiara dei
 * `$attributes` di default (`prezzo_mensile_cent => 0`, `valuta => 'eur'`),
 * quindi un `new Piano` non riesce nemmeno a esprimere la riga scritta a metà:
 * scrivere con Eloquent proverebbe i default del model e non i vincoli della
 * tabella. Qui si passa quindi dal **query builder**, che è anche la strada da
 * cui arriva la migration di backfill.
 *
 * ⚠️ **Le tre migration del listino non sono ancora applicate al database di
 * sviluppo, di proposito**: la suite gira su SQLite ricreato da zero, ed è lì che
 * questi vincoli vengono verificati.
 */

/** La riga minima valida, da mutilare campo per campo. */
function unaRigaDiPiano(array $sovrascritture = []): array
{
    return array_merge([
        'codice' => 'gold',
        'etichetta' => 'Gold',
        'max_enti' => 9,
        'gratuito' => false,
        'prezzo_mensile_cent' => 9900,
        'valuta' => 'eur',
        'attivo' => true,
        'ordine' => 9,
        'created_at' => now(),
        'updated_at' => now(),
    ], $sovrascritture);
}

// ─── Ciò che non si può scrivere ─────────────────────────────────────────────

it('refuses two plans with the same codice, because accounts.piano keeps it by string', function () {
    // 🔴 Il `codice` è la **chiave vera** del listino: `accounts.piano` lo
    // conserva come stringa senza FK, quindi due righe con lo stesso codice
    // renderebbero arbitrario ogni `Piani::modello()` — e arbitraria la cifra
    // che finisce nell'MRR. L'unicità è a database e non solo validata in PHP,
    // perché `GovernoListino` non è l'unica strada verso questa tabella: la
    // migration di backfill scrive col query builder.
    expect(fn () => DB::table('piani')->insert(unaRigaDiPiano(['codice' => 'saas'])))
        ->toThrow(QueryException::class);

    expect(Piano::query()->where('codice', 'saas')->count())->toBe(1);
});

it('refuses a plan declared by halves, which used to be a guard in PHP', function () {
    // *Era `PianiTest::refuses a plan declared by halves`, che scriveva un piano
    // senza prezzo in config e verificava che il getter lanciasse.* Col listino a
    // database quel piano non è più **esprimibile**, e questa è la prova che la
    // sostituzione è avvenuta davvero.
    //
    // Le tre colonne insieme e non una per test: sono la stessa decisione — ciò
    // che decide il denaro e l'identità non può mancare — e separarle
    // produrrebbe tre corpi identici.
    foreach (['codice', 'etichetta', 'prezzo_mensile_cent'] as $colonna) {
        expect(fn () => DB::table('piani')->insert(unaRigaDiPiano([$colonna => null])))
            ->toThrow(QueryException::class);
    }

    expect(Piano::query()->where('codice', 'gold')->exists())->toBeFalse();
});

it('refuses two rows for the same Stripe price, which would make the webhook arbitrary', function () {
    // 🔴 `stripe_price_id` è la chiave con cui il webhook risale al piano
    // (`Piani::perPrice()`). Un duplicato significherebbe **due piani per lo
    // stesso price**: il riallineamento di `accounts.piano` dipenderebbe
    // dall'ordine di caricamento, e il piano di un cliente cambierebbe da solo.
    // `GovernoListino::agganciaPrezzo()` lo rifiuta con un messaggio; qui si
    // congela che nemmeno una scrittura diretta possa produrlo.
    $saas = Piani::modello('saas');
    $free = Piani::modello('free');

    $riga = fn (int $pianoId) => [
        'piano_id' => $pianoId,
        'stripe_price_id' => 'price_conteso',
        'importo_cent' => 4900,
        'valuta' => 'eur',
        'corrente' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('prezzi_piano')->insert($riga($saas->id));

    expect(fn () => DB::table('prezzi_piano')->insert($riga($free->id)))
        ->toThrow(QueryException::class);
});

it('refuses a price row that decides money without saying how much or in what currency', function () {
    $saas = Piani::modello('saas');

    $riga = fn (array $sovrascritture) => array_merge([
        'piano_id' => $saas->id,
        'stripe_price_id' => 'price_'.uniqid(),
        'importo_cent' => 4900,
        'valuta' => 'eur',
        'corrente' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $sovrascritture);

    foreach (['piano_id', 'stripe_price_id', 'importo_cent', 'valuta'] as $colonna) {
        expect(fn () => DB::table('prezzi_piano')->insert($riga([$colonna => null])))
            ->toThrow(QueryException::class);
    }
});

// ─── Ciò che si può scrivere, e che va potuto scrivere ───────────────────────

it('accepts a null cap, because that is how «unlimited» is written', function () {
    // `null` = **illimitato**, non «non lo so»: è la forma che un piano
    // Enterprise ha davvero. Se la colonna fosse NOT NULL, quella forma andrebbe
    // espressa con un numero grande a caso — che è il difetto che
    // `Piani::maxEnti()` documenta di voler evitare.
    DB::table('piani')->insert(unaRigaDiPiano(['max_enti' => null]));

    app(CatalogoPiani::class)->dimentica();

    expect(Piani::maxEnti('gold'))->toBeNull();
});

// ─── La cascata ──────────────────────────────────────────────────────────────

it('takes the price history down with the plan, so no row is left pointing nowhere', function () {
    // ⚠️ **Non è un invito a cancellare un piano** — in questa applicazione un
    // piano si *archivia*, mai si elimina, e `GovernoListino` non offre nessuna
    // cancellazione. È la rete per la strada che resta aperta comunque: un
    // `delete` a mano sul database, o un `down()` di migration. Senza la cascata,
    // `prezzi_piano` conserverebbe righe con un `piano_id` che non esiste più, e
    // `Piani::perPrice()` risolverebbe un price su un piano fantasma.
    $saas = Piani::modello('saas');

    $saas->prezzi()->create([
        'stripe_price_id' => 'price_da_cancellare',
        'importo_cent' => 4900,
        'valuta' => 'eur',
        'corrente' => true,
    ]);

    expect(DB::table('prezzi_piano')->where('piano_id', $saas->id)->count())->toBe(1);

    DB::table('piani')->where('id', $saas->id)->delete();

    expect(DB::table('prezzi_piano')->where('piano_id', $saas->id)->count())->toBe(0);
});
