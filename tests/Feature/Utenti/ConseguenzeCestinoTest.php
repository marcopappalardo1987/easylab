<?php

use App\Livewire\Piattaforma\ParcoGlobale;
use App\Livewire\Piattaforma\RegistroAudit;
use App\Models\Account;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\DigestScadenze;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 Le **conseguenze del cestino** sui percorsi di lettura (🔗 ADR-038).
 *
 * ADR-038 sceglie il soft delete invece di un flag `is_active` con un argomento
 * preciso: «non aggiunge uno stato da consultare, **toglie la riga**» — login,
 * tendine, destinatari del digest e candidati all'impersonazione cadono tutti
 * dallo stesso global scope, senza un solo `if` nuovo da ricordare in dodici
 * punti.
 *
 * ⚠️ **Un argomento del genere va misurato, non creduto.** Se domani un
 * percorso di lettura leggesse `withTrashed()` per comodità — o se una
 * relazione lo facesse per errore — una persona cestinata tornerebbe a ricevere
 * email o a comparire fra i bersagli dell'impersonazione, e nessun test lo
 * direbbe. Questo file è la misura: ogni `it` qui sotto è **un canale** che il
 * cestino deve chiudere, o **uno** che deve restare aperto.
 *
 * La simmetria è il punto: le cinque relazioni di **attribuzione storica** sono
 * `withTrashed()` apposta (🔗 ADR-038, tabella delle conseguenze), quindi lo
 * storico continua a nominare chi se n'è andato. Chiudere e nominare non sono
 * in contraddizione: sono le due metà della stessa decisione.
 *
 * Test speculari: `FondamentaUtentiTest` (login e whitelist a livello di
 * `Assegnabili`), `InterventoModelTest` (`tecnicoLabel()`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->account = Account::factory()->saas()->create(['ragione_sociale' => 'Ospedale San Marco']);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)
        ->create(['nome' => 'Sede Milano']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'name' => 'Anna Admin',
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Admin');
    $this->account->aggiungiMembro($this->admin);
});

// --- I canali che il cestino DEVE chiudere ---

it('drops a binned person from the nightly digest of their own Ente (ADR-011)', function () {
    // `DestinatariEnte::perEnte()` fa `User::query()->where('tenant_id', …)`:
    // niente `withTrashed()`, quindi il global scope di SoftDeletes basta. Il
    // secondo Admin è la prova differenziale — se il comando smettesse di
    // scrivere a chiunque, questo test resterebbe verde a torto.
    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Admin']);
    $uscito->assignRole('Admin');

    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(10)->toDateString()]);

    $uscito->delete();

    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();

    Notification::assertSentTo($this->admin, DigestScadenze::class);
    Notification::assertNotSentTo($uscito, DigestScadenze::class);
});

it('drops a binned external Tecnico from the digest of the work assigned to him', function () {
    // ⚠️ Canale diverso dal precedente, e con una query diversa:
    // `NotificaScadenze` raccoglie gli assegnatari **senza filtro sul tenant**
    // — apposta, perché il tecnico EasyLab non ne ha uno (🔗 ADR-030). Proprio
    // per questo va verificato a parte: è la lettura di `users` che il filtro
    // per Ente non copre.
    $tecnico = User::factory()->create(['tenant_id' => null, 'name' => 'Gino Verdi']);
    $tecnico->assignRole('Tecnico');
    $tecnico->portafoglioClienti()->attach($this->ente->id);

    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($tecnico)
        ->create(['data_scadenza' => today()->addDays(10)->toDateString()]);

    $tecnico->delete();

    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();

    Notification::assertNotSentTo($tecnico, DigestScadenze::class);
    // …e l'Ente riceve comunque: la riga esiste, è solo il suo assegnatario a
    // essere uscito di scena.
    Notification::assertSentTo($this->admin, DigestScadenze::class);
});

it('still sends the digest to a LIVE external Tecnico, so the test above measures the bin and not a dead branch', function () {
    // ⛔ Il controllo che rende onesto il test qui sopra. Senza, un ramo del
    // comando che smettesse di funzionare del tutto lascerebbe verde
    // «il cestinato non riceve» — e la guardia sarebbe vuota.
    $tecnico = User::factory()->create(['tenant_id' => null, 'name' => 'Gino Verdi']);
    $tecnico->assignRole('Tecnico');
    $tecnico->portafoglioClienti()->attach($this->ente->id);

    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($tecnico)
        ->create(['data_scadenza' => today()->addDays(10)->toDateString()]);

    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();

    Notification::assertSentTo($tecnico, DigestScadenze::class);
});

it('drops a binned member from the impersonation candidates of the parco', function () {
    // `candidatiDi()` filtra in PHP su `$account->membri`, che è una
    // BelongsToMany verso `User`: gli scope del model correlato si applicano,
    // quindi il cestinato non arriva nemmeno alla `filter()`.
    $superadmin = superadminDiPiattaforma();

    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Collega']);
    $uscito->assignRole('Admin');
    $this->account->aggiungiMembro($uscito);
    $uscito->delete();

    $candidati = Livewire::actingAs($superadmin)->test(ParcoGlobale::class)
        ->call('apriScelta', $this->account->id)
        ->instance()->candidatiScelti();

    $nomi = $candidati->pluck('name')->all();
    expect($nomi)->toContain('Anna Admin');
    expect($nomi)->not->toContain('Ex Collega');
});

it('answers 404 when the impersonation-to-machine door is aimed at a binned person', function () {
    // ⛔ Secondo ingresso all'impersonazione (🔗 ADR-037): la porta legge
    // `User::query()->whereKey()`, quindi il cestinato non si risolve e il
    // controller aborta PRIMA di `take()`. Il negativo che conta è l'ultima
    // riga: non si è entrati come nessuno.
    $superadmin = superadminDiPiattaforma();

    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Collega']);
    $uscito->assignRole('Admin');
    $this->account->aggiungiMembro($uscito);
    $uscito->delete();

    $this->actingAs($superadmin)
        ->get(route('piattaforma.parco.impersona', ['utente' => $uscito->id, 'strumento' => $this->strumento->id]))
        ->assertNotFound();

    expect(app('impersonate')->isImpersonating())->toBeFalse();
});

// --- I canali che il cestino NON deve chiudere: lo storico continua a nominare ---

it('keeps naming a binned assegnatario on the scheda strumento', function () {
    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Dipendente']);

    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($uscito)
        ->create(['descrizione' => 'Taratura di marzo']);

    $uscito->delete();

    scheda($this->admin, $this->strumento)
        ->assertSee('Taratura di marzo')
        ->assertSee('Ex Dipendente');
});

it('keeps naming a binned person in the audit register, filter «chi» included', function () {
    // 🔴 Il registro è **il** posto in cui una persona uscita deve restare
    // nominabile: è precisamente quando si va a leggere chi ha fatto cosa che
    // serve saperlo. `RegistroAudit` legge `withTrashed()` in due punti — il
    // filtro «chi» e i nomi degli impersonatori — e fino a oggi nessun test
    // teneva ferme quelle due righe.
    $superadmin = superadminDiPiattaforma();

    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Collega']);
    $uscito->assignRole('Admin');

    // ⚠️ Le factory scrivono audit da sé (`AuditsDomainWrites` su `created`):
    // si fa piazza pulita, o il filtro pesca una riga di fixture.
    Activity::query()->delete();

    $riga = Activity::create([
        'log_name' => AuditLog::NAME,
        'description' => 'Cosa fatta prima di andarsene',
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => $uscito->id,
        // «per conto di»: la colonna più delicata del registro, quella che deve
        // dire un nome e non un «#12».
        'properties' => ['impersonato_da' => $uscito->id],
    ]);

    $uscito->delete();

    $pagina = Livewire::actingAs($superadmin)->test(RegistroAudit::class);

    // 1. Il nome si risolve ancora fra gli impersonatori.
    expect($pagina->viewData('impersonatori')->get($uscito->id))->toBe('Ex Collega');

    // 2. E cercarlo per nome lo **trova**: senza `withTrashed()` la sottoquery
    //    del filtro non restituirebbe nessun id, e la ricerca per cui il
    //    registro esiste tornerebbe vuota.
    $pagina->set('chi', 'Ex Collega');

    expect($pagina->viewData('righe')->pluck('id')->all())->toBe([$riga->id]);
});

it('keeps naming a binned assegnatario in the storico PDF', function () {
    // dompdf comprime il contenuto: si verifica la view che lo produce, che è
    // dove il contenuto è deciso (stessa disciplina di EsportaStoricoPdfTest).
    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Dipendente']);

    Intervento::factory()->forStrumento($this->strumento)->assegnatoA($uscito)
        ->create(['descrizione' => 'Taratura di marzo']);

    $uscito->delete();

    $vista = view('pdf.storico-strumento', [
        'strumento' => $this->strumento,
        'percorso' => $this->strumento->percorsoUbicazione(),
        'interventi' => $this->strumento->interventi()->with('tecnico')->get(),
        'garanzia' => null,
        'generatoIl' => now(),
        'generatoDa' => $this->admin->name,
    ])->render();

    // ⚠️ `e()` e non il nome nudo, e un'asserzione per volta: `toContain()` è
    // variadico, quindi due aghi nella stessa chiamata sono due aghi, non un
    // ago e un messaggio.
    expect($vista)->toContain('Taratura di marzo');
    expect($vista)->toContain(e('Ex Dipendente'));
});

/** Il Superadmin di piattaforma, che è l'unico a poter guardare il parco. */
function superadminDiPiattaforma(): User
{
    $easylab = Account::factory()->create(['ragione_sociale' => 'EasyLab', 'di_piattaforma' => true]);
    $enteEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($easylab)->create(['nome' => 'EasyLab']);

    $superadmin = User::factory()->create([
        'tenant_id' => $enteEasylab->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $superadmin->assignRole('Superadmin');
    $easylab->aggiungiMembro($superadmin);

    return $superadmin;
}
