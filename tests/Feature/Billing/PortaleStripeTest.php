<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Billing\PortaleStripe;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * La pagina «Abbonamento» e il bottone che apre il Billing Portal ospitato di
 * Stripe (🔗 ADR-032 — l'intestatario è l'Account; ADR-013 — vi si arriva anche
 * da bloccati; ADR-002 — il piano Free non ha customer Stripe).
 *
 * ⚠️ **Il percorso felice verso Stripe non è provato qui, ed è dichiarato.**
 * Come per `easylab:abbona`, mockare `StripeClient` produrrebbe un test che
 * verifica il proprio mock. Si introduce invece una cucitura minima —
 * `App\Support\Billing\PortaleStripe` — e la suite prova tutto ciò che sta
 * **prima** della rete: autorizzazione, guardie, `returnUrl`, redirect. La
 * verifica vera è su staging con le chiavi test.
 *
 * 🔴 **Le due rotte vivono nel gruppo `auth` NUDO**, quindi non esiste nessun
 * `can:` a proteggerle: la guardia è `Gate::authorize('manage', $account)`
 * scritta dentro il componente e — riscritta, non ereditata — dentro il
 * controller. I negativi di questo file sono la sola rete su quella scelta, e
 * quello sul POST conta doppio: un POST arriva senza passare dalla pagina che
 * offre il bottone.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Le chiavi Stripe sono azzerate in phpunit.xml: si dà per configurato
    // l'ambiente, e il test che vuole il contrario se lo azzera da sé.
    config(['cashier.secret' => 'sk_test_finta']);

    $this->account = Account::factory()->saas()->conStripe()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Centrale']);

    $this->membro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->membro->assignRole('Admin');
    $this->account->aggiungiMembro($this->membro);
});

/**
 * Il finto della cucitura: registra i `returnUrl` ricevuti e non parla con
 * nessuno. `invocazioni` vuoto è l'asserzione che regge le guardie «prima della
 * rete» — senza, un test potrebbe passare per il motivo sbagliato.
 */
function portaleFinto(): object
{
    $fake = new class extends PortaleStripe
    {
        /** @var list<string> */
        public array $invocazioni = [];

        public function url(Account $account, string $returnUrl): string
        {
            $this->invocazioni[] = $returnUrl;

            return 'https://billing.stripe.test/sessione-finta';
        }
    };

    app()->instance(PortaleStripe::class, $fake);

    return $fake;
}

it('shows the page to a member, with the plan and the form towards the portal', function () {
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('Abbonamento')
        ->assertSee('Gruppo Rossi')
        ->assertSee('SaaS')
        ->assertSee(route('abbonamento.portale'));
});

it('never shows a price on a page addressed to the paying customer', function () {
    // Il listino non è l'incassato, e nulla nel repository si accorgerebbe se
    // divergesse dal Price su Stripe: stamparlo qui significherebbe affermare
    // quanto paga il cliente senza saperlo. Le cifre vere stanno nel portale.
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee('49,00')
        ->assertDontSee('4900');
});

it('never lets a user of the same Ente who is not a member open the page', function () {
    // 🔴 Il negativo che regge tutto: è la Policy a restringere, non il
    // permesso — che con `teams = false` è globale.
    $responsabile = User::factory()->create(['tenant_id' => $this->ente->id]);
    $responsabile->assignRole('Responsabile Reparto');

    $this->actingAs($responsabile)
        ->get(route('abbonamento.index'))
        ->assertForbidden();
});

it('never lets an Admin who is not a member of the account open the page', function () {
    // L'Admin il permesso ce l'ha (è globale): ciò che gli impedisce di aprire
    // il contratto di un altro cliente è solo l'appartenenza ad `account_user`.
    $adminEstraneo = User::factory()->create(['tenant_id' => $this->ente->id]);
    $adminEstraneo->assignRole('Admin');

    expect($adminEstraneo->can('billing.manage_own'))->toBeTrue();

    $this->actingAs($adminEstraneo)
        ->get(route('abbonamento.index'))
        ->assertForbidden();
});

it('never lets an Admin who is not a member open the portal with a POST', function () {
    // 🔴 Il gemello del test qui sopra, e NON è ridondante: un POST arriva
    // senza passare dalla pagina che offre il bottone. Il controller riscrive
    // la propria guardia, e questa è la sola rete su quella riscrittura.
    $fake = portaleFinto();

    $adminEstraneo = User::factory()->create(['tenant_id' => $this->ente->id]);
    $adminEstraneo->assignRole('Admin');

    $this->actingAs($adminEstraneo)
        ->post(route('abbonamento.portale'))
        ->assertForbidden();

    expect($fake->invocazioni)->toBeEmpty();
});

it('sends a guest to the login instead of the page', function () {
    $this->get(route('abbonamento.index'))->assertRedirect(route('login'));
});

it('refuses a POST from a guest', function () {
    $fake = portaleFinto();

    $this->post(route('abbonamento.portale'))->assertRedirect(route('login'));

    expect($fake->invocazioni)->toBeEmpty();
});

it('gives a 404 to a user without an Ente', function () {
    // Il tecnico esterno (ADR-030) non ha `tenant_id`: non c'è nessun contratto
    // da amministrare, e un 403 dichiarerebbe l'esistenza di qualcosa.
    $esterno = User::factory()->create(['tenant_id' => null]);
    $esterno->assignRole('Admin');

    $this->actingAs($esterno)
        ->get(route('abbonamento.index'))
        ->assertNotFound();
});

it('gives a 404 when the Ente has no account at all', function () {
    $orfano = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Sede Orfana']);
    $utente = User::factory()->create(['tenant_id' => $orfano->id]);
    $utente->assignRole('Admin');

    $this->actingAs($utente)
        ->get(route('abbonamento.index'))
        ->assertNotFound();
});

it('gives a 404 on the POST too when the Ente has no account', function () {
    $fake = portaleFinto();

    $orfano = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Sede Orfana']);
    $utente = User::factory()->create(['tenant_id' => $orfano->id]);
    $utente->assignRole('Admin');

    $this->actingAs($utente)
        ->post(route('abbonamento.portale'))
        ->assertNotFound();

    expect($fake->invocazioni)->toBeEmpty();
});

it('redirects to the Stripe session and always passes the return url explicitly', function () {
    // ⛔ Il cuore: il default di Cashier è `route('home')`, rotta che questa
    // applicazione NON ha (`config/fortify.php` dichiara un PERCORSO). Ometterlo
    // darebbe RouteNotFoundException, cioè un 500 sul bottone principale.
    $fake = portaleFinto();

    $this->actingAs($this->membro)
        ->post(route('abbonamento.portale'))
        ->assertRedirect('https://billing.stripe.test/sessione-finta');

    expect($fake->invocazioni)->toBe([route('abbonamento.index')]);
});

it('never touches Stripe for an account without a customer', function () {
    // Un account Free non ha customer **per definizione** (ADR-002): è un caso
    // normalissimo, e `billingPortalUrl()` ci lancerebbe sopra
    // (`assertCustomerExists()`). La guardia sta prima di ogni round-trip.
    $fake = portaleFinto();

    $this->account->forceFill(['stripe_id' => null])->save();

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    expect($fake->invocazioni)->toBeEmpty();
});

it('hides the button and explains itself on a free plan without a customer', function () {
    $this->account->forceFill(['stripe_id' => null, 'piano' => 'free'])->save();

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee(route('abbonamento.portale'))
        ->assertSee('Il piano Free non ha un portale di fatturazione');
});

it('refuses before the network when the environment has no Stripe secret', function () {
    // La forma in cui questo si presenta su Laravel Cloud: `optimize` gira in
    // BUILD, quindi una variabile aggiunta dopo resta invisibile.
    $fake = portaleFinto();
    config(['cashier.secret' => null]);

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');

    expect($fake->invocazioni)->toBeEmpty();
});

it('reports the failure instead of showing a blank 500 when Stripe refuses', function () {
    // `report()` e non un catch muto: il guasto finisce nell'error tracker
    // interno invece di sparire, e chi ha cliccato riceve una frase.
    app()->instance(PortaleStripe::class, new class extends PortaleStripe
    {
        public function url(Account $account, string $returnUrl): string
        {
            throw new RuntimeException('Stripe non risponde');
        }
    });

    $this->actingAs($this->membro)
        ->from(route('abbonamento.index'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('abbonamento.index'))
        ->assertSessionHas('erroreAbbonamento');
});

it('never shows the lock reason on a page a locked customer can reach', function () {
    // 🔴 PRIVACY. `locked_reason` e `stripe_lock_reason` sono annotazioni
    // operative interne (solleciti, riferimenti di pratica, id di evento
    // Stripe): ADR-013 li tiene fuori da /bloccato apposta, e questa è la
    // superficie NUOVA da cui potrebbero trapelare.
    //
    // ⚠️ Due `assertDontSee` separate e mai `toContain()` con due argomenti:
    // quel metodo è variadico e il secondo testo diventerebbe un secondo ago,
    // rendendo l'asserzione negativa soddisfatta sempre.
    $this->account->blocca('Sollecito n.3 pratica 42/2026');
    $this->account->bloccaPerStripe('Evento Stripe evt_riservato_9');

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee('Sollecito')
        ->assertDontSee('42/2026')
        ->assertDontSee('evt_riservato_9');
});

it('offers the menu entry to the member who administers the contract', function () {
    // ⚠️ 2FA confermato solo qui: la dashboard sta nel gruppo protetto, e un
    // Admin senza secondo fattore verrebbe spinto al setup prima di vedere il
    // menù. /abbonamento invece sta fuori da `two-factor.enforce` apposta —
    // vedi PortaleStripeInLockoutTest, dove quella differenza È il test.
    $this->membro->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($this->membro->fresh())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('abbonamento.index'));
});

it('never offers the menu entry to a user of the same Ente who does not administer it', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $this->account->aggiungiMembro($tenant);

    // Membro dell'account ma senza il permesso: la voce non deve comparire —
    // vederla e poterla usare devono essere la stessa domanda.
    $this->actingAs($tenant)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('abbonamento.index'));
});
