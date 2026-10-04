<?php

use App\Models\Account;
use App\Notifications\PropostaPiano;
use App\Support\AuditLog;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BancoAbbonamento;

/**
 * `accounts.piano_proposto`: il piano a pagamento che la cabina ha proposto e
 * che il cliente non ha ancora pagato (🔗 ADR-045, ADR-032).
 *
 * 🔴 La colonna esiste per **non** scrivere `accounts.piano`: un account marcato
 * pagante senza subscription è un cliente che risulta pagante e non paga. Qui
 * si prova che la proposta non tocca il piano, che ogni cambio di piano la
 * chiude, e che resta una traccia di entrambi i gesti.
 */
beforeEach(function () {
    BancoAbbonamento::piano('saas', 4900);
    BancoAbbonamento::piano('pro', 9900);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Laboratorio Aurora']);
});

function righeAuditDellaProposta(Account $account): int
{
    return Activity::inLog(AuditLog::NAME)
        ->where('subject_type', $account->getMorphClass())
        ->where('subject_id', $account->id)
        ->where('description', 'Modifica account')
        ->count();
}

it('records the proposal without touching the plan the customer is on', function () {
    $this->account->proponiPiano('pro');

    $account = $this->account->fresh();

    expect($account->piano_proposto)->toBe('pro')
        ->and($account->piano)->toBe('free');
});

it('refuses to propose a free plan, which is assigned and not proposed', function () {
    expect(fn () => $this->account->proponiPiano('free'))
        ->toThrow(InvalidArgumentException::class);

    expect($this->account->fresh()->piano_proposto)->toBeNull();
});

it('refuses to propose a plan that is not in the catalogue', function () {
    expect(fn () => $this->account->proponiPiano('platino'))
        ->toThrow(InvalidArgumentException::class);

    expect($this->account->fresh()->piano_proposto)->toBeNull();
});

it('closes the proposal when the plan changes, whichever plan the customer bought', function (string $comprato) {
    // Il gesto che il webhook compie quando Stripe conferma l'incasso. Vale
    // anche se il cliente ha scelto un piano diverso da quello proposto: ha
    // comprato qualcosa, e «in attesa di pagamento» non è più vero.
    $this->account->proponiPiano('saas');

    $this->account->cambiaPiano($comprato);

    $account = $this->account->fresh();

    expect($account->piano)->toBe($comprato)
        ->and($account->piano_proposto)->toBeNull();
})->with(['saas', 'pro']);

it('withdraws the proposal when asked to', function () {
    $this->account->proponiPiano('saas');
    $this->account->proponiPiano(null);

    expect($this->account->fresh()->piano_proposto)->toBeNull();
});

it('leaves one audit row per gesture, and none when nothing changes', function () {
    $prima = righeAuditDellaProposta($this->account);

    $this->account->proponiPiano('saas');
    // Ripetere la stessa proposta è un no-op: nessuna seconda riga.
    $this->account->proponiPiano('saas');

    expect(righeAuditDellaProposta($this->account))->toBe($prima + 1);

    $riga = Activity::inLog(AuditLog::NAME)
        ->where('subject_id', $this->account->id)
        ->where('description', 'Modifica account')
        ->latest('id')->first();

    expect($riga->attribute_changes['attributes']['piano_proposto'])->toBe('saas');
});

it('leaves a trace in the audit log when the proposal mail is not delivered', function () {
    // Come l'invito: con la coda attiva il fallimento non torna a nessuno, e
    // `failed_jobs` è una tabella che non guarda nessuno.
    (new PropostaPiano('Laboratorio Aurora', 'SaaS', 4900, 'marta@aurora.test'))
        ->failed(new RuntimeException('SMTP giù'));

    $riga = Activity::inLog(AuditLog::NAME)->where('description', 'Proposta di piano NON consegnata')->sole();

    expect($riga->properties['destinatario'])->toBe('marta@aurora.test')
        ->and($riga->properties['piano'])->toBe('SaaS');
});
