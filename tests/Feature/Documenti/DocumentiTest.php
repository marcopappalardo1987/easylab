<?php

use App\Actions\Documenti\CaricaDocumento;
use App\Enums\TipoDocumento;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Documento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Documenti allegati a strumenti e interventi (🔗 ADR-009/025/026 — S4,
 * 15 Ago 2026).
 *
 * **Tre aree rosse insieme**, ed è il motivo per cui i negativi vengono prima:
 * una rotta non-Livewire nuova, l'isolamento fra Enti, e l'accesso a file che
 * vivono fuori dal database. ADR-026 ha scelto il download **mediato
 * dall'applicazione** proprio perché l'autorizzazione si ricontrolli a ogni
 * richiesta: una URL pre-firmata dell'object store sarebbe un bearer token con
 * la Policy fuori dal giro.
 *
 * `Storage::fake()` ovunque: i test non toccano il bucket vero.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(Documento::DISCO);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);
    $this->intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    $this->utente = function (string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create([
            'tenant_id' => ($ente ?? $this->ente)->id,
            'two_factor_confirmed_at' => now(),
        ]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->admin = ($this->utente)('Admin');
    $this->scheda = fn (User $u) => Livewire::actingAs($u)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento]);
});

// --- L'isolamento, prima di tutto ---

it('gives 404 for a document of another Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();
    $altrui = Documento::factory()->perStrumento($strumentoB)->create();

    // Risponde il global scope sul route-model binding, non un controllo
    // scritto a mano: è la stessa risposta che darebbe una scheda altrui.
    $this->actingAs($this->admin)->get(route('documenti.download', $altrui))->assertNotFound();
});

it('keeps a Responsabile out of documents from outside their sub-tree', function () {
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);
    $fuori = Strumento::factory()->forNode($altroDept)->create();
    $documentoFuori = Documento::factory()->perStrumento($fuori)->create();
    $documentoDentro = Documento::factory()->perStrumento($this->strumento)->create();

    $resp = ($this->utente)('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);
    $this->actingAs($resp);

    // È la colonna denormalizzata `strumento_id` a rendere esprimibile questa
    // restrizione: sul solo morph non lo sarebbe.
    expect(Documento::pluck('id')->all())->toBe([$documentoDentro->id]);

    $this->get(route('documenti.download', $documentoFuori))->assertNotFound();
});

it('refuses the download to whoever lacks the permission', function () {
    $documento = Documento::factory()->perStrumento($this->strumento)->create();
    $senzaRuolo = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($senzaRuolo)->get(route('documenti.download', $documento))->assertForbidden();
});

it('separates seeing a document from downloading it', function () {
    // ⚠️ Caso **sintetico e dichiarato tale**: nessun ruolo reale ha oggi
    // `documenti.view` senza `documenti.download`. Serve comunque, ed è stata
    // la prova di mutazione a chiederlo — togliendo `Gate::authorize()` dal
    // controller la suite restava verde, perché il middleware della rotta
    // controlla un permesso DIVERSO (`view`) e nessun test distingueva i due.
    // Una guardia non falsificabile è una guardia di cui nessuno saprà dire, in
    // futuro, se serve ancora.
    $documento = Documento::factory()->perStrumento($this->strumento)->create();

    $ruolo = Role::create(['name' => 'Solo lettura documenti', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(['strumenti.view', 'documenti.view']);

    $utente = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente)->get(route('documenti.download', $documento))->assertForbidden();
});

it('never serves a file to a guest', function () {
    $documento = Documento::factory()->perStrumento($this->strumento)->create();

    $this->get(route('documenti.download', $documento))->assertRedirect(route('login'));
});

// --- Il caricamento ---

it('stores the file and the row, with the tenant in the path', function () {
    $this->actingAs($this->admin);

    $documento = app(CaricaDocumento::class)->esegui(
        $this->strumento,
        UploadedFile::fake()->create('manuale.pdf', 120, 'application/pdf'),
        TipoDocumento::Manuale,
    );

    Storage::disk(Documento::DISCO)->assertExists($documento->path);

    expect($documento->strumento_id)->toBe($this->strumento->id)
        ->and($documento->caricato_da)->toBe($this->admin->id)
        ->and($documento->path)->toContain("tenant-{$this->ente->id}")
        ->and($documento->nome)->toBe('manuale.pdf');
});

it('takes the strumento from the intervento, so the sub-tree scope keeps working', function () {
    $this->actingAs($this->admin);

    $documento = app(CaricaDocumento::class)->esegui(
        $this->intervento,
        UploadedFile::fake()->create('certificato.pdf', 90, 'application/pdf'),
        TipoDocumento::CertificatoTaratura,
    );

    // La colonna denormalizzata è calcolata in UN posto solo: una riga con
    // `strumento_id` incoerente sarebbe invisibile al Responsabile giusto e
    // visibile a quello sbagliato.
    expect($documento->documentabile_type)->toBe(Intervento::class)
        ->and($documento->documentabile_id)->toBe($this->intervento->id)
        ->and($documento->strumento_id)->toBe($this->strumento->id);
});

it('refuses to attach a document to anything else', function () {
    $this->actingAs($this->admin);

    expect(fn () => app(CaricaDocumento::class)->esegui(
        $this->ente, // né Strumento né Intervento
        UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        TipoDocumento::Altro,
    ))->toThrow(InvalidArgumentException::class);
});

it('uploads from the scheda, and refuses an intervento of another machine', function () {
    $altra = Strumento::factory()->forNode($this->dept)->create();
    $interventoAltrui = Intervento::factory()->forStrumento($altra)->create();

    ($this->scheda)($this->admin)
        ->call('openCaricaDocumento')
        ->set('fileDocumento', UploadedFile::fake()->create('manuale.pdf', 50, 'application/pdf'))
        ->set('tipoDocumento', TipoDocumento::Manuale->value)
        ->call('salvaDocumento')
        ->assertHasNoErrors();

    expect(Documento::count())->toBe(1);

    // L'intervento si risolve DALLA relazione: un id di un'altra macchina dà
    // 404 invece di allegare altrove.
    expect(fn () => ($this->scheda)($this->admin)
        ->call('openCaricaDocumento', $interventoAltrui->id)
        ->set('fileDocumento', UploadedFile::fake()->create('x.pdf', 50, 'application/pdf'))
        ->set('tipoDocumento', TipoDocumento::Altro->value)
        ->call('salvaDocumento'))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses a file that is too big or of the wrong kind', function () {
    ($this->scheda)($this->admin)
        ->call('openCaricaDocumento')
        ->set('fileDocumento', UploadedFile::fake()->create('enorme.pdf', 30_000, 'application/pdf'))
        ->set('tipoDocumento', TipoDocumento::Manuale->value)
        ->call('salvaDocumento')
        ->assertHasErrors('fileDocumento');

    ($this->scheda)($this->admin)
        ->call('openCaricaDocumento')
        ->set('fileDocumento', UploadedFile::fake()->create('script.exe', 10))
        ->set('tipoDocumento', TipoDocumento::Altro->value)
        ->call('salvaDocumento')
        ->assertHasErrors('fileDocumento');

    expect(Documento::count())->toBe(0);
});

// --- Il download ---

it('serves the file to an authorised user, and records the access', function () {
    $this->actingAs($this->admin);
    $documento = app(CaricaDocumento::class)->esegui(
        $this->strumento,
        UploadedFile::fake()->create('manuale.pdf', 12, 'application/pdf'),
        TipoDocumento::Manuale,
    );
    Activity::query()->delete();

    $this->get(route('documenti.download', $documento))
        ->assertOk()
        ->assertDownload('manuale.pdf');

    // ADR-026: la traccia del download è la contropartita di aver scelto di far
    // passare i file dall'applicazione.
    $riga = Activity::where('log_name', AuditLog::NAME)->latest('id')->first();
    expect($riga->description)->toBe('Documento scaricato')
        ->and($riga->causer_id)->toBe($this->admin->id);
});

it('gives 404 when the row exists but the file does not', function () {
    // Con `throw => true` un file mancante solleverebbe DENTRO lo
    // StreamedResponse, cioè a header già inviati: il client riceverebbe un 200
    // troncato invece di un errore. Il controllo preventivo esiste per questo.
    $documento = Documento::factory()->perStrumento($this->strumento)->create();

    $this->actingAs($this->admin)->get(route('documenti.download', $documento))->assertNotFound();
});

// --- La cancellazione ---

it('bins the row and keeps the file, so a restore is not a lie', function () {
    $this->actingAs($this->admin);
    $documento = app(CaricaDocumento::class)->esegui(
        $this->strumento,
        UploadedFile::fake()->create('manuale.pdf', 12, 'application/pdf'),
        TipoDocumento::Manuale,
    );

    ($this->scheda)($this->admin)
        ->call('openEliminaDocumento', $documento->id)
        ->call('eliminaDocumento');

    expect($documento->fresh()->trashed())->toBeTrue();
    Storage::disk(Documento::DISCO)->assertExists($documento->path);
});

it('refuses the deletion to a Tenant, who can upload but not remove', function () {
    $documento = Documento::factory()->perStrumento($this->strumento)->create();
    $tenant = ($this->utente)('Tenant');

    // Default ereditato dalla matrice: `documenti.upload` ✅, `documenti.delete` ❌.
    // Il caso lo congela; se un domani si decidesse altrimenti, va riscritto.
    ($this->scheda)($tenant)
        ->call('openEliminaDocumento', $documento->id)
        ->assertForbidden();
});
