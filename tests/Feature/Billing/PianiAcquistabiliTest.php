<?php

use App\Models\Account;
use App\Models\Piano;
use App\Support\Billing\OffertaPiani;
use App\Support\Billing\PianiAcquistabili;
use App\Support\Piani;
use Tests\Support\BancoAbbonamento;

/**
 * 🔴 La regola di prodotto dell'attivazione e del cambio di piano (🔗 ADR-045):
 * **mai un piano gratuito, mai uno che costa meno**.
 *
 * Questo file prova la regola da sola, senza HTTP: la pagina e il controller
 * leggono lo stesso oggetto (`OffertaPiani`), quindi ciò che qui non entra
 * nell'offerta non si vede e non si compra. I test del POST stanno in
 * `SceltaPianoAbbonamentoTest`.
 *
 * Il listino di prova ha tre gradini — SaaS 49 €, Pro 99 €, Enterprise 199 € —
 * più il Free che la suite ha già.
 */
beforeEach(function () {
    BancoAbbonamento::piano('saas', 4900);
    BancoAbbonamento::piano('pro', 9900);
    BancoAbbonamento::piano('enterprise', 19900);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Laboratorio Aurora']);
});

/** I codici offerti all'account, riletto dal database come farebbe una richiesta. */
function codiciOfferti(Account $account): array
{
    BancoAbbonamento::dimentica();

    return array_map(fn (Piano $p) => $p->codice, PianiAcquistabili::per($account->fresh())->piani);
}

// --- Chi non ha mai pagato ---

it('offers a free account every paid plan on sale, in the order of the price list', function () {
    $offerta = PianiAcquistabili::per($this->account);

    expect(codiciOfferti($this->account))->toBe(['saas', 'pro', 'enterprise'])
        ->and($offerta->modo)->toBe(OffertaPiani::ATTIVAZIONE)
        ->and($offerta->impedimento)->toBeNull()
        ->and($offerta->abbonamento)->toBeNull();
});

it('never offers a free plan, not even to an account that is on one', function () {
    // Un secondo piano gratuito accanto al Free, con un price da 29 € su
    // Stripe scritto apposta: la gratuità è una DICHIARAZIONE del listino, e
    // deve bastare lei a tenerlo fuori — non l'assenza del prezzo, né lo zero.
    BancoAbbonamento::piano('omaggio', 2900, ['gratuito' => true]);

    expect(codiciOfferti($this->account))->not->toContain('free')
        ->and(codiciOfferti($this->account))->not->toContain('omaggio');
});

it('never offers a plan that is archived, has no Stripe price, or costs nothing', function () {
    BancoAbbonamento::piano('ritirato', 14900, ['attivo' => false]);
    BancoAbbonamento::pianoSenzaPrice('bozza', 12900);
    // A pagamento per dichiarazione, ma a 0 €: il suo checkout si chiuderebbe
    // «senza pagamento», cioè non è un acquisto.
    BancoAbbonamento::piano('zero', 0);

    $offerti = codiciOfferti($this->account);

    expect($offerti)->not->toContain('ritirato')
        ->and($offerti)->not->toContain('bozza')
        ->and($offerti)->not->toContain('zero')
        ->and($offerti)->toBe(['saas', 'pro', 'enterprise']);
});

// --- Chi sta pagando: solo avanti ---

it('offers a paying customer only the plans that cost strictly more', function () {
    BancoAbbonamento::abbona($this->account, 'pro');

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect(codiciOfferti($this->account))->toBe(['enterprise'])
        ->and($offerta->modo)->toBe(OffertaPiani::CAMBIO)
        ->and($offerta->abbonamento?->stripe_price)->toBe('price_pro');
});

it('never offers a paying customer a free plan, a cheaper plan, or the plan it already has', function () {
    BancoAbbonamento::abbona($this->account, 'pro');

    $offerta = PianiAcquistabili::per($this->account->fresh());

    // 🔴 Le due richieste di prodotto, una per riga: il gratuito e il più
    // economico. Più il proprio piano, che non è un cambio.
    expect($offerta->accetta('free'))->toBeFalse()
        ->and($offerta->accetta('saas'))->toBeFalse()
        ->and($offerta->accetta('pro'))->toBeFalse()
        ->and($offerta->accetta('enterprise'))->toBeTrue();
});

it('offers nothing to the customer who is already on the most expensive plan', function () {
    BancoAbbonamento::abbona($this->account, 'enterprise');

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect($offerta->piani)->toBe([])
        // Non è un impedimento: non c'è niente di rotto, solo niente sopra.
        ->and($offerta->impedimento)->toBeNull();
});

it('never offers a plan at the same price as the one the customer has', function () {
    // Stesso importo ≠ passo avanti: il confronto sul cambio è STRETTO.
    BancoAbbonamento::piano('gemello', 9900);
    BancoAbbonamento::abbona($this->account, 'pro');

    expect(codiciOfferti($this->account))->toBe(['enterprise']);
});

// --- La soglia: il più alto di tre importi ---

it('counts what the customer really pays, when an old price was higher than the price list', function () {
    // Il cliente è su SaaS a 120 € (un prezzo storico), il listino di SaaS è
    // sceso a 49 €. Pro a 99 € costa PIÙ del listino di SaaS ma MENO di ciò che
    // lui paga: passarci sarebbe scendere.
    BancoAbbonamento::prezzo('saas', 'price_saas_storico', 12000, corrente: false);
    BancoAbbonamento::abbona($this->account, 'saas', price: 'price_saas_storico');

    expect(codiciOfferti($this->account))->toBe(['enterprise']);
});

it('counts what the plan costs today, when the customer stayed on a cheaper old price', function () {
    // Il cliente è su Pro a 30 € (prezzo storico), Pro oggi costa 99 €. SaaS a
    // 49 € costa più di ciò che lui paga, ma è un piano più piccolo del suo.
    BancoAbbonamento::prezzo('pro', 'price_pro_storico', 3000, corrente: false);
    BancoAbbonamento::abbona($this->account, 'pro', price: 'price_pro_storico');

    expect(codiciOfferti($this->account))->toBe(['enterprise']);
});

it('recognises the plan of a historic price, so the customer is not sold its own plan again', function () {
    BancoAbbonamento::prezzo('pro', 'price_pro_storico', 3000, corrente: false);
    BancoAbbonamento::abbona($this->account, 'pro', price: 'price_pro_storico');

    expect(PianiAcquistabili::per($this->account->fresh())->accetta('pro'))->toBeFalse();
});

// --- Chi ha disdetto e torna ---

it('lets a cancelled customer come back to the same plan or a bigger one, never a smaller one', function () {
    // 🔴 Il giro che la regola deve chiudere: alla disdetta il piano decade al
    // gratuito, e guardando il solo piano attuale chi disdice e riattiva
    // sceglierebbe il più economico — scenderebbe, con un passaggio in più.
    BancoAbbonamento::abbona($this->account, 'pro', 'canceled');
    $this->account->forceFill(['piano' => 'free'])->save();

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect(codiciOfferti($this->account))->toBe(['pro', 'enterprise'])
        ->and($offerta->modo)->toBe(OffertaPiani::ATTIVAZIONE)
        ->and($offerta->abbonamento)->toBeNull()
        ->and($offerta->accetta('saas'))->toBeFalse();
});

it('never lets a cancelled customer who paid an old cheap price come back on a smaller plan', function () {
    // Pagava Pro a 30 € (prezzo storico), poi ha disdetto. SaaS a 49 € costa
    // più di ciò che pagava — ma è un piano più piccolo di Pro, che oggi costa
    // 99 €. È il terzo importo della soglia, e senza di lui SaaS passerebbe.
    BancoAbbonamento::prezzo('pro', 'price_pro_storico', 3000, corrente: false);
    BancoAbbonamento::abbona($this->account, 'pro', 'canceled', 'price_pro_storico');
    $this->account->forceFill(['piano' => 'free'])->save();

    expect(codiciOfferti($this->account))->toBe(['pro', 'enterprise']);
});

it('looks at the most recent subscription, not at an older and cheaper one', function () {
    // Storia: SaaS chiuso, poi Pro chiuso. La soglia è Pro.
    BancoAbbonamento::abbona($this->account, 'saas', 'canceled');
    BancoAbbonamento::abbonamento($this->account, 'price_pro', 'canceled');
    $this->account->forceFill(['piano' => 'free'])->save();

    expect(codiciOfferti($this->account))->toBe(['pro', 'enterprise']);
});

it('ignores a subscription that expired before its first payment', function () {
    // Un tentativo fallito (carta che chiede il 3-D Secure da `easylab:abbona`,
    // mai confermata): non è un abbonamento in corso, e non è un piano che il
    // cliente ha pagato — quindi non blocca e non alza la soglia.
    $this->account->forceFill(['stripe_id' => 'cus_aurora'])->save();
    BancoAbbonamento::abbonamento($this->account, 'price_enterprise', 'incomplete_expired');

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect(codiciOfferti($this->account))->toBe(['saas', 'pro', 'enterprise'])
        ->and($offerta->modo)->toBe(OffertaPiani::ATTIVAZIONE)
        ->and($offerta->impedimento)->toBeNull();
});

it('lets an account marked on a paid plan without a subscription start paying for it', function () {
    // Stato anomalo ma esistente (un piano assegnato a mano): la via d'uscita è
    // pagare quel piano o uno più grande, mai uno più piccolo.
    $this->account->forceFill(['piano' => 'pro'])->save();

    expect(codiciOfferti($this->account))->toBe(['pro', 'enterprise']);
});

// --- Quando non si offre niente, e perché ---

it('offers nothing when the subscription is on a price the price list does not know', function () {
    // Un accordo su misura, creato a mano in dashboard: quanto paga non si sa
    // senza chiamare Stripe, e indovinare potrebbe offrire un passo indietro.
    BancoAbbonamento::abbona($this->account, 'pro', price: 'price_creato_a_mano');

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect($offerta->piani)->toBe([])
        ->and($offerta->impedimento)->toBe(OffertaPiani::NON_DISPONIBILE);
});

it('offers nothing when the account plan has left the catalogue', function () {
    $this->account->forceFill(['piano' => 'dismesso'])->save();

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect($offerta->piani)->toBe([])
        ->and($offerta->impedimento)->toBe(OffertaPiani::NON_DISPONIBILE);
});

it('offers nothing while Stripe is still chasing a payment', function (string $stato) {
    BancoAbbonamento::abbona($this->account, 'saas', $stato);

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect($offerta->piani)->toBe([])
        ->and($offerta->impedimento)->toBe(OffertaPiani::PAGAMENTO_IN_SOSPESO)
        // 🔴 Resta un CAMBIO: l'abbonamento c'è, e aprire un checkout ne
        // creerebbe un secondo fatturato accanto.
        ->and($offerta->modo)->toBe(OffertaPiani::CAMBIO);
})->with(['past_due', 'unpaid', 'incomplete', 'paused']);

it('tells the customer in arrears to settle, even on a price the list does not know', function () {
    // I due impedimenti si sommano: insoluto, e un price creato a mano. Va
    // detto quello che il cliente può rimuovere da solo — saldare — e non
    // «contatta EasyLab».
    BancoAbbonamento::abbona($this->account, 'saas', 'past_due', 'price_creato_a_mano');

    expect(PianiAcquistabili::per($this->account->fresh())->impedimento)
        ->toBe(OffertaPiani::PAGAMENTO_IN_SOSPESO);
});

it('offers nothing while a cancellation is scheduled', function () {
    // Cashier, cambiando prezzo, annullerebbe la disdetta: un clic che parla
    // d'altro non deve rimettere in piedi un abbonamento che il cliente ha
    // chiuso.
    $this->account->forceFill(['piano' => 'saas', 'stripe_id' => 'cus_aurora'])->save();
    BancoAbbonamento::abbonamento($this->account, 'price_saas', 'active', now()->addDays(10)->toDateTimeString());

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect($offerta->piani)->toBe([])
        ->and($offerta->impedimento)->toBe(OffertaPiani::DISDETTA_PROGRAMMATA);
});

it('offers nothing to an account suspended by hand, and never says why', function () {
    // Il blocco manuale non si riapre pagando (ADR-013): vendere qui sarebbe
    // incassare da chi resta chiuso fuori.
    $this->account->blocca('Contenzioso: pratica 4471, non riaprire.');

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect($offerta->piani)->toBe([])
        ->and($offerta->impedimento)->toBe(OffertaPiani::NON_DISPONIBILE)
        ->and($offerta->messaggioImpedimento())->not->toContain('Contenzioso')
        ->and($offerta->messaggioImpedimento())->not->toContain('4471');
});

it('still sells to an account that Stripe closed after a cancellation', function () {
    // L'altra sorgente del lockout: questa SI riapre pagando, ed è la ragione
    // per cui la rotta sta fuori da `account.lockout`.
    BancoAbbonamento::abbona($this->account, 'saas', 'canceled');
    $this->account->forceFill(['piano' => 'free'])->save();
    $this->account->bloccaPerStripe('Stripe: abbonamento cancellato.');

    expect(codiciOfferti($this->account))->toBe(['saas', 'pro', 'enterprise']);
});

it('offers nothing to the platform account', function () {
    $this->account->forceFill(['di_piattaforma' => true])->save();

    $offerta = PianiAcquistabili::per($this->account->fresh());

    expect($offerta->piani)->toBe([])
        ->and($offerta->impedimento)->toBeNull();
});

// --- La domanda che il POST rifà ---

it('accepts only a code that is in the offer, whatever shape the input has', function () {
    $offerta = PianiAcquistabili::per($this->account);

    expect($offerta->accetta('pro'))->toBeTrue()
        ->and($offerta->accetta('free'))->toBeFalse()
        ->and($offerta->accetta('inesistente'))->toBeFalse()
        ->and($offerta->accetta(null))->toBeFalse()
        ->and($offerta->accetta(['pro']))->toBeFalse()
        ->and($offerta->accetta(''))->toBeFalse();
});

// --- Il listino: le tre letture nuove ---

it('lists as sellable only the active paid plans that have a Stripe price', function () {
    BancoAbbonamento::piano('ritirato', 14900, ['attivo' => false]);
    BancoAbbonamento::pianoSenzaPrice('bozza', 12900);
    BancoAbbonamento::dimentica();

    expect(Piani::vendibili())->toBe(['saas', 'pro', 'enterprise']);
});

it('reads the amount of the current price, not the price list', function () {
    // Il listino è già salito a 59 €, il Price su Stripe è ancora quello da
    // 49 €: il checkout addebiterà 49, ed è la cifra che va mostrata.
    Piano::query()->where('codice', 'saas')->update(['prezzo_mensile_cent' => 5900]);
    BancoAbbonamento::dimentica();

    expect(Piani::importoCorrenteCent('saas'))->toBe(4900)
        ->and(Piani::prezzoMensileCent('saas'))->toBe(5900)
        ->and(Piani::importoCorrenteCent('free'))->toBeNull();
});

it('reads the amount of a historic price too, and answers null for an unknown one', function () {
    BancoAbbonamento::prezzo('saas', 'price_saas_storico', 3900, corrente: false);

    expect(Piani::importoDelPrice('price_saas_storico'))->toBe(3900)
        ->and(Piani::importoDelPrice('price_saas'))->toBe(4900)
        ->and(Piani::importoDelPrice('price_creato_a_mano'))->toBeNull()
        ->and(Piani::importoDelPrice(null))->toBeNull()
        ->and(Piani::importoDelPrice(''))->toBeNull();
});
