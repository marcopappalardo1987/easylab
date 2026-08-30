<?php

use App\Enums\StatoIntervento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Azioni interventi in scheda strumento (S3 punto 3): CRUD + spunta "Fatto".
 * Aree rosse (Policy di Code Review): permessi per ruolo, whitelist
 * assegnatario, isolamento tenant/sotto-albero sulle scritture.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    // Dal 9 Ago 2026 un intervento è SEMPRE assegnato a un tecnico: i test che
    // salvano dal form devono indicarne uno, come farebbe l'utente.
    $this->tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Mario Rossi']);
    $this->tecnico->assignRole('Tecnico');
});

// --- CRUD felice ---

it('creates a planned intervento from the modal', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Controllo pressione valvole')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addMonths(2)->toDateString())
        ->set('interventoForm.tecnico_id', $this->tecnico->id)
        ->call('saveIntervento')
        ->assertHasNoErrors()
        ->assertSet('showInterventoForm', false);

    $intervento = Intervento::withoutGlobalScopes()->where('descrizione', 'Controllo pressione valvole')->sole();
    expect($intervento->stato)->toBe(StatoIntervento::NonFatto)
        ->and($intervento->tenant_id)->toBe($this->strumento->tenant_id)
        ->and($intervento->strumento_id)->toBe($this->strumento->id)
        ->and($intervento->data_esecuzione)->toBeNull();
});

it('creates a historical done intervento in one step', function () {
    // L'inserimento storico del backlog: mai transitare da scaduto-non-fatto.
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Taratura 2025')
        ->set('interventoForm.tipo', 'taratura_e_certificazione')
        ->set('interventoForm.data_scadenza', '2025-05-28')
        ->set('interventoForm.gia_eseguito', true)
        ->set('interventoForm.data_esecuzione', '2025-05-30')
        ->set('interventoForm.tecnico_id', $this->tecnico->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    $intervento = Intervento::withoutGlobalScopes()->where('descrizione', 'Taratura 2025')->sole();
    expect($intervento->stato)->toBe(StatoIntervento::Fatto)
        ->and($intervento->data_esecuzione->toDateString())->toBe('2025-05-30');
});

it('updates descrizione, tipo and data_scadenza without touching stato', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->fatto('2026-01-12')->create();

    scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->assertSet('interventoForm.descrizione', $intervento->descrizione)
        ->set('interventoForm.descrizione', 'Descrizione corretta')
        ->set('interventoForm.tipo', 'manutenzione_straordinaria')
        ->set('interventoForm.data_scadenza', '2026-03-01')
        ->set('interventoForm.tecnico_id', $this->tecnico->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    $fresh = $intervento->fresh();
    expect($fresh->descrizione)->toBe('Descrizione corretta')
        ->and($fresh->tipo->value)->toBe('manutenzione_straordinaria')
        ->and($fresh->stato)->toBe(StatoIntervento::Fatto)
        ->and($fresh->data_esecuzione->toDateString())->toBe('2026-01-12');
});

it('soft deletes an intervento after confirmation', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    scheda($this->admin, $this->strumento)
        ->call('openEliminaIntervento', $intervento->id)
        ->assertSet('deletingInterventoId', $intervento->id)
        ->call('eliminaIntervento')
        ->assertSet('deletingInterventoId', null);

    // NB: withoutGlobalScopes() rimuoverebbe anche il SoftDeletingScope,
    // quindi il "non trovato" va verificato con la query scopata.
    expect(Intervento::find($intervento->id))->toBeNull()
        ->and(Intervento::withoutGlobalScopes()->withTrashed()->find($intervento->id)->trashed())->toBeTrue();
});

it('marks an intervento as fatto with the default today date', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    scheda($this->admin, $this->strumento)
        ->call('openCompleta', $intervento->id)
        ->assertSet('dataEsecuzione', today()->toDateString())
        ->call('completa')
        ->assertHasNoErrors();

    expect($intervento->fresh())
        ->stato->toBe(StatoIntervento::Fatto)
        ->data_esecuzione->toDateString()->toBe(today()->toDateString());
});

it('marks an intervento as fatto with an explicit past date', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    scheda($this->admin, $this->strumento)
        ->call('openCompleta', $intervento->id)
        ->set('dataEsecuzione', today()->subDays(3)->toDateString())
        ->call('completa')
        ->assertHasNoErrors();

    expect($intervento->fresh()->data_esecuzione->toDateString())->toBe(today()->subDays(3)->toDateString());
});

it('rejects a data_esecuzione in the future', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    scheda($this->admin, $this->strumento)
        ->call('openCompleta', $intervento->id)
        ->set('dataEsecuzione', today()->addDay()->toDateString())
        ->call('completa')
        ->assertHasErrors('dataEsecuzione');

    expect($intervento->fresh()->stato)->toBe(StatoIntervento::NonFatto);
});

it('reopening clears data_esecuzione through the component', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->fatto('2026-01-12')->create();

    scheda($this->admin, $this->strumento)->call('riapri', $intervento->id);

    expect($intervento->fresh())
        ->stato->toBe(StatoIntervento::NonFatto)
        ->data_esecuzione->toBeNull();
});

// --- Permessi (🔴) ---

it('forbids a Tenant from every intervento action', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    foreach ([
        ['openNuovoIntervento'],
        ['openModificaIntervento', $intervento->id],
        ['saveIntervento'],
        ['openCompleta', $intervento->id],
        ['completa'],
        ['riapri', $intervento->id],
        ['openEliminaIntervento', $intervento->id],
        ['eliminaIntervento'],
    ] as $call) {
        scheda($tenant, $this->strumento)->call(...$call)->assertForbidden();
    }
});

it('lets a Tecnico complete and reopen but not create, update or delete', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tecnico->assignRole('Tecnico');

    // ⚠️ `tecnico_id` dal 17 Ago 2026 (ADR-030): senza assegnazione il tecnico
    // non vede l'intervento, quindi non potrebbe nemmeno provare a chiuderlo — e
    // il caso misurerebbe l'accesso invece dei PERMESSI, che è ciò di cui parla.
    // Non è un vincolo artificioso: chiudere un lavoro che non ti è stato
    // affidato non è uno scenario che ADR-007 preveda.
    $intervento = Intervento::factory()->forStrumento($this->strumento)->scaduto()
        ->create(['tecnico_id' => $tecnico->id]);

    scheda($tecnico, $this->strumento)
        ->call('openCompleta', $intervento->id)
        ->call('completa')
        ->assertHasNoErrors();
    expect($intervento->fresh()->stato)->toBe(StatoIntervento::Fatto);

    scheda($tecnico, $this->strumento)->call('riapri', $intervento->id);
    expect($intervento->fresh()->stato)->toBe(StatoIntervento::NonFatto);

    foreach ([
        ['openNuovoIntervento'],
        ['saveIntervento'],
        ['openModificaIntervento', $intervento->id],
        ['openEliminaIntervento', $intervento->id],
        ['eliminaIntervento'],
    ] as $call) {
        scheda($tecnico, $this->strumento)->call(...$call)->assertForbidden();
    }
});

it('requires an assignee: an intervento is always someone\'s', function () {
    // Regola del 9 Ago 2026. **Obbligatorio nel form, nullable nello schema**,
    // come il fornitore di ADR-023: sul DB di sviluppo 4178 interventi su 20672
    // non hanno assegnatario, e una FK NOT NULL li renderebbe non salvabili —
    // costringendo a inventare un tecnico per farli passare.
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Senza nessuno')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', null)
        ->call('saveIntervento')
        ->assertHasErrors(['interventoForm.tecnico_id']);

    expect(Intervento::withoutGlobalScopes()->count())->toBe(0);
});

it('forces an assignee when editing one of the legacy unassigned interventi', function () {
    // Conseguenza voluta: le righe storiche restano com'è finché nessuno le
    // tocca, ma modificarne una obbliga a scegliere. È il prezzo di non aver
    // inventato un assegnatario per 4178 interventi.
    $storico = Intervento::factory()->forStrumento($this->strumento)->create(['tecnico_id' => null]);

    scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $storico->id)
        ->set('interventoForm.descrizione', 'Corretta')
        ->call('saveIntervento')
        ->assertHasErrors(['interventoForm.tecnico_id']);

    expect($storico->fresh()->descrizione)->not->toBe('Corretta');
});

it('does not lock out a user who can create but not assign', function () {
    // ⚠️ Il ramo che rende la regola CONDIZIONALE al permesso, e senza questo
    // test non era coperto: il caso vicino («ignores tecnico_id…») imposta la
    // chiave nel payload, quindi soddisfa comunque un `required` e non
    // distingue le due varianti. Qui il campo si lascia vuoto, com'è nella
    // realtà per chi il select non lo vede nemmeno.
    //
    // Nessuno dei 6 ruoli è in questo stato — un meta-test in RbacSeederTest lo
    // congela — ma un `required` incondizionato bloccherebbe del tutto un ruolo
    // futuro invece di lasciargli fare ciò che può.
    Role::create(['name' => 'Compilatore junior'])
        ->givePermissionTo(['strumenti.view', 'interventi.view', 'interventi.create']);
    $compilatore = User::factory()->create(['tenant_id' => $this->ente->id]);
    $compilatore->assignRole('Compilatore junior');

    scheda($compilatore, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Creato senza poter assegnare')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Creato senza poter assegnare')->sole()->tecnico_id)
        ->toBeNull();
});

it('ignores tecnico_id from a user with create but without assign', function () {
    // Ruolo ad hoc: nessuno dei 6 ruoli seedati ha create senza assign.
    Role::create(['name' => 'Compilatore'])
        ->givePermissionTo(['strumenti.view', 'interventi.view', 'interventi.create']);
    $compilatore = User::factory()->create(['tenant_id' => $this->ente->id]);
    $compilatore->assignRole('Compilatore');
    $collega = User::factory()->create(['tenant_id' => $this->ente->id]);

    scheda($compilatore, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Senza assegnatario')
        ->set('interventoForm.tipo', 'taratura_e_certificazione')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $collega->id) // payload manipolato
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Senza assegnatario')->sole()->tecnico_id)
        ->toBeNull();
});

// --- Whitelist assegnatario (🔴, speculare a tecnicoLabel) ---

it('assigns a tecnico of the same ente', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Luca Bianchi']);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Con assegnatario')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $tecnico->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Con assegnatario')->sole()->tecnico_id)
        ->toBe($tecnico->id);
});

it('rejects an external Tecnico who is NOT in this sede portafoglio (ADR-038 replaces "every tecnico everywhere")', function () {
    // 🔴 Riscritto il 29 Ago 2026. Fino a ieri questo test si chiamava «accepts
    // an external Tecnico with no tenant» e congelava la regola vecchia: OGNI
    // tecnico di piattaforma era assegnabile su OGNI cliente. ADR-038 la
    // restringe al portafoglio `tecnico_cliente`, per due ragioni: ogni Admin
    // cliente leggeva l'organigramma di EasyLab in una <select>, e un tecnico
    // fuori portafoglio era assegnabile su una macchina che `AccessoTecnico`
    // non gli fa nemmeno vedere. La riga qui sotto non è un'omissione: il
    // tecnico esiste, ha il ruolo, non ha tenant — gli manca SOLO la riga di
    // portafoglio, ed è quella che oggi decide.
    $esterno = User::factory()->create(['tenant_id' => null, 'name' => 'Gino Verdi']);
    $esterno->assignRole('Tecnico');

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Assegnato a esterno')
        ->set('interventoForm.tipo', 'taratura_e_certificazione')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $esterno->id) // id forgiato: non era in tendina
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.tecnico_id');

    // ⛔ La validazione rifiuta PRIMA della transazione: nessuna riga scritta.
    $this->assertDatabaseMissing('interventi', ['descrizione' => 'Assegnato a esterno']);
});

it('accepts the same external Tecnico once the sede enters his portafoglio (ADR-038)', function () {
    // Lo stesso tecnico del test qui sopra, cambiata una sola cosa: la riga di
    // portafoglio. È la prova differenziale che a decidere è quella e non altro.
    $esterno = User::factory()->create(['tenant_id' => null, 'name' => 'Gino Verdi']);
    $esterno->assignRole('Tecnico');
    $esterno->portafoglioClienti()->attach($this->ente->id);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Assegnato a esterno')
        ->set('interventoForm.tipo', 'taratura_e_certificazione')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $esterno->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Assegnato a esterno')->sole()->tecnico_id)
        ->toBe($esterno->id);
});

it('keeps an already assigned intervento after the portafoglio is revoked (ADR-030 second channel)', function () {
    // ⚠️ Il portafoglio governa CHI è assegnabile, non CHI è assegnato. La
    // revoca è un gesto di oggi e non deve riscrivere lo storico: l'intervento
    // resta, e la pagina continua a dire il nome — l'assegnazione puntuale è il
    // secondo canale di ADR-030 e non passa dal portafoglio.
    $esterno = User::factory()->create(['tenant_id' => null, 'name' => 'Gino Verdi']);
    $esterno->assignRole('Tecnico');
    $esterno->portafoglioClienti()->attach($this->ente->id);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Lavoro in corso')
        ->set('interventoForm.tipo', 'taratura_e_certificazione')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $esterno->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    $esterno->portafoglioClienti()->detach($this->ente->id);

    $intervento = Intervento::withoutGlobalScopes()->where('descrizione', 'Lavoro in corso')->sole();
    expect($intervento->tecnico_id)->toBe($esterno->id)
        ->and($intervento->tecnicoLabel())->toBe('Gino Verdi');

    // …ma da adesso non è più proponibile: la tendina lo ha perso.
    $assegnatari = scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->viewData('assegnatari');

    expect($assegnatari->pluck('id')->all())->not->toContain($esterno->id);
});

it('rejects a tecnico belonging to another ente', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $estraneo = User::factory()->create(['tenant_id' => $enteB->id]);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Non deve salvare')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $estraneo->id)
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.tecnico_id');

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Non deve salvare')->exists())->toBeFalse();
});

it('rejects a user with no tenant and no Tecnico role', function () {
    // Il ∪ della whitelist è sul RUOLO, non sul solo tenant null.
    $piattaforma = User::factory()->create(['tenant_id' => null]);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Non deve salvare')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $piattaforma->id)
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.tecnico_id');
});

it('lists tenant users and only the portafoglio Tecnici in the assegnatari select (ADR-038)', function () {
    // 🔴 Riscritto il 29 Ago 2026: prima bastava «essere Tecnico senza tenant».
    // Ora la tendina è la STESSA query della validazione (Assegnabili), quindi
    // i due tecnici esterni si separano proprio qui: `$inPortafoglio` c'è,
    // `$fuoriPortafoglio` no — ed è il gesto che impedisce a un Admin cliente
    // di leggere l'organigramma di EasyLab.
    $collega = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Collega A']);

    $inPortafoglio = User::factory()->create(['tenant_id' => null, 'name' => 'Tecnico Nostro']);
    $inPortafoglio->assignRole('Tecnico');
    $inPortafoglio->portafoglioClienti()->attach($this->ente->id);

    $fuoriPortafoglio = User::factory()->create(['tenant_id' => null, 'name' => 'Tecnico Altrui']);
    $fuoriPortafoglio->assignRole('Tecnico');

    // …e uno in portafoglio su un ALTRO cliente: il pivot si legge per sede.
    $enteB = UnitaOrganizzativa::factory()->ente()->create();
    $suUnAltroCliente = User::factory()->create(['tenant_id' => null, 'name' => 'Tecnico Di B']);
    $suUnAltroCliente->assignRole('Tecnico');
    $suUnAltroCliente->portafoglioClienti()->attach($enteB->id);

    $estraneo = User::factory()->create(['tenant_id' => $enteB->id, 'name' => 'Estraneo B']);

    $assegnatari = scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->viewData('assegnatari');

    $ids = $assegnatari->pluck('id')->all();
    expect($ids)->toContain($collega->id)
        ->toContain($inPortafoglio->id);
    expect($ids)->not->toContain($fuoriPortafoglio->id);
    expect($ids)->not->toContain($suUnAltroCliente->id);
    expect($ids)->not->toContain($estraneo->id);
});

it('rejects a user with no tenant and no Tecnico role even with a portafoglio row (ADR-038)', function () {
    // ⚠️ L'unione è sul RUOLO, non sul tenant nullo: una riga di portafoglio da
    // sola non promuove nessuno. Il caso è raggiungibile — Superadmin e
    // Developer non hanno `tenant_id` — e senza il ramo sul ruolo un cliente
    // vedrebbe in tendina il nome di chi amministra la piattaforma.
    $piattaforma = User::factory()->create(['tenant_id' => null, 'name' => 'Non Tecnico']);
    $piattaforma->portafoglioClienti()->attach($this->ente->id);

    $assegnatari = scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->viewData('assegnatari');

    expect($assegnatari->pluck('id')->all())->not->toContain($piattaforma->id);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Non deve salvare')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $piattaforma->id)
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.tecnico_id');

    $this->assertDatabaseMissing('interventi', ['descrizione' => 'Non deve salvare']);
});

it('still lets an old intervento be edited after its assegnatario left (ADR-038)', function () {
    // 🔴 La regola nuova restringe **chi si può assegnare**, non **cosa si può
    // conservare**. `openModificaIntervento()` ricarica `tecnico_id` dalla
    // riga, `tecnico_id` è `required`, e la tendina non offre più chi se n'è
    // andato: senza l'unione con l'assegnatario in essere, correggere una
    // descrizione rispondeva «Assegnatario non valido» su un campo che nessuno
    // aveva toccato — cioè cestinare una persona rendeva **immodificabile ogni
    // suo intervento storico**, che è il contrario di ciò che ADR-038 promette.
    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Tecnico']);
    $uscito->assignRole('Tecnico');

    $intervento = Intervento::factory()->forStrumento($this->strumento)->assegnatoA($uscito)
        ->create(['descrizione' => 'Taratura di marzo']);

    $uscito->delete();

    $pagina = scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->set('interventoForm.descrizione', 'Taratura di marzo (corretta)')
        ->call('saveIntervento')
        ->assertHasNoErrors();

    expect($intervento->fresh()->descrizione)->toBe('Taratura di marzo (corretta)')
        ->and($intervento->fresh()->tecnico_id)->toBe($uscito->id);

    // …e la tendina lo mostra, o si potrebbe salvare solo un valore invisibile.
    expect($pagina->call('openModificaIntervento', $intervento->id)
        ->viewData('assegnatari')->pluck('id')->all())->toContain($uscito->id);
});

it('never lets the editing exception widen into a new assignment (ADR-038)', function () {
    // ⛔ Il controllo che rende onesta l'eccezione qui sopra: si conserva chi
    // c'è già, non si accetta chiunque perché la modale è in modifica. Un
    // secondo id fuori perimetro deve restare rifiutato **sulla stessa
    // richiesta** in cui il primo sarebbe passato.
    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Tecnico']);
    $uscito->assignRole('Tecnico');

    $intervento = Intervento::factory()->forStrumento($this->strumento)->assegnatoA($uscito)
        ->create(['descrizione' => 'Taratura di marzo']);

    $uscito->delete();

    $altroCestinato = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Altro Uscito']);
    $altroCestinato->delete();

    scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->set('interventoForm.tecnico_id', $altroCestinato->id)
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.tecnico_id');

    expect($intervento->fresh()->tecnico_id)->toBe($uscito->id);

    // E la tendina non lo propone: l'unione è di **uno** solo.
    expect(scheda($this->admin, $this->strumento)
        ->call('openModificaIntervento', $intervento->id)
        ->viewData('assegnatari')->pluck('id')->all())->not->toContain($altroCestinato->id);
});

it('never proposes a trashed person, not even one of the same Ente (ADR-038)', function () {
    // Il cestino non aggiunge un `if` da ricordare qui: cade dal global scope
    // di SoftDeletes su `User`, ed è precisamente il punto di aver scelto il
    // cestino invece di un flag `is_active`.
    $uscito = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Ex Dipendente']);
    $uscito->delete();

    $assegnatari = scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->viewData('assegnatari');

    expect($assegnatari->pluck('id')->all())->not->toContain($uscito->id);

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Assegnato a un cestinato')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addWeek()->toDateString())
        ->set('interventoForm.tecnico_id', $uscito->id)
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.tecnico_id');

    $this->assertDatabaseMissing('interventi', ['descrizione' => 'Assegnato a un cestinato']);
});

// --- Validazioni di forma ---

it('rejects an invalid tipo and requires descrizione and data_scadenza', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', '')
        ->set('interventoForm.tipo', 'lavaggio')
        ->set('interventoForm.data_scadenza', '')
        ->call('saveIntervento')
        ->assertHasErrors(['interventoForm.descrizione', 'interventoForm.tipo', 'interventoForm.data_scadenza']);
});

it('accepts a past data_scadenza (historical entries are legitimate)', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Scadenza passata')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', '2025-01-01')
        ->set('interventoForm.tecnico_id', $this->tecnico->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();
});

it('requires data_esecuzione when gia_eseguito is checked and rejects a future one', function () {
    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Storico incompleto')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', '2025-01-01')
        ->set('interventoForm.gia_eseguito', true)
        ->set('interventoForm.data_esecuzione', '')
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.data_esecuzione');

    scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Storico futuro')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', '2025-01-01')
        ->set('interventoForm.gia_eseguito', true)
        ->set('interventoForm.data_esecuzione', today()->addDay()->toDateString())
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.data_esecuzione');
});

// --- Isolamento sulle scritture (🔴) ---

it('returns 404 when acting on an intervento of another tenant', function () {
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();
    $interventoB = Intervento::factory()->forStrumento($strumentoB)->create(['descrizione' => 'Di B']);

    foreach ([
        ['openModificaIntervento', $interventoB->id],
        ['openCompleta', $interventoB->id],
        ['riapri', $interventoB->id],
        ['openEliminaIntervento', $interventoB->id],
    ] as $call) {
        expect(fn () => scheda($this->admin, $this->strumento)->call(...$call))
            ->toThrow(ModelNotFoundException::class);
    }

    $survivor = Intervento::withoutGlobalScopes()->withTrashed()->find($interventoB->id);
    expect($survivor)->not->toBeNull()
        ->and($survivor->trashed())->toBeFalse()
        ->and($survivor->stato)->toBe(StatoIntervento::NonFatto);
});

it('returns 404 for a Responsabile acting outside the assigned subtree', function () {
    $deptA2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);
    $strumentoA2 = Strumento::factory()->forNode($deptA2)->create();

    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($deptA2->id); // vede SOLO deptA2, non $this->dept

    // Lo strumento della scheda ($this->dept) è fuori dal suo sotto-albero →
    // dalla rotta il route-model binding scopato dà già 404. (Livewire::test
    // bypassa il binding: il 404 del mount si verifica solo via HTTP.)
    $this->actingAs($resp)->get(route('strumenti.show', $this->strumento))->assertNotFound();

    // E dalla scheda di uno strumento visibile non può raggiungere interventi
    // di strumenti fuori scope: id di un altro strumento → 404 dalla relazione.
    $interventoFuori = Intervento::factory()->forStrumento($this->strumento)->create();
    expect(fn () => scheda($resp, $strumentoA2)->call('openCompleta', $interventoFuori->id))
        ->toThrow(ModelNotFoundException::class);
});

it('returns 404 when the id belongs to another strumento of the same tenant', function () {
    $altroStrumento = Strumento::factory()->forNode($this->dept)->create();
    $interventoAltro = Intervento::factory()->forStrumento($altroStrumento)->create();

    expect(fn () => scheda($this->admin, $this->strumento)->call('riapri', $interventoAltro->id))
        ->toThrow(ModelNotFoundException::class);
});
