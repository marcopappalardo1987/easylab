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
