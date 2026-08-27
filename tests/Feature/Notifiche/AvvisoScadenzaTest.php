<?php

use App\Enums\TransizioneAvviso;
use App\Models\AvvisoScadenza;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->strumento = Strumento::factory()->forNode($ente)->create();
});

/**
 * La memoria anti-duplicati dello scheduler (🔗 ADR-011, S5).
 *
 * Qui si verifica il vincolo, non il comando: che il DB stesso rifiuti il
 * doppione, e che la `data_scadenza` nella chiave lasci passare il caso della
 * proroga. Se questa unique cade, il «niente duplicati» dello scheduler
 * dipenderebbe solo dalla query del comando — cioè da un posto solo.
 */
function avviso(Intervento $intervento, TransizioneAvviso $transizione, string $data): AvvisoScadenza
{
    return AvvisoScadenza::create([
        'tenant_id' => $intervento->tenant_id,
        'riferimento_type' => $intervento->getMorphClass(),
        'riferimento_id' => $intervento->id,
        'transizione' => $transizione,
        'data_scadenza' => $data,
    ]);
}

it('rejects a second avviso for the same scadenza and transizione', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => '2026-09-10']);

    avviso($intervento, TransizioneAvviso::Imminente, '2026-09-10');

    expect(fn () => avviso($intervento, TransizioneAvviso::Imminente, '2026-09-10'))
        ->toThrow(QueryException::class);
});

it('allows the second avviso when the scadenza actually expires', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => '2026-09-10']);

    avviso($intervento, TransizioneAvviso::Imminente, '2026-09-10');
    avviso($intervento, TransizioneAvviso::Scaduta, '2026-09-10');

    expect(AvvisoScadenza::where('riferimento_id', $intervento->id)->count())->toBe(2);
});

it('allows a new avviso after a proroga, because the date is part of the key', function () {
    // È il caso per cui `data_scadenza` sta nella unique: rimandato l'intervento,
    // la scadenza nuova è un'altra scadenza e merita il suo avviso.
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => '2026-09-10']);

    avviso($intervento, TransizioneAvviso::Imminente, '2026-09-10');
    avviso($intervento, TransizioneAvviso::Imminente, '2027-09-10');

    expect(AvvisoScadenza::where('riferimento_id', $intervento->id)->count())->toBe(2);
});

it('prunes avvisi older than 24 months and keeps the recent ones', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => '2026-09-10']);

    $vecchio = avviso($intervento, TransizioneAvviso::Imminente, '2024-01-10');
    $vecchio->forceFill(['created_at' => now()->subMonths(25)])->saveQuietly();
    $recente = avviso($intervento, TransizioneAvviso::Scaduta, '2024-01-10');

    $this->artisan('model:prune', ['--model' => [AvvisoScadenza::class]])->assertSuccessful();

    expect(AvvisoScadenza::find($vecchio->id))->toBeNull()
        ->and(AvvisoScadenza::find($recente->id))->not->toBeNull();
});

// --- La transizione `obsoleta` (🔗 ADR-014, 27 Ago 2026) ---
//
// Il vincolo, non il comando: che lo schema esistente ospiti davvero il morph
// verso `Strumento` e il valore nuovo dell'enum — senza migration, perché
// `transizione` è una `string` e `riferimento` un `morphs()` — e che la unique a
// quattro colonne sia la **seconda rete** sotto la query del servizio. Se
// cadesse, il «niente duplicati» dell'obsolescenza dipenderebbe da un posto solo.

it('accepts an obsolescence avviso pointing at a Strumento', function () {
    $riga = AvvisoScadenza::create([
        'tenant_id' => $this->strumento->tenant_id,
        'riferimento_type' => $this->strumento->getMorphClass(),
        'riferimento_id' => $this->strumento->id,
        'transizione' => TransizioneAvviso::Obsoleta,
        // La data è la `data_installazione` NUDA: è ciò che rende la chiave
        // stabile sotto i cambi di soglia (vedi `AvvisiObsolescenza`).
        'data_scadenza' => '2012-04-01',
    ]);

    expect($riga->fresh()->transizione)->toBe(TransizioneAvviso::Obsoleta)
        ->and($riga->fresh()->riferimento)->toBeInstanceOf(Strumento::class)
        ->and($riga->fresh()->riferimento->id)->toBe($this->strumento->id);
});

it('rejects a second obsolescence avviso for the same machine and installation date', function () {
    $riga = fn () => AvvisoScadenza::create([
        'tenant_id' => $this->strumento->tenant_id,
        'riferimento_type' => $this->strumento->getMorphClass(),
        'riferimento_id' => $this->strumento->id,
        'transizione' => TransizioneAvviso::Obsoleta,
        'data_scadenza' => '2012-04-01',
    ]);

    $riga();

    expect($riga)->toThrow(QueryException::class);
});
