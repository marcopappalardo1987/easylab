<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Concerns\SceglieFornitore;
use App\Models\Account;
use App\Models\Fornitore;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Il selettore del fornitore (🔗 ADR-051; ADR-023 il fornitore della macchina).
 *
 * Tre cose chieste da Marco il 10 Ott 2026: scorrere l'elenco, filtrarlo
 * scrivendo, e creare il fornitore che manca senza uscire dal form. E una che
 * non ha chiesto ma che ogni elenco di un'anagrafica deve reggere: **non far
 * vedere, né scegliere, il fornitore di un altro Ente**.
 *
 * Mondo: l'Ente A con tre fornitori, uno dei quali cestinato; l'Ente B, di un
 * altro cliente, col suo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->reparto = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Reparto A']);

    $this->alfa = Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => 'Alfa Strumenti']);
    $this->beta = Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => 'Beta Medical']);
    $this->cestinato = Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => 'Gamma Service']);
    $this->cestinato->delete();

    $this->altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $this->altrui = Fornitore::factory()->forTenant($this->altroEnte)->create(['ragione_sociale' => 'Fornitore altrui']);

    $this->utente = function (string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create(['tenant_id' => ($ente ?? $this->ente)->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u->fresh();
    };

    $this->admin = ($this->utente)('Admin');
});

/** Il form «nuovo strumento» aperto sul reparto, com'è prima di toccare il fornitore. */
function formNuovoStrumento(User $chi, UnitaOrganizzativa $reparto)
{
    return Livewire::actingAs($chi)->test(Albero::class)
        ->call('open', $reparto->id)
        ->call('addStrumento');
}

// ─── L'elenco: della propria sede, e di nessun'altra ─────────────────────────

it('shows a closed selector first, and the suppliers of the sede only when it is opened', function () {
    $form = formNuovoStrumento($this->admin, $this->reparto)
        ->assertSee('— Scegli un fornitore —')
        // Chiuso non elenca nessuno: nessuna query, nessun nome in pagina.
        ->assertDontSee('Alfa Strumenti')
        ->assertDontSeeHtml('data-pannello-fornitori');

    $form->call('apriFornitori', 'strumento')
        ->assertSet('selettoreFornitore', 'strumento')
        ->assertSeeHtml('data-pannello-fornitori="strumento"')
        ->assertSee('Alfa Strumenti')
        ->assertSee('Beta Medical')
        // 🔴 Né quello di un altro Ente, né un cestinato.
        ->assertDontSee('Fornitore altrui')
        ->assertDontSee('Gamma Service');
});

it('filters by what is typed, whatever the case and wherever it falls in the name', function () {
    $form = formNuovoStrumento($this->admin, $this->reparto)->call('apriFornitori', 'strumento');

    DB::enableQueryLog();
    $form->set('cercaFornitore', 'MEDIC')
        ->assertSee('Beta Medical')
        ->assertDontSee('Alfa Strumenti');
    $ricerca = collect(DB::getQueryLog())->last(fn (array $q) => str_contains($q['query'], 'LOWER(ragione_sociale) LIKE'));
    DB::disableQueryLog();

    // ⚠️ Sull'SQL e non solo sul risultato: su SQLite `LIKE` ignora già le
    // maiuscole, quindi lì il nome si troverebbe anche senza abbassare ciò che
    // si cerca. Su Postgres no, ed è dove gira l'applicazione.
    expect($ricerca['bindings'])->toContain('%medic%');

    $form->set('cercaFornitore', '  alfa ')
        ->assertSee('Alfa Strumenti')
        ->assertDontSee('Beta Medical');

    $form->set('cercaFornitore', 'zzz')
        ->assertSee('Nessun fornitore con questo nome.')
        ->assertDontSee('Alfa Strumenti');
});

it('takes the wildcards of a search for what they are, characters', function (string $jolly) {
    // `%` e `_` sono jolly per LIKE: senza escape «%» troverebbe tutti, e chi
    // cerca davvero un nome con dentro quel carattere non lo troverebbe mai.
    Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => "Sconti 50{$jolly} srl"]);

    formNuovoStrumento($this->admin, $this->reparto)
        ->call('apriFornitori', 'strumento')
        ->set('cercaFornitore', $jolly)
        ->assertSee("Sconti 50{$jolly} srl")
        ->assertDontSee('Alfa Strumenti')
        ->assertDontSee('Beta Medical');
})->with(['%', '_']);

it('cuts a long list, says so, and orders it in a way no engine can shuffle', function () {
    foreach (range(1, 55) as $i) {
        Fornitore::factory()->forTenant($this->ente)->create(['ragione_sociale' => sprintf('Fornitore %03d', $i)]);
    }

    DB::enableQueryLog();
    $form = formNuovoStrumento($this->admin, $this->reparto)->call('apriFornitori', 'strumento');
    $sql = collect(DB::getQueryLog())->pluck('query')
        ->last(fn (string $q) => str_contains($q, 'from "fornitori"') && str_contains($q, 'limit'));
    DB::disableQueryLog();

    // Cinquanta in elenco, e la pagina dice che ce ne sono altri.
    expect(substr_count($form->html(), 'wire:click="scegliFornitore('))->toBe(Albero::FORNITORI_IN_ELENCO)
        ->and($form->html())->toContain('data-altri-fornitori')
        // ⚠️ Sull'SQL: a parità di ragione sociale l'ordine fra due righe è una
        // proprietà del motore, e l'elenco è tagliato.
        ->and($sql)->toContain('order by "ragione_sociale" asc, "id" asc');

    // Scrivendo si restringe, e l'avviso sparisce con ciò che avvisava.
    $form->set('cercaFornitore', 'Fornitore 05')
        ->assertSee('Fornitore 055')
        ->assertDontSeeHtml('data-altri-fornitori');

    expect(substr_count($form->html(), 'wire:click="scegliFornitore('))->toBe(6);
});

// ─── Scegliere ───────────────────────────────────────────────────────────────

it('writes the chosen supplier in the form, closes, and saves it with the instrument', function () {
    $form = formNuovoStrumento($this->admin, $this->reparto)
        ->set('strumentoForm.nome', 'Autoclave AC-200')
        ->call('apriFornitori', 'strumento')
        ->set('cercaFornitore', 'beta')
        ->call('scegliFornitore', $this->beta->id)
        ->assertSet('strumentoForm.fornitore_id', $this->beta->id)
        ->assertSet('selettoreFornitore', null)
        ->assertSet('cercaFornitore', '')
        // Chiuso, il selettore dice chi è stato scelto.
        ->assertSee('Beta Medical')
        ->assertDontSee('Alfa Strumenti')
        // 🔴 E il resto del form è ancora lì.
        ->assertSet('strumentoForm.nome', 'Autoclave AC-200')
        ->assertSet('showStrumentoForm', true);

    $form->call('saveStrumento')->assertHasNoErrors();

    expect(Strumento::withoutGlobalScopes()->where('nome', 'Autoclave AC-200')->value('fornitore_id'))->toBe($this->beta->id);
});

it('refuses a supplier that is not in the list, even when the id exists', function (string $quale) {
    // 🔴 L'id arriva dal browser. Fuori dall'elenco c'è un 404 e non un 403:
    // un «non ti è permesso» confermerebbe che quel fornitore esiste.
    $form = formNuovoStrumento($this->admin, $this->reparto)->call('apriFornitori', 'strumento');

    expect(fn () => $form->call('scegliFornitore', $this->{$quale}->id))->toThrow(ModelNotFoundException::class);

    expect($form->get('strumentoForm')['fornitore_id'])->toBeNull();
})->with(['altrui', 'cestinato']);

it('never writes the name of a supplier of another Ente on the closed selector', function () {
    // La property del form è pubblica: l'id si può forgiare senza passare dal
    // selettore. Al salvataggio lo ferma la regola; prima, la pagina non deve
    // nemmeno dirne il nome.
    formNuovoStrumento($this->admin, $this->reparto)
        ->set('strumentoForm.fornitore_id', $this->altrui->id)
        ->assertDontSee('Fornitore altrui')
        ->assertSee('— Scegli un fornitore —')
        ->set('strumentoForm.nome', 'Macchina')
        ->call('saveStrumento')
        ->assertHasErrors('strumentoForm.fornitore_id');
});

it('opens no selector for a field that is not on the screen', function () {
    $albero = Livewire::actingAs($this->admin)->test(Albero::class)->call('open', $this->reparto->id);

    // Il form è chiuso: il campo non c'è.
    $albero->call('apriFornitori', 'strumento')->assertNotFound();

    // E l'albero non ha righe di ricambi.
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->reparto->id)
        ->call('addStrumento')
        ->call('apriFornitori', 'ricambio.0')
        ->assertNotFound();

    // Lo stesso nella scheda di una macchina che esiste già.
    $strumento = Strumento::factory()->forNode($this->reparto)->create();
    scheda($this->admin, $strumento)->call('apriFornitori', 'strumento')->assertNotFound();

    // Scegliere senza un selettore aperto non scrive niente.
    Livewire::actingAs($this->admin)->test(Albero::class)
        ->call('open', $this->reparto->id)
        ->call('addStrumento')
        ->call('scegliFornitore', $this->alfa->id)
        ->assertNotFound();
});

it('forgets an open selector when the form is closed, so the next one does not start half open', function () {
    formNuovoStrumento($this->admin, $this->reparto)
        ->call('apriFornitori', 'strumento')
        ->set('cercaFornitore', 'alfa')
        ->call('closeStrumentoForm')
        ->assertSet('selettoreFornitore', null)
        ->call('addStrumento')
        ->assertSet('selettoreFornitore', null)
        ->assertSet('cercaFornitore', '')
        ->assertDontSeeHtml('data-pannello-fornitori');
});

it('offers the supplier already on the instrument even when it is in the bin, and only that one', function () {
    $strumento = Strumento::factory()->forNode($this->reparto)->create(['fornitore_id' => $this->alfa->id]);
    // `Fornitore` rifiuta di cestinarsi finché ha macchine: qui si prova ciò
    // che resta dopo, quando è successo comunque (un import, la console).
    DB::table('fornitori')->where('id', $this->alfa->id)->update(['deleted_at' => now()]);

    scheda($this->admin, $strumento->fresh())
        ->call('edit')
        ->assertSee('Alfa Strumenti (cestinato)')
        ->call('apriFornitori', 'strumento')
        ->assertSeeHtml('wire:click="scegliFornitore('.$this->alfa->id.')"')
        ->assertDontSeeHtml('wire:click="scegliFornitore('.$this->cestinato->id.')"')
        ->call('scegliFornitore', $this->alfa->id)
        ->call('save')
        ->assertHasNoErrors();
});

it('does not offer «no supplier» where the supplier is required', function () {
    // Sulla macchina il fornitore è obbligatorio (ADR-023): un bottone che lo
    // toglie porterebbe solo a un errore al salvataggio.
    $strumento = Strumento::factory()->forNode($this->reparto)->create(['fornitore_id' => $this->alfa->id]);

    scheda($this->admin, $strumento)
        ->call('edit')
        ->call('apriFornitori', 'strumento')
        ->assertSee('Chiudi')
        ->assertDontSeeHtml('wire:click="togliFornitore"');
});

// ─── Crearlo senza uscire ────────────────────────────────────────────────────

it('creates the missing supplier from the same form, in the sede of the instrument, and chooses it', function () {
    $form = formNuovoStrumento($this->admin, $this->reparto)
        ->set('strumentoForm.nome', 'Centrifuga')
        ->call('apriFornitori', 'strumento')
        ->set('cercaFornitore', '  Delta Lab ')
        ->assertSee('Nessun fornitore con questo nome.')
        ->assertSee('＋ Nuovo fornitore «Delta Lab»')
        ->call('apriNuovoFornitore')
        ->assertSeeHtml('data-nuovo-fornitore')
        // Si parte da ciò che si stava cercando: non lo si riscrive.
        ->assertSet('nuovoFornitore.ragione_sociale', 'Delta Lab')
        // Un indirizzo incollato porta con sé uno spazio in coda.
        ->set('nuovoFornitore.email', 'info@deltalab.test ')
        ->set('nuovoFornitore.telefono', '')
        ->call('creaFornitore')
        ->assertHasNoErrors();

    $delta = Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Delta Lab')->sole();

    expect($delta->tenant_id)->toBe($this->ente->id)
        ->and($delta->email)->toBe('info@deltalab.test')
        // Un campo facoltativo lasciato vuoto è `null`, non la stringa vuota.
        ->and($delta->telefono)->toBeNull();

    $form->assertSet('strumentoForm.fornitore_id', $delta->id)
        ->assertSet('selettoreFornitore', null)
        ->assertSet('nuovoFornitoreAperto', false)
        // 🔴 Il form della macchina non si è mosso: è il punto della richiesta.
        ->assertSet('showStrumentoForm', true)
        ->assertSet('strumentoForm.nome', 'Centrifuga')
        ->assertSee('Delta Lab');

    $form->call('saveStrumento')->assertHasNoErrors();

    expect(Strumento::withoutGlobalScopes()->where('nome', 'Centrifuga')->value('fornitore_id'))->toBe($delta->id);

    // Chi l'ha creato resta scritto, come dalla pagina Fornitori.
    $riga = Activity::where('log_name', AuditLog::NAME)->where('description', 'Creazione fornitore')->latest('id')->first();

    expect($riga->causer_id)->toBe($this->admin->id)
        ->and($riga->subject_id)->toBe($delta->id);
});

it('holds the new supplier to the rules of the suppliers page', function (array $campi, string $errore) {
    $form = formNuovoStrumento($this->admin, $this->reparto)
        ->call('apriFornitori', 'strumento')
        ->call('apriNuovoFornitore');

    foreach ($campi as $campo => $valore) {
        $form->set("nuovoFornitore.{$campo}", $valore);
    }

    $form->call('creaFornitore')
        ->assertHasErrors("nuovoFornitore.{$errore}")
        // Il mini-form resta aperto con ciò che si era scritto.
        ->assertSet('nuovoFornitoreAperto', true)
        ->assertSet('strumentoForm.fornitore_id', null);

    expect(Fornitore::withoutGlobalScopes()->withTrashed()->count())->toBe(4);
})->with([
    'senza ragione sociale' => [['ragione_sociale' => '   '], 'ragione_sociale'],
    'ragione sociale troppo lunga' => [['ragione_sociale' => str_repeat('a', 256)], 'ragione_sociale'],
    'email che non è un indirizzo' => [['ragione_sociale' => 'Delta', 'email' => 'non-una-email'], 'email'],
    'telefono troppo lungo' => [['ragione_sociale' => 'Delta', 'telefono' => str_repeat('1', 51)], 'telefono'],
]);

it('goes back from the new supplier to the list without losing the search', function () {
    formNuovoStrumento($this->admin, $this->reparto)
        ->call('apriFornitori', 'strumento')
        ->set('cercaFornitore', 'alfa')
        ->call('apriNuovoFornitore')
        ->call('annullaNuovoFornitore')
        ->assertSet('nuovoFornitoreAperto', false)
        ->assertSet('selettoreFornitore', 'strumento')
        ->assertSet('cercaFornitore', 'alfa')
        ->assertSee('Alfa Strumenti');
});

it('keeps the creation of a supplier behind its own permission', function () {
    // 🔴 Poter registrare una macchina non è poter allargare l'anagrafica: il
    // Responsabile ha `strumenti.create` e `fornitori.view`, non
    // `fornitori.create`. Sceglie fra quelli che ci sono, e basta.
    $responsabile = ($this->utente)('Responsabile Reparto');
    $responsabile->unitaResponsabili()->attach($this->reparto->id);

    $form = formNuovoStrumento($responsabile, $this->reparto)
        ->call('apriFornitori', 'strumento')
        ->assertSee('Alfa Strumenti')
        ->assertDontSeeHtml('data-apri-nuovo-fornitore');

    $form->call('apriNuovoFornitore')->assertForbidden();

    // E l'azione che scrive, chiamata con le property già piene.
    formNuovoStrumento($responsabile, $this->reparto)
        ->call('apriFornitori', 'strumento')
        ->set('nuovoFornitore.ragione_sociale', 'Abusivo srl')
        ->call('creaFornitore')
        ->assertForbidden();

    expect(Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Abusivo srl')->exists())->toBeFalse();
});

it('has no selector at all for whoever cannot see suppliers', function () {
    // Ruolo sintetico, come in `FornitoreTest`: nessun ruolo reale è oggi senza
    // `fornitori.view` avendo `strumenti.create`.
    $ruolo = Role::create(['name' => 'Senza fornitori', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(['unita_organizzativa.view', 'strumenti.view', 'strumenti.create']);

    $utente = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    $form = formNuovoStrumento($utente->fresh(), $this->reparto)->assertDontSeeHtml('data-selettore-fornitore');

    $form->call('apriFornitori', 'strumento')->assertForbidden();

    formNuovoStrumento($utente->fresh(), $this->reparto)->call('scegliFornitore', $this->alfa->id)->assertForbidden();
    formNuovoStrumento($utente->fresh(), $this->reparto)->call('togliFornitore')->assertForbidden();

    // 🔴 Le due property sono pubbliche: si scrivono dal browser senza passare
    // da `apriFornitori()`. La vista non disegna niente, ma nemmeno i dati
    // devono essere letti per chi non li può vedere.
    $forgiato = formNuovoStrumento($utente->fresh(), $this->reparto)
        ->set('selettoreFornitore', 'strumento')
        ->set('strumentoForm.fornitore_id', $this->alfa->id)
        ->assertDontSee('Alfa Strumenti');

    expect($forgiato->viewData('elencoFornitori')['righe'])->toBeEmpty()
        ->and($forgiato->viewData('fornitoriScelti'))->toBeEmpty();
});

// ─── Chi lavora nella sede di un altro ───────────────────────────────────────

it('creates the supplier in the sede of the customer when EasyLab works there, not in its own', function () {
    // 🔗 ADR-046: il Superadmin registra una macchina nella sede di un cliente
    // gestito. Il fornitore che crea da lì è dell'anagrafica del cliente.
    $easylab = Account::factory()->diPiattaforma()->create();
    $enteEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($easylab)->create(['nome' => 'EasyLab']);

    $cliente = Account::factory()->create();
    $cliente->affidaManutenzione();
    $sede = UnitaOrganizzativa::factory()->ente()->perAccount($cliente)->create(['nome' => 'Sede cliente']);
    $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($sede)->create();
    Fornitore::factory()->forTenant($sede)->create(['ragione_sociale' => 'Fornitore del cliente']);
    Fornitore::factory()->forTenant($enteEasylab)->create(['ragione_sociale' => 'Fornitore di EasyLab']);

    $superadmin = ($this->utente)('Superadmin', $enteEasylab);

    $form = formNuovoStrumento($superadmin, $reparto)
        ->call('apriFornitori', 'strumento')
        // L'elenco è quello della sede della macchina, non di chi guarda.
        ->assertSee('Fornitore del cliente')
        ->assertDontSee('Fornitore di EasyLab')
        ->set('cercaFornitore', 'Epsilon')
        ->call('apriNuovoFornitore')
        ->call('creaFornitore')
        ->assertHasNoErrors();

    $epsilon = Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Epsilon')->sole();

    expect($epsilon->tenant_id)->toBe($sede->id);
    $form->assertSet('strumentoForm.fornitore_id', $epsilon->id);
});

it('creates nothing when the supplier would be born in a sede that is not the one of the field', function () {
    // 🔴 `BelongsToTenant` riscrive il tenant di chi ha un Ente proprio. Nei
    // componenti veri la sede del campo è quella di chi lavora, o una che la
    // tenancy gli riconosce; se un giorno non lo fosse, il fornitore nascerebbe
    // nell'anagrafica sbagliata senza che nessuno se ne accorga. Qui un
    // componente di prova dichiara apposta la sede di un altro.
    $altroEnte = $this->altroEnte->id;

    $ospite = new class extends Component
    {
        use SceglieFornitore;

        public int $sedeDelCampo = 0;

        public ?int $scelto = null;

        protected function campoFornitore(string $campo): ?array
        {
            return ['tenant' => $this->sedeDelCampo, 'corrente' => null];
        }

        protected function scriviFornitore(string $campo, ?int $id): void
        {
            $this->scelto = $id;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    Livewire::actingAs($this->admin)->test($ospite::class, ['sedeDelCampo' => $altroEnte])
        ->call('apriFornitori', 'qualunque')
        ->set('nuovoFornitore.ragione_sociale', 'Fuori posto srl')
        ->call('creaFornitore')
        ->assertForbidden();

    // Né nella sede di un altro, né nella propria: la transazione è tornata indietro.
    expect(Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Fuori posto srl')->exists())->toBeFalse();
});
