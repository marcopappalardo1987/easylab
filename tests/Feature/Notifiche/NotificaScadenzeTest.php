<?php

use App\Enums\TipoMotivoSemaforo;
use App\Enums\VisibilitaGaranzieRicambio;
use App\Models\Account;
use App\Models\AvvisoScadenza;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\DigestScadenze;
use App\Support\Notifiche\RigaAvviso;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Lo scheduler delle scadenze (🔗 ADR-011) — area rossa della Policy di Code
 * Review su tre fronti: niente duplicati, isolamento fra Enti, privacy dei
 * ricambi. In console i global scope non filtrano, quindi ciascuna di quelle
 * tre garanzie vive nel codice del comando e va verificata qui, non dedotta.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();

    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Agitatore 2358']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->admin->assignRole('Admin');
});

function scheduler(): void
{
    test()->artisan('easylab:notifica-scadenze')->assertSuccessful();
}

/** @return list<RigaAvviso> righe del digest ricevuto da un utente */
function righeRicevuteDa(User $utente): array
{
    $inviate = Notification::sent($utente, DigestScadenze::class);

    return $inviate->isEmpty() ? [] : $inviate->first()->righe;
}

it('warns about an intervento becoming imminent at the soglia boundary', function () {
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(30)->toDateString()]);

    scheduler();

    expect(righeRicevuteDa($this->admin))->toHaveCount(1);
});

it('stays silent one day beyond the soglia', function () {
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(31)->toDateString()]);

    scheduler();

    Notification::assertNothingSent();
});

it('warns about a garanzia at the soglia boundary, and not one day beyond', function () {
    // Il confine delle garanzie ha il suo test perché non ce l'aveva: la prima
    // stesura del comando ricalcolava la soglia a mano invece di usare
    // `Garanzia::entroSoglia()`, e una mutazione che la spostava di un giorno
    // non faceva diventare rosso nulla.
    Garanzia::factory()->forStrumento($this->strumento)
        ->scadenzaDichiarata(today()->addDays(30)->toDateString())->create();

    scheduler();
    expect(righeRicevuteDa($this->admin))->toHaveCount(1);

    $oltre = Strumento::factory()->forNode($this->dept)->create();
    Garanzia::factory()->forStrumento($oltre)
        ->scadenzaDichiarata(today()->addDays(31)->toDateString())->create();

    Notification::fake();
    scheduler();
    Notification::assertNothingSent();
});

it('treats a garanzia expiring today as imminent, not as expired', function () {
    Garanzia::factory()->forStrumento($this->strumento)
        ->scadenzaDichiarata(today()->toDateString())->create();

    scheduler();

    expect(righeRicevuteDa($this->admin)[0]->transizione->value)->toBe('imminente');
});

it('treats a scadenza falling today as imminent, not as expired', function () {
    // `isScaduto()` usa `lt(today())`: oggi non è ancora in ritardo.
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->toDateString()]);

    scheduler();

    expect(righeRicevuteDa($this->admin)[0]->transizione->value)->toBe('imminente');
});

it('warns a second time when the scadenza actually expires', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDay()->toDateString()]);

    scheduler();
    expect(righeRicevuteDa($this->admin)[0]->transizione->value)->toBe('imminente');

    $this->travel(2)->days();
    Notification::fake();
    scheduler();

    expect(righeRicevuteDa($this->admin)[0]->transizione->value)->toBe('scaduta')
        ->and(AvvisoScadenza::where('riferimento_id', $intervento->id)->count())->toBe(2);
});

it('sends nothing on a second run in the same day', function () {
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(10)->toDateString()]);

    scheduler();
    expect(AvvisoScadenza::count())->toBe(1);

    Notification::fake();
    scheduler();

    Notification::assertNothingSent();
    expect(AvvisoScadenza::count())->toBe(1);
});

it('fills the log without notifying anyone, for the first run on existing data', function () {
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(10)->toDateString()]);

    $this->artisan('easylab:notifica-scadenze', ['--senza-invio' => true])->assertSuccessful();

    Notification::assertNothingSent();
    expect(AvvisoScadenza::count())->toBe(1);

    // E il giorno dopo il silenzio continua: la scadenza è già stata registrata,
    // quindi non è più una novità. È tutto il punto dell'opzione.
    scheduler();
    Notification::assertNothingSent();
});

it('only warns once for something that was already expired when created', function () {
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->subMonths(3)->toDateString()]);

    scheduler();

    $righe = righeRicevuteDa($this->admin);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->transizione->value)->toBe('scaduta');
});

it('warns again after a proroga, on the new date', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(10)->toDateString()]);

    scheduler();

    $intervento->update(['data_scadenza' => today()->addDays(20)->toDateString()]);
    Notification::fake();
    scheduler();

    expect(righeRicevuteDa($this->admin))->toHaveCount(1);
});

it('ignores interventi already done', function () {
    Intervento::factory()->forStrumento($this->strumento)->fatto()
        ->create(['data_scadenza' => today()->subWeek()->toDateString()]);

    scheduler();

    Notification::assertNothingSent();
});

it('ignores a ricambio that is not mounted yet', function () {
    $ricambio = Ricambio::factory()->forTenant($this->ente)->create();
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)->forRicambio($ricambio)->forIntervento($intervento)
        ->nonMontato()->create();
    Garanzia::factory()->forRicambio($utilizzo)->imminente()->create();

    scheduler();

    // L'intervento non è in scadenza; la garanzia del pezzo non montato non
    // deve avvisare nessuno (ADR-020, «montati» preso alla lettera).
    Notification::assertNothingSent();
});

it('never warns the enti of an account in lockout', function () {
    // ADR-013: il blocco per insoluto è totale. Un promemoria operativo con un
    // link che sbatte su /bloccato direbbe due cose opposte nello stesso
    // minuto. Trovato dalla review del blocco S5, non da un test preesistente.
    $bloccato = Account::factory()->bloccato()->create();
    $sedeBloccata = UnitaOrganizzativa::factory()->ente()->perAccount($bloccato)->create();
    $suoStrumento = Strumento::factory()->forNode($sedeBloccata)->create();
    $suoAdmin = User::factory()->create(['tenant_id' => $sedeBloccata->id]);
    $suoAdmin->assignRole('Admin');

    Intervento::factory()->forStrumento($suoStrumento)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);
    // …e una scadenza nell'Ente sano del setup, che invece deve partire.
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    scheduler();

    Notification::assertNotSentTo($suoAdmin, DigestScadenze::class);
    expect(righeRicevuteDa($this->admin))->toHaveCount(1);

    // Gli avvisi non si «consumano» per l'Ente bloccato: allo sblocco la
    // scadenza ancora aperta torna a essere una novità.
    expect(AvvisoScadenza::where('tenant_id', $sedeBloccata->id)->count())->toBe(0);
});

it('warns again after the account is unlocked', function () {
    $bloccato = Account::factory()->bloccato()->create();
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($bloccato)->create();
    $strumento = Strumento::factory()->forNode($sede)->create();
    $admin = User::factory()->create(['tenant_id' => $sede->id]);
    $admin->assignRole('Admin');
    Intervento::factory()->forStrumento($strumento)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    scheduler();
    Notification::assertNotSentTo($admin, DigestScadenze::class);

    $bloccato->sblocca();
    Notification::fake();
    scheduler();

    Notification::assertSentTo($admin, DigestScadenze::class);
});

it('warns a Tenant about the machines of their own Ente', function () {
    // 🔴 Il Tenant è entrato fra i destinatari il 25 Ago 2026. Prima non
    // riceveva NULLA — né email né notifica in-app, perché `via()` scrive
    // `database` solo per chi `destinatari()` sceglie — mentre §4 gli promette
    // le notifiche programmate e `/settings/notifiche` una preferenza per
    // un'email che nessuno gli mandava.
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    Intervento::factory()->forStrumento($this->strumento)->scaduto()
        ->create(['descrizione' => 'Taratura annuale']);

    Notification::fake();
    $this->artisan('easylab:notifica-scadenze')->assertOk();

    Notification::assertSentTo($tenant, DigestScadenze::class);
});

it('never puts a ricambio row in a Tenant digest when their Ente hides them', function () {
    // 🔴 **La conseguenza dell'ingresso del Tenant**, e la ragione per cui il
    // filtro ADR-029 è nato con lui: fino a ieri il docblock del comando poteva
    // dire «nessun destinatario è il ruolo che quella regola protegge», e da
    // oggi sarebbe falso.
    //
    // In console i global scope non filtrano, quindi `GaranziaRicambioPrivacyScope`
    // qui non gira: fra quelle righe e la casella di posta c'è solo questo
    // filtro.
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);

    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    $ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Lampada UV']);
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)->forRicambio($ricambio)
        ->create(['data' => today()->subMonths(2)->toDateString()]);
    Garanzia::factory()->scadenzaDichiarata(today()->addDays(5)->toDateString())
        ->forRicambio($utilizzo)->create();

    Notification::fake();
    $this->artisan('easylab:notifica-scadenze')->assertOk();

    // Nessun digest affatto: quella era l'unica riga in scadenza dell'Ente, e
    // per lui non esiste.
    Notification::assertNotSentTo($tenant, DigestScadenze::class);
});

it('does send that same row to a Tenant whose Ente shows them', function () {
    // Il complementare, senza cui il test qui sopra sarebbe verde anche con il
    // digest rotto per tutti.
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Lettura);

    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');

    $ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Lampada UV']);
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)->forRicambio($ricambio)
        ->create(['data' => today()->subMonths(2)->toDateString()]);
    Garanzia::factory()->scadenzaDichiarata(today()->addDays(5)->toDateString())
        ->forRicambio($utilizzo)->create();

    Notification::fake();
    $this->artisan('easylab:notifica-scadenze')->assertOk();

    Notification::assertSentTo($tenant, DigestScadenze::class, function (DigestScadenze $digest) {
        return collect($digest->righe)
            ->contains(fn ($riga) => $riga->tipo === TipoMotivoSemaforo::GaranziaRicambio);
    });
});

it('never puts rows of another Ente in a digest', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $suoStrumento = Strumento::factory()->forNode($altroEnte)->create(['nome' => 'Centrifuga B']);
    Intervento::factory()->forStrumento($suoStrumento)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    scheduler();

    $righe = righeRicevuteDa($this->admin);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->strumentoNome)->toBe('Agitatore 2358');

    $reso = (string) Notification::sent($this->admin, DigestScadenze::class)
        ->first()->toMail($this->admin)->render();
    expect($reso)->not->toContain('Centrifuga B')
        ->and($reso)->not->toContain('Ente B');
});

it('limits a Responsabile to the machines of their own sotto-albero', function () {
    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);

    $fuori = Strumento::factory()->forNode($this->altroDept)->create(['nome' => 'Fuori reparto']);
    Intervento::factory()->forStrumento($fuori)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    scheduler();

    expect(righeRicevuteDa($this->admin))->toHaveCount(2);

    $sue = righeRicevuteDa($resp);
    expect($sue)->toHaveCount(1)
        ->and($sue[0]->strumentoNome)->toBe('Agitatore 2358');
});

it('sends nothing to a Responsabile without assignments', function () {
    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');

    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    scheduler();

    Notification::assertNotSentTo($resp, DigestScadenze::class);
});

it('warns the assigned Tecnico about their intervento, and nothing else', function () {
    $tecnico = User::factory()->create(['tenant_id' => null]); // esterno (ADR-030)
    $tecnico->assignRole('Tecnico');

    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($tecnico)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);
    Garanzia::factory()->forStrumento($this->strumento)->imminente()->create();

    scheduler();

    expect(righeRicevuteDa($this->admin))->toHaveCount(2);

    $sue = righeRicevuteDa($tecnico);
    expect($sue)->toHaveCount(1)
        ->and($sue[0]->tipo->value)->toBe('intervento');
});

it('gives an external Tecnico one digest per Ente, never a mixed one', function () {
    $tecnico = User::factory()->create(['tenant_id' => null]);
    $tecnico->assignRole('Tecnico');

    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $suoStrumento = Strumento::factory()->forNode($altroEnte)->create();

    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($tecnico)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);
    Intervento::factory()->forStrumento($suoStrumento)->assegnatoA($tecnico)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    scheduler();

    $inviate = Notification::sent($tecnico, DigestScadenze::class);
    expect($inviate)->toHaveCount(2);
    foreach ($inviate as $digest) {
        expect($digest->righe)->toHaveCount(1);
    }
});

it('sends a single digest to an Admin who is also the assigned Tecnico', function () {
    $this->admin->assignRole('Tecnico');

    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($this->admin)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    scheduler();

    expect(Notification::sent($this->admin, DigestScadenze::class))->toHaveCount(1)
        ->and(righeRicevuteDa($this->admin))->toHaveCount(1);
});

it('never reads the name of a ricambio, not even into the payload', function () {
    $ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Lampada UV XR-7']);
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)->forRicambio($ricambio)->forIntervento($intervento)
        ->montatoAMano(today()->subMonth()->toDateString())->create();
    Garanzia::factory()->forRicambio($utilizzo)->imminente()->create();

    scheduler();

    $digest = Notification::sent($this->admin, DigestScadenze::class)->first();
    $righe = collect($digest->righe)->where('tipo.value', 'garanzia_ricambio');

    expect($righe)->toHaveCount(1)
        ->and($righe->first()->dettaglio)->toBeNull()
        ->and(json_encode($digest->toArray($this->admin)))->not->toContain('Lampada UV');
});
