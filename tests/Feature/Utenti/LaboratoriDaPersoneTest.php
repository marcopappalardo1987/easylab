<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Utenti\ElencoUtenti;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * 🔴 Assegnare i laboratori a un Responsabile Reparto da «Persone»
 * (🔗 ADR-057) — area rossa: ciò che questa finestra scrive decide che cosa
 * una persona vede.
 *
 * Le regole su *quali* laboratori siano leciti stanno in
 * `LaboratoriDelResponsabileTest`. Qui si provano le porte della schermata: il
 * permesso (in ogni azione, non solo sulla rotta), il confine dell'Ente — che
 * su `User` si scrive a mano — e il ruolo di chi riceve.
 *
 * Mondo: l'Ospedale ha Chimica (con dentro Cromatografia) e Fisica. La Clinica
 * è l'altro cliente.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Ospedale']);
    $this->ospedale = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Ospedale']);
    $this->chimica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ospedale)->create(['nome' => 'Chimica']);
    $this->cromatografia = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->chimica)->create(['nome' => 'Cromatografia']);
    $this->fisica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ospedale)->create(['nome' => 'Fisica']);

    $altroAccount = Account::factory()->create(['ragione_sociale' => 'Clinica']);
    $this->clinica = UnitaOrganizzativa::factory()->ente()->perAccount($altroAccount)->create(['nome' => 'Clinica']);
    $this->radiologia = UnitaOrganizzativa::factory()->dipartimento()->under($this->clinica)->create(['nome' => 'Radiologia']);

    $this->persona = function (string $nome, string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create(['name' => $nome, 'tenant_id' => ($ente ?? $this->ospedale)->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u->fresh();
    };

    $this->admin = ($this->persona)('Aldo Admin', 'Admin');
    $this->account->aggiungiMembro($this->admin);
    $this->rita = ($this->persona)('Rita Responsabile', 'Responsabile Reparto');
    $this->bruno = ($this->persona)('Bruno Clinica', 'Responsabile Reparto', $this->clinica);

    $this->pagina = fn (?User $chi = null) => Livewire::actingAs($chi ?? $this->admin)->test(ElencoUtenti::class);

    /** Le righe del pivot di una persona, così come stanno nel database. */
    $this->righe = fn (User $chi): array => DB::table('responsabile_unita')
        ->where('user_id', $chi->id)->orderBy('unita_organizzativa_id')->pluck('unita_organizzativa_id')->map(fn ($id) => (int) $id)->all();
});

// ─── Che cosa si vede in elenco ──────────────────────────────────────────────

it('says of a responsabile without laboratori that they see nothing, and offers the way to fix it', function () {
    ($this->persona)('Tina Tenant', 'Tenant');
    ($this->persona)('Mario Tecnico', 'Tecnico');

    $html = ($this->pagina)()->html();

    // Uno solo: Rita. Né l'Admin, né il Tenant, né il Tecnico hanno laboratori da seguire.
    expect(substr_count($html, 'data-senza-laboratori'))->toBe(1)
        ->and(substr_count($html, 'wire:click="apriLaboratori('))->toBe(1)
        ->and($html)->toContain('Nessun laboratorio assegnato: non vede nulla')
        ->toContain('wire:click="apriLaboratori('.$this->rita->id.')"');
});

it('lists what a responsabile follows, and cuts a long list short', function () {
    $nomi = ['Analisi', 'Biologia', 'Ematologia', 'Genetica'];
    $altri = array_map(fn (string $nome) => UnitaOrganizzativa::factory()->dipartimento()->under($this->ospedale)->create(['nome' => $nome])->id, $nomi);

    $this->rita->unitaResponsabili()->sync([$this->cromatografia->id]);

    $testo = fn () => preg_replace('/\s+/', ' ', strip_tags(($this->pagina)()->html()));

    expect($testo())->toContain('Segue: Chimica › Cromatografia')
        ->not->toContain('Nessun laboratorio assegnato');

    $this->rita->unitaResponsabili()->sync([...array_slice($altri, 0, 3), $this->fisica->id]);

    // In ordine di percorso, i primi tre; il quarto si conta.
    expect($testo())->toContain('Segue: Analisi, Biologia, Ematologia e un altro');

    $this->rita->unitaResponsabili()->sync([...$altri, $this->fisica->id]);

    expect($testo())->toContain('Segue: Analisi, Biologia, Ematologia e altri 2');
});

// ─── Assegnare ───────────────────────────────────────────────────────────────

it('lets an admin choose the laboratori of a responsabile from the list of the ente', function () {
    $pagina = ($this->pagina)()
        ->call('apriLaboratori', $this->rita->id)
        ->assertSet('utenteLaboratori', $this->rita->id)
        ->assertSet('laboratoriScelti', [])
        ->assertSee('Laboratori di Rita Responsabile')
        // 🔴 Solo i laboratori di questo Ente, e mai la sede.
        ->assertDontSee('Radiologia')
        ->assertDontSeeHtml('value="'.$this->ospedale->id.'"');

    // Nell'ordine dell'albero: il sotto-laboratorio subito dopo ciò che lo contiene.
    preg_match_all('/value="(\d+)"/', $pagina->html(), $caselle);

    expect(array_map('intval', $caselle[1]))->toBe([$this->chimica->id, $this->cromatografia->id, $this->fisica->id])
        ->and($pagina->html())->toContain('<span>Chimica › Cromatografia</span>');

    $pagina->set('laboratoriScelti', [(string) $this->cromatografia->id, (string) $this->fisica->id])
        ->call('salvaLaboratori')
        ->assertHasNoErrors()
        ->assertSet('utenteLaboratori', null)
        ->assertSet('laboratoriScelti', [])
        ->assertSet('notice', 'Rita Responsabile ora segue 2 laboratori, con ciò che contengono.')
        ->assertSee('Chimica › Cromatografia, Fisica')
        ->assertDontSeeHtml('data-senza-laboratori');

    expect(($this->righe)($this->rita))->toBe([$this->cromatografia->id, $this->fisica->id]);

    $riga = Activity::query()->where('description', 'Laboratori assegnati')->sole();

    expect($riga->causer_id)->toBe($this->admin->id)
        ->and($riga->subject_id)->toBe($this->rita->id);
});

it('opens the window again with what the person already follows', function () {
    $this->rita->unitaResponsabili()->sync([$this->fisica->id]);

    ($this->pagina)()
        ->call('apriLaboratori', $this->rita->id)
        ->assertSet('laboratoriScelti', [(string) $this->fisica->id]);
});

it('shows a sub of a chosen laboratorio as already inside it, and does not let it be chosen apart', function () {
    $pagina = ($this->pagina)()->call('apriLaboratori', $this->rita->id);

    expect($pagina->html())->not->toContain('data-compreso');

    // Due livelli sotto: non basta guardare il laboratorio che lo contiene.
    $colonne = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->cromatografia)->create(['nome' => 'Colonne']);

    $pagina->set('laboratoriScelti', [(string) $this->chimica->id])
        ->assertSeeHtml('data-compreso="'.$this->cromatografia->id.'"')
        ->assertSeeHtml('data-compreso="'.$colonne->id.'"')
        ->assertDontSeeHtml('value="'.$colonne->id.'"')
        ->assertSee('(compreso in Chimica)')
        // Il sotto-laboratorio non ha più una casella che si manda.
        ->assertDontSeeHtml('value="'.$this->cromatografia->id.'"')
        ->assertSeeHtml('value="'.$this->fisica->id.'"');
});

it('takes everything away when nothing is left ticked, and says what that means', function () {
    $this->rita->unitaResponsabili()->sync([$this->fisica->id]);

    ($this->pagina)()
        ->call('apriLaboratori', $this->rita->id)
        ->set('laboratoriScelti', [])
        ->call('salvaLaboratori')
        ->assertSet('notice', 'Rita Responsabile non ha più nessun laboratorio: finché non ne ha, non vede nulla.')
        ->assertSeeHtml('data-senza-laboratori');

    expect(($this->righe)($this->rita))->toBe([]);
});

it('says one laboratorio in the singular', function () {
    ($this->pagina)()
        ->call('apriLaboratori', $this->rita->id)
        ->set('laboratoriScelti', [(string) $this->chimica->id])
        ->call('salvaLaboratori')
        ->assertSet('notice', 'Rita Responsabile ora segue un laboratorio, con ciò che contiene.');
});

it('makes the responsabile see those laboratori and nothing else, from the very next request', function () {
    Strumento::factory()->forNode($this->cromatografia)->create(['nome' => 'HPLC']);
    Strumento::factory()->forNode($this->fisica)->create(['nome' => 'Laser']);

    // Prima: niente.
    Livewire::actingAs($this->rita)->test(Albero::class)
        ->assertDontSee('Cromatografia')
        ->assertDontSee('Fisica');

    ($this->pagina)()
        ->call('apriLaboratori', $this->rita->id)
        ->set('laboratoriScelti', [(string) $this->cromatografia->id])
        ->call('salvaLaboratori')
        ->assertHasNoErrors();

    Livewire::actingAs($this->rita->fresh())->test(Albero::class)
        ->assertSee('Cromatografia')
        ->assertSee('HPLC')
        ->assertDontSee('Fisica')
        ->assertDontSee('Laser');
});

it('tells an ente without laboratori where they are created, instead of showing an empty list', function () {
    $vuoto = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Sede nuova']);
    $admin = ($this->persona)('Nora Nuova', 'Admin', $vuoto);
    $resp = ($this->persona)('Remo', 'Responsabile Reparto', $vuoto);

    ($this->pagina)($admin)
        ->call('apriLaboratori', $resp->id)
        ->assertSeeHtml('data-nessun-laboratorio')
        ->assertSee('Questo Ente non ha ancora laboratori')
        ->assertDontSeeHtml('data-elenco-laboratori');
});

// ─── Il permesso, in ogni azione ─────────────────────────────────────────────

it('asks for utenti.update in every action, not only where the button is shown', function () {
    // Chi vede l'elenco ma non può modificare: la rotta lo fa entrare, le azioni no.
    $soloLettura = Role::create(['name' => 'Solo lettura persone', 'guard_name' => 'web']);
    $soloLettura->givePermissionTo('utenti.view');
    $lettore = ($this->persona)('Lea Lettrice', 'Solo lettura persone');

    $pagina = ($this->pagina)($lettore);

    expect($pagina->html())->not->toContain('data-apri-laboratori');

    $pagina->call('apriLaboratori', $this->rita->id)->assertForbidden();

    ($this->pagina)($lettore)->set('utenteLaboratori', $this->rita->id)->assertForbidden();

    // 🔴 E l'azione che scrive, chiamata senza passare dalla finestra.
    ($this->pagina)($lettore)
        ->set('laboratoriScelti', [(string) $this->chimica->id])
        ->call('salvaLaboratori')
        ->assertForbidden();

    expect(($this->righe)($this->rita))->toBe([]);
});

it('lets a superadmin do the same on the ente they are in', function () {
    $superadmin = ($this->persona)('Sara Superadmin', 'Superadmin');

    ($this->pagina)($superadmin)
        ->call('apriLaboratori', $this->rita->id)
        ->set('laboratoriScelti', [(string) $this->fisica->id])
        ->call('salvaLaboratori')
        ->assertHasNoErrors();

    expect(($this->righe)($this->rita))->toBe([$this->fisica->id]);
});

// ─── Il confine dell'Ente ────────────────────────────────────────────────────

it('finds nobody when asked for a responsabile of another ente, by action or by property', function () {
    // `User` non ha uno scope di tenancy: un id di un'altra sede deve dare 404,
    // cioè «non esiste per te», senza aprire niente.
    expect(fn () => ($this->pagina)()->call('apriLaboratori', $this->bruno->id))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->pagina)()->set('utenteLaboratori', $this->bruno->id))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->pagina)()->call('apriLaboratori', 999999))
        ->toThrow(ModelNotFoundException::class);

    expect(DB::table('responsabile_unita')->count())->toBe(0);
});

it('refuses a laboratorio of another ente sent from the browser, and writes nothing', function () {
    $this->rita->unitaResponsabili()->sync([$this->fisica->id]);

    ($this->pagina)()
        ->call('apriLaboratori', $this->rita->id)
        // 🔴 Una richiesta costruita a mano: un laboratorio lecito e uno di un altro cliente.
        ->set('laboratoriScelti', [(string) $this->chimica->id, (string) $this->radiologia->id])
        ->call('salvaLaboratori')
        ->assertHasErrors('laboratoriScelti')
        ->assertSee('non è di questo Ente')
        // La finestra resta aperta, con l'errore accanto.
        ->assertSet('utenteLaboratori', $this->rita->id);

    expect(($this->righe)($this->rita))->toBe([$this->fisica->id]);
});

it('refuses the sede sent from the browser', function () {
    ($this->pagina)()
        ->call('apriLaboratori', $this->rita->id)
        ->set('laboratoriScelti', [(string) $this->ospedale->id])
        ->call('salvaLaboratori')
        ->assertHasErrors('laboratoriScelti');

    expect(($this->righe)($this->rita))->toBe([]);
});

it('refuses what is not a list of numbers before it gets anywhere near an id', function (Closure $scelti) {
    // 🔴 I casi che contano sono quelli che un cast a intero trasformerebbe
    // nell'id di un laboratorio LECITO: senza la validazione verrebbero
    // scritti, e nessun'altra guardia se ne accorgerebbe.
    ($this->pagina)()
        ->call('apriLaboratori', $this->rita->id)
        ->set('laboratoriScelti', $scelti())
        ->call('salvaLaboratori')
        ->assertHasErrors();

    expect(($this->righe)($this->rita))->toBe([]);
})->with([
    'una lista dentro la lista' => [fn () => [[(string) test()->chimica->id]]],
    'una parola' => [fn () => ['chimica']],
    'un id con la virgola' => [fn () => [test()->chimica->id.'.5']],
    'un id con una coda' => [fn () => [test()->chimica->id.' or 1=1']],
]);

// ─── Il ruolo di chi riceve ──────────────────────────────────────────────────

it('opens the window only for a responsabile reparto', function (string $ruolo) {
    // Per ogni altro ruolo i laboratori non contano: una riga scritta oggi
    // tornerebbe a contare il giorno in cui la persona cambia ruolo.
    $altro = ($this->persona)('Persona', $ruolo);

    ($this->pagina)()->call('apriLaboratori', $altro->id)->assertForbidden();
    ($this->pagina)()->set('utenteLaboratori', $altro->id)->assertForbidden();

    expect(($this->righe)($altro))->toBe([]);
})->with(['Admin', 'Tenant', 'Tecnico']);

it('does not open the window for a responsabile in the bin', function () {
    $this->rita->delete();

    expect(fn () => ($this->pagina)()->call('apriLaboratori', $this->rita->id))
        ->toThrow(ModelNotFoundException::class);
});

it('does not write if the person stopped being a responsabile while the window was open', function () {
    $pagina = ($this->pagina)()
        ->call('apriLaboratori', $this->rita->id)
        ->set('laboratoriScelti', [(string) $this->chimica->id]);

    // Da un'altra scheda, qualcuno le cambia ruolo.
    $this->rita->syncRoles(['Tenant']);

    $pagina->call('salvaLaboratori')->assertForbidden();

    expect(($this->righe)($this->rita))->toBe([]);
});

// ─── I laboratori sono del ruolo ─────────────────────────────────────────────

it('takes the laboratori away from who is moved to another role, in the same gesture', function () {
    $this->rita->unitaResponsabili()->sync([$this->chimica->id, $this->fisica->id]);

    ($this->pagina)()
        ->call('apriRuolo', $this->rita->id)
        ->set('nuovoRuolo', 'Tenant')
        ->call('cambiaRuolo')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Rita Responsabile ora è Tenant.');

    // 🔴 Lasciati lì non conterebbero oggi, e tornerebbero a contare il giorno
    // in cui ridiventa Responsabile, senza che nessuno glieli abbia ridati.
    expect(($this->righe)($this->rita))->toBe([])
        ->and(Activity::query()->where('description', 'Laboratori tolti col ruolo')->where('subject_id', $this->rita->id)->count())->toBe(1);
});

it('leaves the laboratori alone when the role is saved as it was', function () {
    $this->rita->unitaResponsabili()->sync([$this->chimica->id]);

    ($this->pagina)()
        ->call('apriRuolo', $this->rita->id)
        ->set('nuovoRuolo', 'Responsabile Reparto')
        ->call('cambiaRuolo')
        ->assertHasNoErrors()
        // Non è diventata Responsabile adesso: non c'è niente da ricordarle.
        ->assertSet('notice', 'Rita Responsabile ora è Responsabile Reparto.');

    expect(($this->righe)($this->rita))->toBe([$this->chimica->id]);
});

it('reminds who makes somebody a responsabile that the laboratori come next', function () {
    $tina = ($this->persona)('Tina Tenant', 'Tenant');

    ($this->pagina)()
        ->call('apriRuolo', $tina->id)
        ->set('nuovoRuolo', 'Responsabile Reparto')
        ->call('cambiaRuolo')
        ->assertSet('notice', 'Tina Tenant ora è Responsabile Reparto. Ora assegnale i laboratori con «Laboratori»: finché non ne ha, non vede nulla.')
        ->assertSeeHtml('wire:click="apriLaboratori('.$tina->id.')"');
});

it('says the same after inviting somebody as a responsabile, and not for the other roles', function (string $ruolo, bool $loDice) {
    Notification::fake();

    $pagina = ($this->pagina)()
        ->call('apriInvito')
        ->set('nome', 'Nuova Persona')
        ->set('email', 'nuova@ospedale.test')
        ->set('ruolo', $ruolo)
        ->call('invita')
        ->assertHasNoErrors();

    Notification::assertSentTimes(InvitoUtente::class, 1);

    expect(str_contains((string) $pagina->get('notice'), 'Ora assegnale i laboratori con «Laboratori»'))->toBe($loDice);
})->with([
    'Responsabile Reparto' => ['Responsabile Reparto', true],
    'Tenant' => ['Tenant', false],
    'Tecnico' => ['Tecnico', false],
]);

// ─── Quanto costa ────────────────────────────────────────────────────────────

it('reads nothing about laboratori on a page that has no responsabile', function () {
    $this->rita->delete();
    $this->actingAs($this->admin);

    DB::enableQueryLog();
    Livewire::test(ElencoUtenti::class);

    $query = collect(DB::getQueryLog())->pluck('query')->implode(' | ');

    expect($query)->not->toContain('responsabile_unita')
        ->not->toContain('"unita_organizzativa" where "tenant_id" = ? and "deleted_at" is null');
});
