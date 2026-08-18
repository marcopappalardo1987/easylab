<?php

use App\Models\Garanzia;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Semaforo;

/**
 * La forma "a data" della garanzia (ADR-022, che estende ADR-019).
 *
 * L'operatore che monta un pezzo conosce la **scadenza**, non i mesi:
 * convertirla in interi la sposterebbe di giorni su un dato contrattuale che
 * accende l'arancione a 30. Il dominio la rappresenta quindi con una colonna di
 * INPUT propria — `data_scadenza_dichiarata` — mentre
 * `data_scadenza_effettiva` resta derivata al 100%, come prima.
 */
beforeEach(function () {
    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($dept)->create();

    $this->utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)
        ->forRicambio(Ricambio::factory()->forTenant($this->ente)->create())
        ->create();

    $this->perRicambio = fn (array $extra = []): Garanzia => Garanzia::factory()
        ->forRicambio($this->utilizzo)
        ->create($extra);
});

it('stores the declared date verbatim, without converting it to months', function () {
    // Il punto dell'intera estensione: la data salvata è quella digitata.
    $garanzia = Garanzia::factory()->forRicambio($this->utilizzo)
        ->scadenzaDichiarata('2028-02-03')
        ->create(['data_inizio' => '2026-08-09']);

    expect($garanzia->data_scadenza_effettiva->toDateString())->toBe('2028-02-03')
        ->and($garanzia->durata_mesi)->toBeNull();
});

it('rejects a garanzia carrying both a durata and a declared scadenza', function () {
    // Due verità sulla stessa scadenza. Il payload non può costruirlo (la
    // colonna è fuori da $fillable), ma un chiamante che assegna gli attributi
    // a mano sì — ed è il caso che l'XOR esiste per fermare.
    $garanzia = Garanzia::factory()->forRicambio($this->utilizzo)->make(['durata_mesi' => 12]);
    $garanzia->data_scadenza_dichiarata = '2030-01-01';

    expect(fn () => $garanzia->save())->toThrow(InvalidArgumentException::class);
});

it('rejects a declared scadenza that is not after data_inizio', function () {
    // Stessa soglia del ramo durata (`>= 1` mese), espressa in date: una
    // garanzia che finisce il giorno in cui inizia non è una garanzia.
    foreach (['2026-08-09', '2026-08-08'] as $scadenza) {
        $garanzia = Garanzia::factory()->forRicambio($this->utilizzo)
            ->scadenzaDichiarata($scadenza)
            ->make(['data_inizio' => '2026-08-09']);

        expect(fn () => $garanzia->save())->toThrow(InvalidArgumentException::class);
    }
});

it('ignores a directly assigned data_scadenza_effettiva, in both branches', function () {
    // È il test che dimostra che il ramo nuovo NON ha aperto una falla: il
    // campo che pilota il semaforo resta riscritto a ogni salvataggio, qualunque
    // sia la forma di input. Senza il secondo caso, il ramo di ADR-022 non
    // sarebbe coperto proprio dove conta.
    $conDurata = Garanzia::factory()->forStrumento($this->strumento)
        ->make(['data_inizio' => '2026-01-01', 'durata_mesi' => 12]);
    $conDurata->data_scadenza_effettiva = '2099-01-01';
    $conDurata->save();

    $conData = Garanzia::factory()->forRicambio($this->utilizzo)
        ->scadenzaDichiarata('2027-06-30')
        ->make(['data_inizio' => '2026-01-01']);
    $conData->data_scadenza_effettiva = '2099-01-01';
    $conData->save();

    expect($conDurata->fresh()->data_scadenza_effettiva->toDateString())->toBe('2027-01-01')
        ->and($conData->fresh()->data_scadenza_effettiva->toDateString())->toBe('2027-06-30');
});

it('recomputes the effective date when the declared one changes', function () {
    $garanzia = ($this->perRicambio)();
    $garanzia->fissaScadenzaDichiarata('2029-03-15')->save();

    expect($garanzia->fresh()->data_scadenza_effettiva->toDateString())->toBe('2029-03-15');
});

it('switches cleanly from one form to the other', function () {
    // I due setter azzerano sempre l'altro lato: è il motivo per cui l'XOR non
    // è violabile passando dai metodi di dominio.
    $garanzia = Garanzia::factory()->forRicambio($this->utilizzo)
        ->scadenzaDichiarata('2030-01-01')
        ->create(['data_inizio' => '2026-01-01']);

    $garanzia->fissaDurata(24)->save();

    expect($garanzia->fresh()->data_scadenza_dichiarata)->toBeNull()
        ->and($garanzia->fresh()->data_scadenza_effettiva->toDateString())->toBe('2028-01-01');
});

it('feeds the semaforo identically, whichever form produced the date', function () {
    // `entroSoglia` e `isScaduta` leggono l'effettiva: per loro le due forme
    // non esistono. Congelato, perché è la ragione per cui il semaforo non è
    // stato toccato da questa estensione.
    $imminente = Garanzia::factory()->forRicambio($this->utilizzo)
        ->scadenzaDichiarata(today()->addDays(Semaforo::giorniImminente())->toDateString())
        ->create(['data_inizio' => today()->subYear()->toDateString()]);

    $scaduta = Garanzia::factory()
        ->forRicambio(RicambioUtilizzo::factory()
            ->forStrumento($this->strumento)
            ->forRicambio(Ricambio::factory()->forTenant($this->ente)->create())
            ->create())
        ->scadenzaDichiarata(today()->subDay()->toDateString())
        ->create(['data_inizio' => today()->subYear()->toDateString()]);

    expect(Garanzia::query()->entroSoglia()->pluck('id')->all())
        ->toEqualCanonicalizing([$imminente->id, $scaduta->id])
        ->and($scaduta->isScaduta())->toBeTrue()
        ->and($imminente->isScaduta())->toBeFalse();
});
