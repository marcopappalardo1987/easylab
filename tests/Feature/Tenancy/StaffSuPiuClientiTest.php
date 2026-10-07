<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Fornitori\ElencoFornitori;
use App\Livewire\Interventi\Scadenzario;
use App\Livewire\Ricambi\RicercaRicambi;
use App\Livewire\Strumenti\ImportStrumenti;
use App\Models\Account;
use App\Models\Fornitore;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Chi lavora su **più clienti insieme** — il Gestore e il Superadmin sui clienti
 * gestiti (🔗 ADR-046) — passa dalle stesse pagine dei clienti.
 *
 * 🔴 **Area rossa, e di un tipo nuovo.** Fino al 6 Ott 2026 «in scope» e «del
 * mio Ente» coincidevano per chiunque potesse scrivere. Qui non coincidono più:
 * ogni pagina che sceglie un *secondo* oggetto — il reparto di destinazione, la
 * sede di un fornitore, il reparto di una riga importata — può mescolare i dati
 * di due clienti senza che nessuno scope se ne accorga. I casi sono scritti per
 * provare a farlo.
 *
 * Mondo: tre clienti. **Alfa** e **Beta** sono gestiti da EasyLab e stanno nel
 * portafoglio del Gestore; **Gamma** si gestisce da sé ed è la controprova.
 * Ognuno ha un reparto che si chiama «Ematologia», apposta.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $cliente = function (string $nome, bool $gestito): array {
        $account = Account::factory()->create(['ragione_sociale' => "{$nome} srl"]);
        if ($gestito) {
            $account->affidaManutenzione();
        }
        $sede = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => "Sede {$nome}"]);
        $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($sede)->create(['nome' => 'Ematologia']);
        $macchina = Strumento::factory()->forNode($reparto)->create(['nome' => "Contaglobuli {$nome}"]);
        $fornitore = Fornitore::factory()->create(['tenant_id' => $sede->id, 'ragione_sociale' => "Fornitore {$nome}"]);

        return compact('account', 'sede', 'reparto', 'macchina', 'fornitore');
    };

    $this->alfa = $cliente('Alfa', true);
    $this->beta = $cliente('Beta', true);
    $this->gamma = $cliente('Gamma', false);

    $this->persona = function (string $ruolo, ?UnitaOrganizzativa $ente = null, string $nome = 'Persona'): User {
        $u = User::factory()->create(['name' => $nome, 'tenant_id' => $ente?->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->gestore = ($this->persona)('Gestore', null, 'Giorgia Gestore');
    $this->gestore->portafoglioClienti()->attach([$this->alfa['sede']->id, $this->beta['sede']->id]);

    $easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $this->enteEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($easylab)->create(['nome' => 'EasyLab']);
    $this->superadmin = ($this->persona)('Superadmin', $this->enteEasylab, 'Sara Superadmin');
});

dataset('chi lavora su più clienti', [
    'Gestore' => [fn () => $this->gestore],
    'Superadmin' => [fn () => $this->superadmin],
]);

// ─── Macchine e interventi ───────────────────────────────────────────────────

it('registers a machine in a reparto of a followed client, with the tenant of that client', function (User $chi) {
    Livewire::actingAs($chi)->test(Albero::class)
        ->call('open', $this->alfa['reparto']->id)
        ->call('addStrumento')
        ->set('strumentoForm.fornitore_id', $this->alfa['fornitore']->id)
        ->set('strumentoForm.nome', 'Bilancia nuova')
        ->call('saveStrumento')
        ->assertHasNoErrors();

    $nuova = Strumento::withoutGlobalScopes()->where('nome', 'Bilancia nuova')->firstOrFail();

    // 🔴 Il `tenant_id` è quello del cliente e non quello di chi scrive: per il
    // Superadmin, che un Ente ce l'ha, è la riga che `BelongsToTenant` forzava.
    expect($nuova->tenant_id)->toBe($this->alfa['sede']->id)
        ->and($nuova->unita_organizzativa_id)->toBe($this->alfa['reparto']->id);
})->with('chi lavora su più clienti');

it('never accepts on a machine the supplier of another client', function (User $chi) {
    // Il catalogo fornitori è per sede: quello di Beta è in vista, ma non è di Alfa.
    Livewire::actingAs($chi)->test(Albero::class)
        ->call('open', $this->alfa['reparto']->id)
        ->call('addStrumento')
        ->set('strumentoForm.fornitore_id', $this->beta['fornitore']->id)
        ->set('strumentoForm.nome', 'Bilancia sbagliata')
        ->call('saveStrumento')
        ->assertHasErrors('strumentoForm.fornitore_id');

    expect(Strumento::withoutGlobalScopes()->where('nome', 'Bilancia sbagliata')->exists())->toBeFalse();
})->with('chi lavora su più clienti');

it('plans, assigns and closes an intervento on a followed client', function (User $chi) {
    $tecnico = ($this->persona)('Tecnico', null, 'Tito Tecnico');
    $tecnico->portafoglioClienti()->attach($this->alfa['sede']->id);

    scheda($chi, $this->alfa['macchina'])
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Taratura annuale')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addMonth()->toDateString())
        ->set('interventoForm.tecnico_id', $tecnico->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    $intervento = Intervento::withoutGlobalScopes()->where('descrizione', 'Taratura annuale')->firstOrFail();

    expect($intervento->tenant_id)->toBe($this->alfa['sede']->id)
        ->and($intervento->tecnico_id)->toBe($tecnico->id);

    scheda($chi, $this->alfa['macchina'])
        ->call('openCompleta', $intervento->id)
        ->set('dataEsecuzione', today()->toDateString())
        ->call('completa')
        ->assertHasNoErrors();

    expect($intervento->fresh()->data_esecuzione)->not->toBeNull();
})->with('chi lavora su più clienti');

it('never assigns an intervento to a tecnico who does not work on that client', function (User $chi) {
    // Il tecnico lavora su Beta: sulla macchina di Alfa non è assegnabile, anche
    // se chi assegna vede entrambi i clienti.
    $tecnico = ($this->persona)('Tecnico', null, 'Tito Tecnico');
    $tecnico->portafoglioClienti()->attach($this->beta['sede']->id);

    scheda($chi, $this->alfa['macchina'])
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Assegnazione sbagliata')
        ->set('interventoForm.tipo', 'manutenzione_ordinaria')
        ->set('interventoForm.data_scadenza', today()->addMonth()->toDateString())
        ->set('interventoForm.tecnico_id', $tecnico->id)
        ->call('saveIntervento')
        ->assertHasErrors('interventoForm.tecnico_id');

    expect(Intervento::withoutGlobalScopes()->where('descrizione', 'Assegnazione sbagliata')->exists())->toBeFalse();
})->with('chi lavora su più clienti');

it('never opens the machine card of a client that is not followed', function (User $chi) {
    $this->actingAs($chi)
        ->get(route('strumenti.show', $this->gamma['macchina']))
        ->assertNotFound();
})->with('chi lavora su più clienti');

it('never opens a client to a gestore just because easylab manages it', function () {
    // 🔴 Il segno sul cliente apre al Superadmin. Al Gestore apre il portafoglio,
    // e basta: Beta esce dal portafoglio e sparisce, gestito o no.
    $this->gestore->portafoglioClienti()->detach($this->beta['sede']->id);

    $this->actingAs($this->gestore)
        ->get(route('strumenti.show', $this->beta['macchina']))
        ->assertNotFound();

    $this->actingAs($this->gestore)
        ->get(route('strumenti.show', $this->alfa['macchina']))
        ->assertOk();
});

// ─── Lo spostamento: dentro lo stesso cliente, mai fra due ──────────────────

it('never moves a machine into a reparto of another client', function (User $chi) {
    // 🔴 Il reparto di Beta è in scope — chi guarda segue entrambi — quindi lo
    // scope non lo ferma. Senza la guardia la macchina di Alfa finirebbe in un
    // reparto di Beta col `tenant_id` di Alfa: persa per tutti e due.
    try {
        scheda($chi, $this->alfa['macchina'])
            ->call('openMove')
            ->set('destinazioneId', $this->beta['reparto']->id)
            ->set('dataSpostamento', today()->toDateString())
            ->call('move')
            ->assertNotFound();
    } catch (NotFoundHttpException) {
        // Stessa cosa, detta dall'eccezione.
    }

    expect($this->alfa['macchina']->fresh()->unita_organizzativa_id)->toBe($this->alfa['reparto']->id)
        ->and(SpostamentoStrumento::withoutGlobalScopes()->count())->toBe(0);
})->with('chi lavora su più clienti');

it('offers as destinations only the reparti of the client the machine belongs to', function (User $chi) {
    $altro = UnitaOrganizzativa::factory()->dipartimento()->under($this->alfa['sede'])->create(['nome' => 'Chimica clinica']);

    $destinazioni = scheda($chi, $this->alfa['macchina'])->viewData('nodiDestinazione');

    expect($destinazioni->pluck('id')->all())->toBe([$altro->id]);
})->with('chi lavora su più clienti');

it('still moves a machine inside the same client', function (User $chi) {
    $altro = UnitaOrganizzativa::factory()->dipartimento()->under($this->alfa['sede'])->create(['nome' => 'Chimica clinica']);

    scheda($chi, $this->alfa['macchina'])
        ->call('openMove')
        ->set('destinazioneId', $altro->id)
        ->set('dataSpostamento', today()->toDateString())
        ->call('move')
        ->assertHasNoErrors();

    $movimento = SpostamentoStrumento::withoutGlobalScopes()->firstOrFail();

    expect($this->alfa['macchina']->fresh()->unita_organizzativa_id)->toBe($altro->id)
        ->and($movimento->tenant_id)->toBe($this->alfa['sede']->id)
        ->and($movimento->eseguito_da)->toBe($chi->id);
})->with('chi lavora su più clienti');

// ─── L'albero: reparti sì, eliminarli no, il nodo Ente mai ───────────────────

it('lets a gestore create and rename a reparto of a followed client', function () {
    Livewire::actingAs($this->gestore)->test(Albero::class)
        ->call('addChild', $this->alfa['sede']->id)
        ->set('nome', 'Microbiologia')
        ->call('save')
        ->assertHasNoErrors();

    $nuovo = UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Microbiologia')->firstOrFail();

    expect($nuovo->tenant_id)->toBe($this->alfa['sede']->id)
        ->and($nuovo->parent_id)->toBe($this->alfa['sede']->id);

    Livewire::actingAs($this->gestore)->test(Albero::class)
        ->call('edit', $nuovo->id)
        ->set('nome', 'Microbiologia e virologia')
        ->call('save')
        ->assertHasNoErrors();

    expect($nuovo->fresh()->nome)->toBe('Microbiologia e virologia');
});

it('never lets a gestore delete a reparto', function () {
    // Decisione di Marco, 6 Ott 2026: si creano e si rinominano, non si eliminano.
    $vuoto = UnitaOrganizzativa::factory()->dipartimento()->under($this->alfa['sede'])->create(['nome' => 'Vuoto']);

    Livewire::actingAs($this->gestore)->test(Albero::class)
        ->call('confirmDelete', $vuoto->id)
        ->assertForbidden();

    Livewire::actingAs($this->gestore)->test(Albero::class)
        ->set('deletingId', $vuoto->id)
        ->call('delete')
        ->assertForbidden();

    expect($vuoto->fresh()->trashed())->toBeFalse();
});

it('never lets a gestore touch the ente node of a client', function () {
    // Il permesso è lo stesso che rinomina un reparto: a fare la differenza è
    // il nodo. Il nome della sede e la soglia di obsolescenza restano al
    // cliente e al Superadmin.
    Livewire::actingAs($this->gestore)->test(Albero::class)
        ->call('edit', $this->alfa['sede']->id)
        ->assertForbidden();

    // La seconda strada: le property sono pubbliche, il form si apparecchia a mano.
    Livewire::actingAs($this->gestore)->test(Albero::class)
        ->set('editingId', $this->alfa['sede']->id)
        ->set('nome', 'Rinominata dal gestore')
        ->set('sogliaObsolescenzaAnni', 3)
        ->call('save')
        ->assertForbidden();

    expect($this->alfa['sede']->fresh()->nome)->toBe('Sede Alfa');
});

it('does not offer a gestore the rename of an ente, nor the email brand of a client', function () {
    $pagina = Livewire::actingAs($this->gestore)->test(Albero::class)->call('open', $this->alfa['sede']->id);

    $pagina->assertDontSeeHtml('wire:click="edit('.$this->alfa['sede']->id.')"')
        ->assertDontSee('Marchio email')
        ->assertDontSee('Aggiungi una sede');
});

it('lets the superadmin rename the ente of a managed client, but offers the email brand on the own ente only', function () {
    Livewire::actingAs($this->superadmin)->test(Albero::class)
        ->call('edit', $this->alfa['sede']->id)
        ->set('nome', 'Sede Alfa centro')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->alfa['sede']->fresh()->nome)->toBe('Sede Alfa centro');

    // La pagina del marchio lavora sull'Ente di chi guarda: offerta sul nodo di
    // un cliente aprirebbe quello di EasyLab.
    Livewire::actingAs($this->superadmin)->test(Albero::class)
        ->call('open', $this->alfa['sede']->id)->assertDontSee('Marchio email');

    Livewire::actingAs($this->superadmin)->test(Albero::class)
        ->call('open', $this->enteEasylab->id)->assertSee('Marchio email');
});

it('never creates a reparto under a client that is not followed', function (User $chi) {
    try {
        Livewire::actingAs($chi)->test(Albero::class)
            ->call('addChild', $this->gamma['sede']->id)
            ->assertNotFound();
    } catch (ModelNotFoundException) {
        // Stessa cosa, detta dall'eccezione.
    }

    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('tenant_id', $this->gamma['sede']->id)->count())->toBe(2);
})->with('chi lavora su più clienti');

// ─── I fornitori: il catalogo è per sede, e va detta ─────────────────────────

it('asks which sede a new supplier is for, and writes it there', function (User $chi) {
    Livewire::actingAs($chi)->test(ElencoFornitori::class)
        ->call('nuovo')
        ->set('form.ragione_sociale', 'Senza sede')
        ->call('save')
        ->assertHasErrors('sedeId');

    expect(Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Senza sede')->exists())->toBeFalse();

    Livewire::actingAs($chi)->test(ElencoFornitori::class)
        ->call('nuovo')
        ->set('form.ragione_sociale', 'Reagenti Beta')
        ->set('sedeId', $this->beta['sede']->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Reagenti Beta')->value('tenant_id'))
        ->toBe($this->beta['sede']->id);
})->with('chi lavora su più clienti');

it('refuses a supplier for a sede that is not followed, even when the id is forged', function (User $chi) {
    Livewire::actingAs($chi)->test(ElencoFornitori::class)
        ->call('nuovo')
        ->set('form.ragione_sociale', 'Intruso')
        ->set('sedeId', $this->gamma['sede']->id)
        ->call('save')
        ->assertHasErrors('sedeId');

    expect(Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Intruso')->exists())->toBeFalse();
})->with('chi lavora su più clienti');

it('edits a supplier of a followed client without asking for a sede again', function (User $chi) {
    // Un fornitore non cambia sede: in modifica la tendina non c'è, e la sua
    // regola non deve bloccare il salvataggio.
    Livewire::actingAs($chi)->test(ElencoFornitori::class)
        ->call('edit', $this->alfa['fornitore']->id)
        ->assertDontSee('Scegli la sede')
        ->set('form.ragione_sociale', 'Fornitore Alfa rinominato')
        ->call('save')
        ->assertHasNoErrors();

    $dopo = Fornitore::withoutGlobalScopes()->findOrFail($this->alfa['fornitore']->id);

    expect($dopo->ragione_sociale)->toBe('Fornitore Alfa rinominato')
        ->and($dopo->tenant_id)->toBe($this->alfa['sede']->id);
})->with('chi lavora su più clienti');

it('writes the supplier in the only sede of a gestore who follows one, without asking', function () {
    $this->gestore->portafoglioClienti()->detach($this->beta['sede']->id);

    Livewire::actingAs($this->gestore)->test(ElencoFornitori::class)
        ->call('nuovo')
        ->assertDontSee('Scegli la sede')
        ->set('form.ragione_sociale', 'Unico')
        ->call('save')
        ->assertHasNoErrors();

    expect(Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Unico')->value('tenant_id'))
        ->toBe($this->alfa['sede']->id);
});

it('refuses a supplier from a gestore who follows no sede at all', function () {
    $this->gestore->portafoglioClienti()->detach();

    Livewire::actingAs($this->gestore)->test(ElencoFornitori::class)
        ->call('nuovo')
        ->set('form.ragione_sociale', 'Di nessuno')
        ->call('save')
        ->assertForbidden();

    expect(Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Di nessuno')->exists())->toBeFalse();
});

it('keeps writing the supplier of a client admin in their own ente, whatever sede they send', function () {
    // La controprova: per chi ha un Ente solo la tendina non esiste, e un
    // `sedeId` forgiato non ha dove attaccarsi.
    $admin = ($this->persona)('Admin', $this->gamma['sede']);

    Livewire::actingAs($admin)->test(ElencoFornitori::class)
        ->call('nuovo')
        ->assertDontSee('Scegli la sede')
        ->set('form.ragione_sociale', 'Del cliente')
        ->set('sedeId', $this->alfa['sede']->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Fornitore::withoutGlobalScopes()->where('ragione_sociale', 'Del cliente')->value('tenant_id'))
        ->toBe($this->gamma['sede']->id);
});

it('says of which client each supplier is, to whoever follows more than one', function () {
    $html = Livewire::actingAs($this->gestore)->test(ElencoFornitori::class)->html();

    expect($html)->toContain('data-sede-fornitore="'.$this->alfa['fornitore']->id.'">Alfa srl — Sede Alfa<')
        ->and($html)->toContain('data-sede-fornitore="'.$this->beta['fornitore']->id.'">Beta srl — Sede Beta<')
        ->and($html)->not->toContain('Fornitore Gamma');

    $admin = ($this->persona)('Admin', $this->gamma['sede']);

    expect(Livewire::actingAs($admin)->test(ElencoFornitori::class)->html())->not->toContain('data-sede-fornitore');
});

// ─── L'import: una sede alla volta ───────────────────────────────────────────

it('imports into the chosen sede, even when two clients have a reparto with the same name', function (User $chi) {
    $csv = "nome;modello;matricola;data_installazione;ubicazione;provenienza\nCentrifuga importata;;;;Ematologia;\n";

    // Senza sede non si analizza: «Ematologia» esiste in due clienti.
    Livewire::actingAs($chi)->test(ImportStrumenti::class)
        ->set('file', UploadedFile::fake()->createWithContent('strumenti.csv', $csv))
        ->call('analizza')
        ->assertHasErrors('sedeId')
        ->assertSet('analizzato', false);

    Livewire::actingAs($chi)->test(ImportStrumenti::class)
        ->set('file', UploadedFile::fake()->createWithContent('strumenti.csv', $csv))
        ->set('sedeId', $this->beta['sede']->id)
        ->call('analizza')
        ->assertHasNoErrors()
        ->call('importa');

    $importata = Strumento::withoutGlobalScopes()->where('nome', 'Centrifuga importata')->firstOrFail();

    expect($importata->tenant_id)->toBe($this->beta['sede']->id)
        ->and($importata->unita_organizzativa_id)->toBe($this->beta['reparto']->id);
})->with('chi lavora su più clienti');

it('resolves an explicit path inside the chosen sede, not in the last client read', function (User $chi) {
    // 🔴 Il percorso esplicito era una mappa `percorso → nodo`: con due clienti
    // in vista l'ultimo sovrascriveva il primo, in silenzio.
    foreach ([$this->alfa, $this->beta] as $cliente) {
        UnitaOrganizzativa::factory()->sottolaboratorio()->under($cliente['reparto'])->create(['nome' => 'Coagulazione']);
    }

    $csv = "nome;modello;matricola;data_installazione;ubicazione;provenienza\nCoagulometro;;;;Ematologia > Coagulazione;\n";

    Livewire::actingAs($chi)->test(ImportStrumenti::class)
        ->set('file', UploadedFile::fake()->createWithContent('strumenti.csv', $csv))
        ->set('sedeId', $this->alfa['sede']->id)
        ->call('analizza')
        ->assertHasNoErrors()
        ->call('importa');

    expect(Strumento::withoutGlobalScopes()->where('nome', 'Coagulometro')->value('tenant_id'))
        ->toBe($this->alfa['sede']->id);
})->with('chi lavora su più clienti');

it('refuses to import into a sede that is not followed', function (User $chi) {
    $csv = "nome;modello;matricola;data_installazione;ubicazione;provenienza\nIntrusa;;;;Ematologia;\n";

    Livewire::actingAs($chi)->test(ImportStrumenti::class)
        ->set('file', UploadedFile::fake()->createWithContent('strumenti.csv', $csv))
        ->set('sedeId', $this->gamma['sede']->id)
        ->call('analizza')
        ->assertHasErrors('sedeId');

    expect(Strumento::withoutGlobalScopes()->where('nome', 'Intrusa')->exists())->toBeFalse();
})->with('chi lavora su più clienti');

// ─── Il cliente legge chi ha lavorato per lui ────────────────────────────────

it('shows the client the real name of the easylab person who worked on the machine', function (User $chi) {
    scheda($chi, $this->alfa['macchina'])
        ->call('openForza')
        ->set('forzaForm.stato', 'rosso')
        ->set('forzaForm.motivo', 'Guasto alla pompa')
        ->call('forza')
        ->assertHasNoErrors();

    $admin = ($this->persona)('Admin', $this->alfa['sede']);

    $this->actingAs($admin)
        ->get(route('strumenti.show', $this->alfa['macchina']))
        ->assertOk()
        ->assertSee($chi->name);
})->with('chi lavora su più clienti');

it('shows the client the name of the gestore who took an intervento on', function () {
    Intervento::factory()->forStrumento($this->alfa['macchina'])->create([
        'descrizione' => 'Sostituzione filtro',
        'tecnico_id' => $this->gestore->id,
    ]);

    $admin = ($this->persona)('Admin', $this->alfa['sede']);

    $this->actingAs($admin)
        ->get(route('strumenti.show', $this->alfa['macchina']))
        ->assertOk()
        ->assertSee('Giorgia Gestore');
});

// ─── Lo scadenzario: tutti i clienti insieme, o uno alla volta ───────────────

it('shows the open interventi of every followed client, and narrows to one sede on request', function (User $chi) {
    Intervento::factory()->forStrumento($this->alfa['macchina'])->create(['descrizione' => 'Lavoro Alfa']);
    Intervento::factory()->forStrumento($this->beta['macchina'])->create(['descrizione' => 'Lavoro Beta']);
    Intervento::factory()->forStrumento($this->gamma['macchina'])->create(['descrizione' => 'Lavoro Gamma']);

    $pagina = Livewire::actingAs($chi)->test(Scadenzario::class);

    expect($pagina->viewData('interventi')->pluck('descrizione')->all())
        ->toEqualCanonicalizing(['Lavoro Alfa', 'Lavoro Beta'])
        ->and($pagina->viewData('sedi')->pluck('id')->all())->toContain($this->alfa['sede']->id, $this->beta['sede']->id)
        ->and($pagina->instance()->haFiltriAttivi())->toBeFalse();

    $pagina->assertSee('Tutte le sedi')->assertSee('Beta srl — Sede Beta');

    $pagina->set('sede', $this->beta['sede']->id);

    // I contatori seguono il filtro: sono l'indice della stessa lista.
    expect($pagina->viewData('interventi')->pluck('descrizione')->all())->toBe(['Lavoro Beta'])
        ->and(array_sum($pagina->viewData('contatori')))->toBe(1)
        ->and($pagina->instance()->haFiltriAttivi())->toBeTrue();
})->with('chi lavora su più clienti');

it('never uses the sede filter to reach a client that is not followed', function (User $chi) {
    Intervento::factory()->forStrumento($this->alfa['macchina'])->create(['descrizione' => 'Lavoro Alfa']);
    Intervento::factory()->forStrumento($this->gamma['macchina'])->create(['descrizione' => 'Lavoro Gamma']);

    $pagina = Livewire::actingAs($chi)->withQueryParams(['sede' => $this->gamma['sede']->id])
        ->test(Scadenzario::class);

    // Un valore non valido non filtra e non si annuncia: l'elenco resta intero.
    expect($pagina->viewData('interventi')->pluck('descrizione')->all())->toBe(['Lavoro Alfa'])
        ->and($pagina->instance()->haFiltriAttivi())->toBeFalse();
})->with('chi lavora su più clienti');

it('ignores a sede filter that is not a number, instead of failing or guessing', function () {
    // ⚠️ `(int) ['1', '2']` in PHP vale 1, cioè l'id di una sede vera: senza
    // `is_numeric` un array in query string diventerebbe un filtro applicato a
    // caso. Su SQLite la sede Alfa ha id 1, ed è lì che la mutazione si vede;
    // su Postgres le sequenze non si azzerano fra un test e l'altro, quindi
    // l'id non si asserisce — il comportamento atteso è lo stesso.
    Intervento::factory()->forStrumento($this->alfa['macchina'])->create(['descrizione' => 'Lavoro Alfa']);
    Intervento::factory()->forStrumento($this->beta['macchina'])->create(['descrizione' => 'Lavoro Beta']);

    $pagina = Livewire::actingAs($this->gestore)->withQueryParams(['sede' => ['1', '2']])
        ->test(Scadenzario::class)
        ->assertOk();

    expect($pagina->viewData('interventi')->pluck('descrizione')->all())
        ->toEqualCanonicalizing(['Lavoro Alfa', 'Lavoro Beta']);
});

it('offers no sede filter to a gestore who follows a single sede', function () {
    // Una tendina con una voce sola è rumore, e «Tutte le sedi» direbbe il falso.
    $this->gestore->portafoglioClienti()->detach($this->beta['sede']->id);

    $pagina = Livewire::actingAs($this->gestore)->test(Scadenzario::class);

    $pagina->assertDontSee('Tutte le sedi');

    expect($pagina->viewData('sedi'))->toHaveCount(0);
});

it('offers no sede filter to whoever works on a single sede', function () {
    $admin = ($this->persona)('Admin', $this->alfa['sede']);
    Intervento::factory()->forStrumento($this->alfa['macchina'])->create(['descrizione' => 'Lavoro Alfa']);

    $pagina = Livewire::actingAs($admin)->withQueryParams(['sede' => $this->beta['sede']->id])
        ->test(Scadenzario::class);

    $pagina->assertDontSee('Tutte le sedi');

    expect($pagina->viewData('sedi'))->toHaveCount(0)
        ->and($pagina->viewData('interventi')->pluck('descrizione')->all())->toBe(['Lavoro Alfa'])
        ->and($pagina->instance()->haFiltriAttivi())->toBeFalse();
});

// ─── Il catalogo ricambi: uno per sede, anche quando se ne vedono due ────────

it('never merges two parts that belong to the catalogues of two clients', function () {
    // Stesso nome in due cataloghi: per chi li vede entrambi sembrano doppioni.
    $diAlfa = Ricambio::factory()->forTenant($this->alfa['sede'])->create(['nome' => 'Guarnizione']);
    $diBeta = Ricambio::factory()->forTenant($this->beta['sede'])->create(['nome' => 'Guarnizione grande']);
    RicambioUtilizzo::factory()->forStrumento($this->alfa['macchina'])->forRicambio($diAlfa)->create();
    RicambioUtilizzo::factory()->forStrumento($this->beta['macchina'])->forRicambio($diBeta)->create();

    $pagina = Livewire::actingAs($this->superadmin)->test(RicercaRicambi::class)
        ->set('search', 'Guarnizione')
        ->call('apriUnione', $diAlfa->id);

    // La modale non offre la voce dell'altro cliente come destinazione…
    $pagina->assertDontSeeHtml('<option value="'.$diBeta->id.'"');

    // …e l'azione la rifiuta anche se l'id viene forzato, con una frase e non
    // con una pagina di errore.
    $pagina->set('destinazioneId', $diBeta->id)
        ->call('unisci')
        ->assertHasErrors('destinazioneId');

    expect($diAlfa->fresh()->trashed())->toBeFalse()
        ->and(RicambioUtilizzo::withoutGlobalScopes()->where('ricambio_id', $diAlfa->id)->count())->toBe(1);
});

it('still merges two parts of the same client, for whoever follows several', function () {
    $tenere = Ricambio::factory()->forTenant($this->alfa['sede'])->create(['nome' => 'Guarnizione']);
    $doppione = Ricambio::factory()->forTenant($this->alfa['sede'])->create(['nome' => 'Guarnizione bis']);
    RicambioUtilizzo::factory()->forStrumento($this->alfa['macchina'])->forRicambio($tenere)->create();
    RicambioUtilizzo::factory()->forStrumento($this->alfa['macchina'])->forRicambio($doppione)->create();

    Livewire::actingAs($this->superadmin)->test(RicercaRicambi::class)
        ->set('search', 'Guarnizione')
        ->call('apriUnione', $doppione->id)
        ->assertSeeHtml('<option value="'.$tenere->id.'"')
        ->set('destinazioneId', $tenere->id)
        ->call('unisci')
        ->assertHasNoErrors();

    expect(RicambioUtilizzo::withoutGlobalScopes()->where('ricambio_id', $tenere->id)->count())->toBe(2);
});
