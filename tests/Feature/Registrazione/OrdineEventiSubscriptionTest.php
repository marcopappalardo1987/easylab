<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use Illuminate\Testing\TestResponse;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 Stripe non garantisce l'ordine delle consegne, e ritenta per giorni un
 * evento fallito (🔗 ADR-013 il lockout per insoluto; caccia T3, A5).
 *
 * Un `customer.subscription.updated` «active» di ieri, arrivato col retry dopo
 * l'«unpaid» di oggi, riapriva un account insoluto. Il controller confronta il
 * `created` dell'evento con quello dell'ultimo applicato alla subscription.
 */
const SEGRETO_ORDINE = 'whsec_test_ordine';

beforeEach(function () {
    config(['cashier.webhook.secret' => SEGRETO_ORDINE]);

    BancoRegistrazione::listinoVendibile();

    $this->account = Account::factory()->conStripe('cus_ordine')->saas()->create(['ragione_sociale' => 'Gruppo Ordine']);
    UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede']);
});

function eventoSubscription(string $id, string $stato, int $creato, string $tipo = 'customer.subscription.updated'): array
{
    return [
        'id' => $id,
        'type' => $tipo,
        'created' => $creato,
        'data' => ['object' => [
            'id' => 'sub_ordine',
            'customer' => 'cus_ordine',
            'status' => $stato,
            'cancel_at_period_end' => false,
            'items' => ['data' => [[
                'id' => 'si_ordine', 'quantity' => 1,
                'price' => ['id' => BancoRegistrazione::PRICE_SAAS, 'product' => 'prod_x'],
            ]]],
        ]],
    ];
}

function consegnaOrdine(array $payload): TestResponse
{
    $corpo = json_encode($payload);
    $t = time();
    $firma = hash_hmac('sha256', "{$t}.{$corpo}", SEGRETO_ORDINE);

    return test()->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$firma}",
    ], $corpo);
}

it('never reopens an unpaid account because Stripe retried an older «active» event', function () {
    consegnaOrdine(eventoSubscription('evt_nuovo', 'unpaid', 1_700_000_100))->assertOk();
    expect($this->account->fresh()->is_locked)->toBeTrue();

    // Il vecchio «active», fallito alla consegna, arriva col retry.
    consegnaOrdine(eventoSubscription('evt_vecchio', 'active', 1_700_000_000))->assertOk();

    expect($this->account->fresh()->is_locked)->toBeTrue()
        // Nemmeno lo specchio locale torna indietro.
        ->and($this->account->subscriptions()->sole()->stripe_status)->toBe('unpaid');
});

it('reopens the account when a newer «active» event arrives', function () {
    consegnaOrdine(eventoSubscription('evt_insoluto', 'unpaid', 1_700_000_100))->assertOk();
    consegnaOrdine(eventoSubscription('evt_vecchio', 'active', 1_700_000_000))->assertOk();
    consegnaOrdine(eventoSubscription('evt_pagato', 'active', 1_700_000_200))->assertOk();

    expect($this->account->fresh()->is_locked)->toBeFalse()
        ->and($this->account->subscriptions()->sole()->creazione_ultimo_evento_stripe)->toBe(1_700_000_200);
});

it('never locks a paying account because Stripe retried an older «unpaid» event', function () {
    consegnaOrdine(eventoSubscription('evt_pagato', 'active', 1_700_000_200))->assertOk();
    consegnaOrdine(eventoSubscription('evt_insoluto', 'unpaid', 1_700_000_100))->assertOk();

    expect($this->account->fresh()->is_locked)->toBeFalse();
});

it('never lets an older event contradict a cancellation', function () {
    consegnaOrdine(eventoSubscription('evt_creata', 'active', 1_700_000_000, 'customer.subscription.created'))->assertOk();
    consegnaOrdine(eventoSubscription('evt_chiusa', 'canceled', 1_700_000_300, 'customer.subscription.deleted'))->assertOk();
    consegnaOrdine(eventoSubscription('evt_vecchio', 'active', 1_700_000_100))->assertOk();

    expect($this->account->fresh()->is_locked)->toBeTrue();
});

it('still applies an event that carries no creation time, as before', function () {
    $senzaData = eventoSubscription('evt_senza', 'unpaid', 0);
    unset($senzaData['created']);

    consegnaOrdine($senzaData)->assertOk();

    expect($this->account->fresh()->is_locked)->toBeTrue();
});
