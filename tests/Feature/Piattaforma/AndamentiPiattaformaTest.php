<?php

use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piattaforma\AndamentiPiattaforma;
use App\Support\Piattaforma\MetrichePiattaforma;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 Gli andamenti a dodici mesi della cabina di regia (S6 — Wireframe §4).
 *
 * Area rossa due volte: **tenancy** (le serie nascono da builder non scopati) e
 * **numeri riportabili a terzi** (una curva di crescita si mostra a un socio più
 * facilmente di una tabella). Il rischio qui non è il 500 — quello lo copre
 * `GeometriaGraficoTest` — è la cifra *plausibile e diversa*: un grafico che
 * chiude sotto il KPI della tile che gli sta a due centimetri, e che nessuno
 * verifica proprio perché sembra ragionevole.
 *
 * ⚠️ **Tutti i confini sono in `Europe/Rome` esplicito**, perché è ciò che
 * `config/app.php` dichiara dal 6 Set 2026 (🔗 ADR-041): il fuso si scrive per
 * esteso e non si lascia al default della macchina, o il test misurerebbe
 * l'ambiente invece del codice. *Fino a quel giorno erano in UTC esplicito, per
 * la stessa ragione e con l'altro fuso.* E i confini di mese vanno rigirati una volta su
 * Postgres (`DB_CONNECTION=pgsql DB_DATABASE=easylab_test CACHE_STORE=array`),
 * perché su SQLite il confronto è **lessicografico** sulla stringa e su Postgres
 * è un cast a timestamp.
 */
beforeEach(function () {
    // Un istante fisso: senza, ogni asserzione su un bucket dipenderebbe dal
    // giorno in cui la suite gira, e il file diventerebbe rosso a Capodanno.
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 15, 12, 0, 0, 'Europe/Rome'));

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    $this->cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

// ─── I negativi, per primi ───────────────────────────────────────────────────

it('denies every series to a user without tenants.view_all', function () {
    // Nessuna guardia nuova è stata scritta, ed è il punto: ogni serie parte da
    // `PerimetroClienti`, che parte da `VistaPiattaforma`, che fa
    // `Gate::authorize()` **prima** di consegnare il builder. Il permesso è
    // chiesto per costruzione — questo test è ciò che lo dimostra invece di
    // lasciarlo credere.
    $tenant = User::factory()->create(['tenant_id' => $this->sede->id]);
    $tenant->assignRole('Tenant');
    $this->actingAs($tenant->fresh());

    expect(fn () => AndamentiPiattaforma::ultimiDodiciMesi())
        ->toThrow(AuthorizationException::class);
});

it('denies every series to a guest, which is where a console call would land', function () {
    // `Gate::authorize()` nega sempre senza utente: è la ragione per cui questa
    // classe non va chiamata da console, job o scheduler (il precedente vivo è
    // `NotificaScadenze`). Senza questo test la regola vivrebbe solo in un
    // docblock.
    auth()->logout();

    expect(fn () => AndamentiPiattaforma::ultimiDodiciMesi())
        ->toThrow(AuthorizationException::class);
});

it('never counts EasyLab in any series', function () {
    // Il precedente vero: sul database di sviluppo l'Ente di EasyLab e le sue
    // macchine erano **1.217 strumenti su 5.105**, il 24% di un numero
    // etichettato «macchine di tutti i clienti». Senza la sede e lo strumento
    // nella fixture, la correzione non sarebbe dimostrabile — è lo stesso
    // errore che la prima stesura di `KpiPiattaformaTest` aveva fatto.
    $easylab = Account::factory()->diPiattaforma()->saas()->create(['ragione_sociale' => 'EasyLab']);
    $sedeSua = UnitaOrganizzativa::factory()->ente()->perAccount($easylab)->create();
    Strumento::factory()->forNode($sedeSua)->create();

    Strumento::factory()->forNode($this->sede)->create();

    $a = AndamentiPiattaforma::ultimiDodiciMesi();

    expect($a->clientiCumulati->ultimo())->toBe(1)
        ->and($a->sediCumulate->ultimo())->toBe(1)
        ->and($a->strumentiCumulati->ultimo())->toBe(1)
        ->and($a->nuoviClienti->totale())->toBe(1);
});

it('leaves the trash out of every series', function () {
    // Un cestinato sparisce **retroattivamente** da tutta la serie, non solo
    // dall'ultimo punto: è la conseguenza dichiarata di tenere il
    // `SoftDeletingScope` dentro `VistaPiattaforma::accounts()`, ed è il motivo
    // per cui la curva sale e basta. Il churn è invisibile, e la pagina lo dice.
    $cestinato = Account::factory()->saas()->create();
    $suaSede = UnitaOrganizzativa::factory()->ente()->perAccount($cestinato)->create();
    Strumento::factory()->forNode($suaSede)->create();
    $cestinato->delete();

    Strumento::factory()->forNode($this->sede)->create()->delete();

    $a = AndamentiPiattaforma::ultimiDodiciMesi();

    expect($a->clientiCumulati->ultimo())->toBe(1)
        ->and($a->sediCumulate->ultimo())->toBe(1)
        ->and($a->strumentiCumulati->ultimo())->toBe(0);
});

// ─── Gli accordi con i KPI, che è la ragione per cui PerimetroClienti esiste ──

it('ends each series on the same number the KPI shows', function () {
    // ⚠️ È **l'unico** modo di impedire che le due passate divergano. Il guasto
    // che questo test previene non è un errore: è un grafico che chiude a 11
    // sotto una tile che dice 12, a due centimetri di distanza, senza che nulla
    // si lamenti. Se un giorno diventasse rosso, la risposta non è aggiustare il
    // numero atteso — è che qualcuno ha riscritto la definizione di «cliente» in
    // uno dei due posti.
    collect(range(1, 4))->each(function () {
        $acc = Account::factory()->saas()->create();
        $ente = UnitaOrganizzativa::factory()->ente()->perAccount($acc)->create();
        Strumento::factory()->count(2)->forNode($ente)->create();
    });

    Strumento::factory()->count(3)->forNode($this->sede)->create();

    $a = AndamentiPiattaforma::ultimiDodiciMesi();
    $r = MetrichePiattaforma::riepilogo();

    expect($a->clientiCumulati->ultimo())->toBe($r->clienti)
        ->and($a->sediCumulate->ultimo())->toBe($r->sedi)
        ->and($a->strumentiCumulati->ultimo())->toBe($r->strumenti);
});

it('keeps a row with a null created_at inside the cumulative total', function () {
    // ⛔ `$table->timestamps()` crea colonne **nullable**. Senza il ramo
    // `created_at is null or`, una riga senza data cade fuori da ogni `CASE` e
    // il grafico chiude **sotto** il KPI: il numero non è rotto, è plausibile e
    // diverso. Con il ramo, la riga sta nella base — «c'era già prima che la
    // finestra cominciasse» — e il cumulato torna sempre.
    $senzaData = Account::factory()->saas()->create();
    DB::table('accounts')->where('id', $senzaData->id)->update(['created_at' => null]);

    $a = AndamentiPiattaforma::ultimiDodiciMesi();

    expect($a->clientiCumulati->ultimo())->toBe(MetrichePiattaforma::riepilogo()->clienti)
        ->and($a->clientiCumulati->ultimo())->toBe(2)
        // E sta nella BASE, non in un mese: non è un cliente «nuovo» di
        // settembre 2025 solo perché non sappiamo quando è arrivato.
        ->and($a->clientiCumulati->primo())->toBe(1)
        ->and($a->nuoviClienti->totale())->toBe(1);
});

it('measures the twelve-month change over the whole window, base included', function () {
    // 🔴 Il difetto che questo test rende impossibile è **la cifra plausibile e
    // diversa**, un piano sotto quello che `PerimetroClienti` chiude. Il primo
    // punto della curva cumulata è `c1` — il totale alla FINE del primo bucket —
    // quindi `ultimo() - primo()` misura undici intervalli su dodici e perde
    // tutto ciò che è entrato nel mese di apertura della finestra.
    //
    // Scenario: tre clienti entrati a Set 2025, che è il **primo** bucket della
    // finestra Set 2025 → Ago 2026, e nessuno dopo. Sulla stessa schermata la
    // tile dice «Clienti 3», il grafico a barre disegna una barra da 3 su Set e
    // la tabella «Vedi i dati» riporta 3: la riga sotto la sparkline diceva «+0
    // in 12 mesi». E per Sedi e Strumenti quella riga è l'**unica**
    // informazione che esiste, perché accanto non c'è nemmeno un grafico a
    // smentirla.
    $altri = Account::factory()->count(2)->saas()->create();
    $strumento = Strumento::factory()->forNode($this->sede)->create();

    $set2025 = '2025-09-10 09:00:00';

    DB::table('accounts')
        ->whereIn('id', $altri->pluck('id')->push($this->cliente->id)->all())
        ->update(['created_at' => $set2025]);
    DB::table('unita_organizzativa')->where('id', $this->sede->id)->update(['created_at' => $set2025]);
    DB::table('strumenti')->where('id', $strumento->id)->update(['created_at' => $set2025]);

    $a = AndamentiPiattaforma::ultimiDodiciMesi();

    expect($a->clientiCumulati->valori)->toBe(array_fill(0, 12, 3))
        ->and($a->clientiCumulati->variazione())->toBe(3)
        ->and($a->clientiCumulati->variazioneConSegno())->toBe('+3')
        // ⚠️ L'accordo che tiene insieme le due letture della stessa card: la
        // riga di testo sotto la sparkline e il totale del grafico a barre che
        // le sta a due centimetri devono dire lo stesso numero.
        ->and($a->clientiCumulati->variazione())->toBe($a->nuoviClienti->totale())
        // E le due serie che un grafico a barre accanto **non** ce l'hanno.
        ->and($a->sediCumulate->variazione())->toBe(1)
        ->and($a->strumentiCumulati->variazione())->toBe(1);
});

it('leaves out of the twelve-month change what was already there before the window', function () {
    // L'altra metà della stessa regola, e la ragione per cui la correzione non
    // è «`ultimo()` e basta»: chi c'era già non è cresciuto adesso. Un cliente
    // entrato nel 2024 sta nella base, quindi la finestra racconta +1 e non +2.
    $anziano = Account::factory()->saas()->create();
    DB::table('accounts')->where('id', $anziano->id)
        ->update(['created_at' => '2024-01-05 08:00:00']);

    $a = AndamentiPiattaforma::ultimiDodiciMesi();

    expect($a->clientiCumulati->ultimo())->toBe(2)
        ->and($a->clientiCumulati->base)->toBe(1)
        ->and($a->clientiCumulati->variazione())->toBe(1)
        ->and($a->clientiCumulati->variazione())->toBe($a->nuoviClienti->totale());
});

// ─── I confini, che è dove i due driver non si somigliano ────────────────────

it('returns exactly twelve points ending on the current month', function () {
    $a = AndamentiPiattaforma::ultimiDodiciMesi();

    expect($a->clientiCumulati->valori)->toHaveCount(12)
        ->and($a->nuoviClienti->valori)->toHaveCount(12)
        ->and($a->clientiCumulati->mesi)->toHaveCount(12)
        ->and($a->clientiCumulati->etichette)->toHaveCount(12)
        // Dodici mesi, mese corrente **compreso** (e parziale).
        ->and($a->clientiCumulati->mesi[0])->toBe('2025-09')
        ->and($a->clientiCumulati->mesi[11])->toBe('2026-08')
        ->and($a->clientiCumulati->etichette[0])->toBe('Set')
        ->and($a->clientiCumulati->etichette[11])->toBe('Ago');
});

it('does not merge December 2025 into December 2026', function () {
    // ⛔ È il test che diventa rosso se qualcuno riscrive il raggruppamento con
    // `strftime('%m')` o `to_char(.., 'MM')`, o con `EXTRACT(MONTH …)`: il mese
    // senza l'anno fonde due dicembre a dodici mesi di distanza, e il risultato
    // è un picco che non è mai esistito.
    $vecchio = Account::factory()->saas()->create();
    DB::table('accounts')->where('id', $vecchio->id)
        ->update(['created_at' => '2025-12-10 09:00:00']);

    $nuovo = Account::factory()->saas()->create();
    DB::table('accounts')->where('id', $nuovo->id)
        ->update(['created_at' => '2026-12-10 09:00:00']);

    // Finestra Gen 2026 → Dic 2026: il dicembre 2025 è **prima** della finestra
    // (quindi nella base), il dicembre 2026 è l'ultimo bucket.
    $a = AndamentiPiattaforma::ultimiDodiciMesi(CarbonImmutable::create(2026, 12, 20, 0, 0, 0, 'Europe/Rome'));

    expect($a->clientiCumulati->mesi[11])->toBe('2026-12')
        ->and($a->nuoviClienti->valori[11])->toBe(1)   // e non 2
        ->and($a->clientiCumulati->ultimo())->toBe(3); // i due sopra + $this->cliente
});

it('puts 23:59:59 of the last day in the month that ends and 00:00:00 of the first in the one that begins', function () {
    // Il confine è **mezzo aperto**: `[inizio, fine)`. Un `<=` al posto di `<`
    // farebbe scivolare la mezzanotte del primo nel mese precedente — e su
    // SQLite il confronto è lessicografico sulla stringa mentre su Postgres è
    // una data, quindi lo stesso errore può essere verde in locale e rosso in CI.
    $ultimoIstanteDiLuglio = Account::factory()->saas()->create();
    DB::table('accounts')->where('id', $ultimoIstanteDiLuglio->id)
        ->update(['created_at' => '2026-07-31 23:59:59']);

    $primoIstanteDiAgosto = Account::factory()->saas()->create();
    DB::table('accounts')->where('id', $primoIstanteDiAgosto->id)
        ->update(['created_at' => '2026-08-01 00:00:00']);

    // `$this->cliente` nasce il 2026-08-15, quindi anche lui in agosto.
    $a = AndamentiPiattaforma::ultimiDodiciMesi();

    expect($a->nuoviClienti->mesi[10])->toBe('2026-07')
        ->and($a->nuoviClienti->valori[10])->toBe(1)
        ->and($a->nuoviClienti->mesi[11])->toBe('2026-08')
        ->and($a->nuoviClienti->valori[11])->toBe(2);
});

it('drops out of the window what happened before it, without losing it from the base', function () {
    // Un cliente entrato tredici mesi fa non ha un bucket, ma **c'è**: sta in
    // `c0`, quindi il primo punto della curva parte da 1 e non da 0. Senza la
    // base, il grafico racconterebbe una piattaforma nata dodici mesi fa.
    $anziano = Account::factory()->saas()->create();
    DB::table('accounts')->where('id', $anziano->id)
        ->update(['created_at' => '2024-01-05 08:00:00']);

    $a = AndamentiPiattaforma::ultimiDodiciMesi();

    expect($a->clientiCumulati->primo())->toBe(1)
        ->and($a->nuoviClienti->valori[0])->toBe(0)
        ->and($a->clientiCumulati->ultimo())->toBe(2);
});

// ─── Il costo, che è un vincolo di prodotto e non un dettaglio ───────────────

it('stays at three statements no matter how many customers there are', function () {
    // ⚠️ `Cabina::render()` gira a **ogni** update di Livewire, ricerca compresa:
    // una query per mese sarebbero 36 round-trip a ogni tasto. Si contano gli
    // STATEMENT e non le citazioni di una tabella, per la ragione già scritta in
    // `KpiPiattaformaTest`: le sottoquery **nominano** `accounts` e
    // `unita_organizzativa` dentro lo stesso SQL.
    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        AndamentiPiattaforma::ultimiDodiciMesi();
        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], '"accounts"')
                || str_contains($q['query'], '"unita_organizzativa"')
                || str_contains($q['query'], '"strumenti"'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    expect($conta())->toBe(3);

    collect(range(1, 11))->each(function () {
        $acc = Account::factory()->saas()->create();
        $ente = UnitaOrganizzativa::factory()->ente()->perAccount($acc)->create();
        Strumento::factory()->forNode($ente)->create();
    });

    expect($conta())->toBe(3);
});
