<?php

use App\Enums\TipoUnitaOrganizzativa;
use App\Livewire\Anagrafica\MarchioEnte;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Mail\MarchioEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * 🔴 La pagina del **marchio email** dell'Ente (🔗 ADR-011, ADR-018, ADR-033) —
 * area rossa della Policy di Code Review su tre fronti insieme:
 * **autorizzazioni**, **tenancy** e **upload**.
 *
 * ## Perché ogni negativo esiste in DUE forme: la rotta e l'azione
 *
 * ⛔ Il `can:unita_organizzativa.update` della rotta copre la sola `GET`. Le
 * azioni Livewire arrivano su `/livewire/update`, che quel middleware non
 * attraversa: chi conosce il nome del componente può chiamare `salva()`
 * direttamente. Un test che si fermasse al 403 sulla pagina proverebbe metà
 * della guardia e lascerebbe l'altra metà libera di sparire — ed è il difetto
 * già trovato tre volte su questo progetto.
 *
 * ## E perché c'è un test sullo snapshot
 *
 * 🔴 Un `public UnitaOrganizzativa $ente` sarebbe **reidratato senza global
 * scope** (`ModelSynth::hydrate()` → `newQueryWithoutScopes()`), quindi il
 * legame Ente↔tenant verificato al `mount()` non varrebbe più per le azioni. È
 * stato riprodotto prima di essere corretto: un Admin dell'Ente B, con lo
 * snapshot dell'Ente A rimasto in una scheda aperta, ne ha riscritto il colore.
 * Il test «never writes into the Ente of a stale snapshot» è quella
 * riproduzione, e va letto come il cuore di questo file.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(MarchioEmail::DISCO);
});

/** Un Ente con il suo Admin (2FA confermata: `two-factor.enforce` la esige). */
function enteConAdmin(string $nome = 'Laboratorio Rossi'): array
{
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => $nome]);
    $admin = User::factory()->create([
        'tenant_id' => $ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('Admin');

    return [$ente, $admin];
}

/** L'Ente riletto **fuori** da ogni scope: è ciò che è finito davvero a database. */
function enteACrudo(int $id): UnitaOrganizzativa
{
    return UnitaOrganizzativa::withoutGlobalScopes()->findOrFail($id);
}

// --- Accesso alla ROTTA -----------------------------------------------------

it('redirects guests to login', function () {
    $this->get(route('anagrafica.marchio'))->assertRedirect(route('login'));
});

it('forbids the roles that may only view the anagrafica', function (string $ruolo) {
    // 🔴 Responsabile Reparto, Tenant e Tecnico hanno `unita_organizzativa.view`
    // e NON `.update`: il marchio è l'identità dell'Ente verso l'esterno, e la
    // decide chi può rinominarlo.
    [$ente] = enteConAdmin();

    $utente = User::factory()->create(['tenant_id' => $ente->id]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente)->get(route('anagrafica.marchio'))->assertForbidden();
})->with(['Responsabile Reparto', 'Tenant', 'Tecnico']);

it('lets an admin open the page', function () {
    [$ente, $admin] = enteConAdmin();

    $this->actingAs($admin)->get(route('anagrafica.marchio'))
        ->assertOk()
        ->assertSee('Laboratorio Rossi')
        ->assertSee('Marchio email');

    expect($ente->id)->toBeInt();
});

// --- 🔴 Il negativo sull'AZIONE, che la rotta non copre ---------------------

it('forbids the write actions themselves, not only the route', function (string $azione) {
    // 🔴 Nessun ruolo, quindi nessun permesso — ma il `tenant_id` c'è, quindi
    // `mount()` monta: è precisamente lo stato in cui una chiamata diretta a
    // `/livewire/update` arriverebbe all'azione senza passare dal `can:` della
    // rotta. Se `Gate::authorize()` sparisse dall'azione, questa sarebbe l'unica
    // prova a diventare rossa.
    [$ente] = enteConAdmin();
    $ente->fissaMarchioEmail('marchi/'.$ente->id.'/vecchio.png', '#111111');

    $estraneo = User::factory()->create(['tenant_id' => $ente->id]);

    Livewire::actingAs($estraneo)->test(MarchioEnte::class)
        ->set('colore', '#ff0000')
        ->call($azione)
        ->assertForbidden();

    // ⚠️ E il database fermo, che è la metà che `assertForbidden()` non prova:
    // un 403 alzato DOPO la scrittura sarebbe verde lo stesso.
    expect(enteACrudo($ente->id)->marchio_colore)->toBe('#111111');
    expect(enteACrudo($ente->id)->marchio_logo_path)->toBe('marchi/'.$ente->id.'/vecchio.png');
})->with(['salva', 'rimuoviLogo']);

// --- 🔴 Fail-closed ADR-018 -------------------------------------------------

it('fails closed for an authenticated user without a tenant', function () {
    // ADR-018: l'assenza di contesto è una negazione, non un permesso. Un
    // Superadmin senza `tenant_id` non deve «vedere il primo Ente che capita».
    enteConAdmin();

    $senzaTenant = User::factory()->create([
        'tenant_id' => null,
        'two_factor_confirmed_at' => now(),
    ]);
    $senzaTenant->assignRole('Superadmin');

    $this->actingAs($senzaTenant)->get(route('anagrafica.marchio'))->assertForbidden();
});

it('fails closed on the action too when the tenant is taken away mid-session', function () {
    // ADR-030: la conversione a tecnico esterno azzera il `tenant_id`. Una
    // scheda già aperta non deve continuare a scrivere sull'Ente di prima —
    // ed è ciò che accadeva finché l'Ente viveva nello snapshot.
    [$ente, $admin] = enteConAdmin();
    $ente->fissaMarchioEmail(null, '#111111');

    $componente = Livewire::actingAs($admin)->test(MarchioEnte::class)->set('colore', '#ff0000');

    $admin->forceFill(['tenant_id' => null])->saveQuietly();
    $senzaTenant = $admin->fresh();
    $this->actingAs($senzaTenant);
    Livewire::actingAs($senzaTenant);

    $componente->call('salva')->assertForbidden();

    expect(enteACrudo($ente->id)->marchio_colore)->toBe('#111111');
});

it('never writes into the Ente of a stale snapshot', function () {
    // 🔴 **Il test rosso principale.** Due Enti, due Admin. A monta la pagina;
    // il contesto cambia (uscita da un'impersonazione legittima, ADR-018) e a
    // premere Salva è B. L'Ente di A non deve muoversi di un byte.
    [$enteA, $adminA] = enteConAdmin('Laboratorio Rossi');
    [$enteB, $adminB] = enteConAdmin('Clinica Aurora');

    $enteA->fissaMarchioEmail(null, '#111111');

    $componente = Livewire::actingAs($adminA)->test(MarchioEnte::class);

    $this->actingAs($adminB);
    Livewire::actingAs($adminB);

    $componente->set('colore', '#ff0000')->call('salva');

    // 🔴 L'Ente di A è intatto: nessuna scrittura cross-tenant.
    expect(enteACrudo($enteA->id)->marchio_colore)->toBe('#111111');
    // E il positivo di controllo: la scrittura è finita dove doveva, cioè
    // sull'Ente di chi ha premuto il pulsante.
    expect(enteACrudo($enteB->id)->marchio_colore)->toBe('#ff0000');
});

it('never lets an upload land in the folder of another Ente', function () {
    // 🔴 Il percorso non arriva mai dal client: si costruisce con l'id
    // dell'Ente risolto dal `tenant_id`, e il nome è un ULID.
    [$enteA, $adminA] = enteConAdmin('Laboratorio Rossi');
    [$enteB] = enteConAdmin('Clinica Aurora');

    Livewire::actingAs($adminA)->test(MarchioEnte::class)
        ->set('logo', UploadedFile::fake()->image('marchio.png', 400, 120))
        ->call('salva')
        ->assertHasNoErrors();

    $path = enteACrudo($enteA->id)->marchio_logo_path;

    expect($path)->toStartWith('marchi/'.$enteA->id.'/');
    expect($path)->not->toContain('marchi/'.$enteB->id.'/');
    expect($path)->not->toContain('marchio.png');
    Storage::disk(MarchioEmail::DISCO)->assertExists($path);
});

// --- La validazione dell'upload --------------------------------------------

it('rejects an SVG, which two client families would not render anyway', function () {
    [$ente, $admin] = enteConAdmin();

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('logo', UploadedFile::fake()->create('marchio.svg', 4, 'image/svg+xml'))
        ->call('salva')
        ->assertHasErrors('logo');

    expect(enteACrudo($ente->id)->marchio_logo_path)->toBeNull();
});

it('rejects a logo heavier than 512 KB', function () {
    [$ente, $admin] = enteConAdmin();

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('logo', UploadedFile::fake()->image('grosso.png', 400, 120)->size(600))
        ->call('salva')
        ->assertHasErrors(['logo' => 'max']);

    expect(enteACrudo($ente->id)->marchio_logo_path)->toBeNull();
});

it('rejects a logo larger than the header can show', function () {
    [$ente, $admin] = enteConAdmin();

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('logo', UploadedFile::fake()->image('enorme.png', 2000, 800))
        ->call('salva')
        ->assertHasErrors(['logo' => 'dimensions']);

    expect(enteACrudo($ente->id)->marchio_logo_path)->toBeNull();
});

it('rejects a colour that is not a six digit hexadecimal', function (string $sbagliato) {
    [$ente, $admin] = enteConAdmin();

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('colore', $sbagliato)
        ->call('salva')
        ->assertHasErrors(['colore' => 'regex']);

    expect(enteACrudo($ente->id)->marchio_colore)->toBeNull();
})->with(['#12345', 'rosso', '#12345g', 'javascript:alert(1)', '#06589c;background:url(x)']);

// --- Il salvataggio, e le due azioni che si parlano -------------------------

it('saves colour and logo together and replaces the previous file', function () {
    [$ente, $admin] = enteConAdmin();

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('colore', '#ffff00')
        ->set('logo', UploadedFile::fake()->image('primo.png', 400, 120))
        ->call('salva')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Marchio aggiornato.');

    $primo = enteACrudo($ente->id)->marchio_logo_path;
    expect(enteACrudo($ente->id)->marchio_colore)->toBe('#ffff00');

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('logo', UploadedFile::fake()->image('secondo.png', 400, 120))
        ->call('salva')
        ->assertHasNoErrors();

    $secondo = enteACrudo($ente->id)->marchio_logo_path;

    expect($secondo)->not->toBe($primo);
    Storage::disk(MarchioEmail::DISCO)->assertExists($secondo);
    // Il file sostituito se ne va: il disco privato non è un archivio di scarti.
    Storage::disk(MarchioEmail::DISCO)->assertMissing($primo);
});

it('empties the colour back to the Easy Lab blue instead of storing an empty string', function () {
    [$ente, $admin] = enteConAdmin();
    $ente->fissaMarchioEmail(null, '#ffff00');

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('colore', '')
        ->call('salva')
        ->assertHasNoErrors();

    expect(enteACrudo($ente->id)->marchio_colore)->toBeNull();
    expect(MarchioEmail::perEnte($ente->id)->colore)->toBe(MarchioEmail::COLORE_EASYLAB);
});

it('saves the pending colour when the logo is removed, instead of discarding it', function () {
    // Il campo del colore è `wire:model.live`: si aggiorna a ogni battuta senza
    // salvare. `rimuoviLogo()` scriveva il valore GIÀ a database, quindi buttava
    // via la modifica in sospeso e mostrava comunque un avviso di riuscita —
    // l'utente lasciava la pagina convinto di aver salvato entrambe le cose e le
    // email continuavano a uscire del colore vecchio.
    [$ente, $admin] = enteConAdmin();
    $ente->fissaMarchioEmail('marchi/'.$ente->id.'/vecchio.png', '#06589c');
    Storage::disk(MarchioEmail::DISCO)->put('marchi/'.$ente->id.'/vecchio.png', 'PNG');

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('colore', '#ff0000')
        ->call('rimuoviLogo')
        ->assertHasNoErrors();

    expect(enteACrudo($ente->id)->marchio_logo_path)->toBeNull();
    expect(enteACrudo($ente->id)->marchio_colore)->toBe('#ff0000');
    Storage::disk(MarchioEmail::DISCO)->assertMissing('marchi/'.$ente->id.'/vecchio.png');
});

it('refuses to remove the logo while the pending colour is malformed', function () {
    [$ente, $admin] = enteConAdmin();
    $ente->fissaMarchioEmail('marchi/'.$ente->id.'/vecchio.png', '#06589c');

    Livewire::actingAs($admin)->test(MarchioEnte::class)
        ->set('colore', '#12345')
        ->call('rimuoviLogo')
        ->assertHasErrors(['colore' => 'regex']);

    expect(enteACrudo($ente->id)->marchio_logo_path)->toBe('marchi/'.$ente->id.'/vecchio.png');
});

// --- L'anteprima, che è la ragione per cui la pagina esiste -----------------

it('shows the stored logo in the header preview, not a placeholder', function () {
    // La specifica chiedeva «un'anteprima statica del blocco testata (logo +
    // nome + filetto colorato), così il colore si giudica dove verrà visto». Un
    // segnaposto testuale non permette di accorgersi che un logo su fondo bianco
    // stona sul colore scelto — che è l'unica cosa che l'anteprima serve a
    // mostrare.
    [$ente, $admin] = enteConAdmin();
    Storage::disk(MarchioEmail::DISCO)->put('marchi/'.$ente->id.'/logo.png', 'PNG-DEL-LABORATORIO-ROSSI');
    $ente->fissaMarchioEmail('marchi/'.$ente->id.'/logo.png', '#ffff00');

    $html = Livewire::actingAs($admin)->test(MarchioEnte::class)->html();

    expect($html)->toContain('data:image/png;base64,'.base64_encode('PNG-DEL-LABORATORIO-ROSSI'));
    expect($html)->not->toContain('logo caricato');
    // E il filetto e il pulsante dell'anteprima portano il colore scelto con
    // l'inchiostro CALCOLATO — lo stesso che finirà nell'email.
    expect($html)->toContain('border-top: 4px solid #ffff00');
    expect($html)->toContain('background-color: #ffff00; color: #0f172a');
});

it('does not fall over when the stored logo has vanished from the disk', function () {
    // Il disco `documenti` ha `throw => true`: un file cancellato a mano non
    // deve buttare giù proprio la pagina che serve a rimetterlo.
    [$ente, $admin] = enteConAdmin();
    $ente->fissaMarchioEmail('marchi/'.$ente->id.'/sparito.png', '#ffff00');

    $this->actingAs($admin)->get(route('anagrafica.marchio'))
        ->assertOk()
        ->assertSee('Laboratorio Rossi');
});

// --- Il modello: le due colonne non passano da un form ----------------------

it('keeps the brand columns out of mass assignment', function () {
    // 🔴 `marchio_logo_path` è un percorso sul disco privato `documenti`:
    // forgiarlo da un form dell'anagrafica significherebbe far incorporare in
    // un'email il file — o il documento — di un altro Ente.
    [$ente] = enteConAdmin();
    $altro = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Clinica Aurora']);

    $ente->update([
        'nome' => 'Laboratorio Rossi 2',
        'marchio_logo_path' => 'marchi/'.$altro->id.'/segreto.png',
        'marchio_colore' => '#ff0000',
    ]);

    expect(enteACrudo($ente->id)->nome)->toBe('Laboratorio Rossi 2');
    expect(enteACrudo($ente->id)->marchio_logo_path)->toBeNull();
    expect(enteACrudo($ente->id)->marchio_colore)->toBeNull();
});

it('refuses to brand a node that is not an Ente', function () {
    // Il marchio si legge solo dal nodo `tipo = ente`: scriverlo su un
    // dipartimento produrrebbe un'impostazione che nessuno rileggerà mai.
    [$ente] = enteConAdmin();
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Reparto Chimica']);

    expect(fn () => $dept->fissaMarchioEmail(null, '#ff0000'))
        ->toThrow(RuntimeException::class);

    expect(enteACrudo($dept->id)->marchio_colore)->toBeNull();
    expect($dept->tipo)->toBe(TipoUnitaOrganizzativa::Dipartimento);
});

it('does not resolve a node that is not an Ente as the page subject', function () {
    // Un utente il cui `tenant_id` puntasse a un nodo non-Ente (dato incoerente)
    // non deve trovarsi davanti la pagina di qualcun altro: la query filtra sul
    // tipo, quindi non trova nulla.
    [$ente] = enteConAdmin();
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();

    $utente = User::factory()->create([
        'tenant_id' => $dept->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $utente->assignRole('Admin');

    expect(fn () => Livewire::actingAs($utente)->test(MarchioEnte::class))
        ->toThrow(ModelNotFoundException::class);
});

// --- I global scope su `ente()`, resi falsificabili ---
//
// 🔴 Trovato in verifica indipendente, per mutazione: sostituendo la query di
// `ente()` con `withoutGlobalScopes()` **l'intero file restava verde**. Il
// docblock del componente afferma che quella query «per costruzione non può
// essere quella di un altro tenant», ma la ragione vera è che `$enteId` viene
// dalla sessione e non dall'utente: i global scope lì non erano provati da
// nulla, cioè erano una guardia di cui nessuno sapeva più dire se servisse.
//
// Servono, e questo test dice per cosa: il `SoftDeletingScope`. Un Ente
// cestinato non è più governabile — e senza gli scope la pagina continuerebbe
// a caricarlo e a lasciarci scrivere sopra, riscrivendo il marchio di
// un'organizzazione che l'applicazione considera cancellata.

it('refuses to govern the brand of a binned Ente', function () {
    [$ente, $admin] = enteConAdmin();
    $ente->delete();

    Livewire::actingAs($admin->fresh())->test(MarchioEnte::class);
})->throws(ModelNotFoundException::class);

it('refuses to WRITE the brand of an Ente binned after the page was opened', function () {
    // ⛔ La seconda metà, come ogni negativo di questo file: la pagina si apre
    // quando l'Ente è ancora sano, e viene cestinato mentre è aperta. L'azione
    // arriva su `/livewire/update`, dove il `can:` di rotta non passa — e deve
    // rileggere l'Ente, non fidarsi di ciò che aveva in mano al `mount()`.
    [$ente, $admin] = enteConAdmin();

    $pagina = Livewire::actingAs($admin->fresh())->test(MarchioEnte::class);

    $ente->delete();

    $pagina->set('colore', '#123456')->call('salva');

    // Il colore NON deve essere finito a database.
    expect(enteACrudo($ente->id)->marchio_email['colore'] ?? null)->not->toBe('#123456');
})->throws(ModelNotFoundException::class);
