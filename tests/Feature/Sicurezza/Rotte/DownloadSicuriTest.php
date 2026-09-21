<?php

use App\Models\Documento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Le rotte che restituiscono BYTE invece di pagine (security pass S7, T1b —
 * 🔗 ADR-003, ADR-018, ADR-026).
 *
 * Una rotta di file lasciata aperta è il modo classico di aggirare il gate di
 * un elenco: qui si provano cross-tenant, traversal e gli header che decidono
 * se il browser **scarica** o **esegue** ciò che riceve.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(Documento::DISCO);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $dip = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Microbiologia']);
    $this->strumento = Strumento::factory()->forNode($dip)->create(['nome' => 'Autoclave']);

    $this->altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $altroDip = UnitaOrganizzativa::factory()->dipartimento()->under($this->altroEnte)->create(['nome' => 'Chimica']);
    $this->estraneo = Strumento::factory()->forNode($altroDip)->create(['nome' => 'Centrifuga']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->documento = function (Strumento $strumento, array $attributi = []): Documento {
        $documento = Documento::factory()->perStrumento($strumento)->create($attributi);
        Storage::disk(Documento::DISCO)->put($documento->path, '%PDF-1.4 finto');

        return $documento;
    };
});

// --- Cross-tenant ---

it('gives 404 on a document of another Ente, and never streams a byte of it', function () {
    $estraneo = ($this->documento)($this->estraneo);

    $risposta = $this->actingAs($this->admin)->get(route('documenti.download', $estraneo));

    $risposta->assertNotFound();
    expect($risposta->headers->get('content-disposition'))->toBeNull();
});

it('gives 404 on the storico of a machine of another Ente', function () {
    $this->actingAs($this->admin)
        ->get(route('strumenti.storico-pdf', $this->estraneo))
        ->assertNotFound();
});

it('refuses a non numeric document id before any binding', function () {
    $this->actingAs($this->admin)->get('/documenti/..%2F..%2F.env')->assertNotFound();
});

// --- Gli header del download ---

it('always serves a document as an attachment, never inline', function () {
    $documento = ($this->documento)($this->strumento);

    $disposizione = $this->actingAs($this->admin)
        ->get(route('documenti.download', $documento))
        ->assertOk()
        ->headers->get('content-disposition');

    expect($disposizione)->toStartWith('attachment;');
});

it('tells the browser not to sniff the type of a downloaded document', function () {
    $documento = ($this->documento)($this->strumento);

    $this->actingAs($this->admin)
        ->get(route('documenti.download', $documento))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('never echoes a mime type the upload could not have produced', function (string $mime) {
    // Il mime salvato è quello DICHIARATO dal client (`getClientMimeType`), non
    // quello misurato: `mimes:pdf,jpg,jpeg,png` controlla il contenuto, non
    // l'intestazione della parte multipart.
    $documento = ($this->documento)($this->strumento, ['mime' => $mime]);

    $this->actingAs($this->admin)
        ->get(route('documenti.download', $documento))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream');
})->with(['text/html', 'image/svg+xml', 'application/javascript', 'text/xml']);

it('keeps the declared mime when it is one the upload accepts', function () {
    $documento = ($this->documento)($this->strumento, ['mime' => 'image/png', 'nome' => 'targa.png']);

    $this->actingAs($this->admin)
        ->get(route('documenti.download', $documento))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('downloads a document whose name the header could not carry, instead of a 500', function (string $nome) {
    // Symfony rifiuta `/`, `\` e i caratteri di controllo in Content-Disposition
    // lanciando un'eccezione: il nome arriva dal client, quindi era un 500 a
    // comando su un documento proprio (e un documento reso inscaricabile).
    $documento = ($this->documento)($this->strumento, ['nome' => $nome]);

    $disposizione = $this->actingAs($this->admin)
        ->get(route('documenti.download', $documento))
        ->assertOk()
        ->headers->get('content-disposition');

    expect($disposizione)->toStartWith('attachment;')
        ->not->toContain('\\')
        ->not->toContain("\n");
})->with([
    'backslash' => 'rapporto\\finale.pdf',
    'a capo' => "rapporto\nSet-Cookie: x=1.pdf",
    'tabulazione' => "rapporto\tfinale.pdf",
]);

// --- File delle guide: traversal ---

it('never resolves a traversal in the slug of a guide file', function (string $slug) {
    discoGuideVuoto();
    guidaFinta('una-guida');
    $developer = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    $this->actingAs($developer)->get("/guida/{$slug}/video")->assertNotFound();
})->with([
    'punti codificati' => '..%2F..%2F.env',
    'punti doppi' => '..',
    'maiuscole' => 'UNA-GUIDA',
    'slug inesistente' => 'altra-guida',
]);

it('never serves a piece of a guide that is not a video or a poster', function (string $pezzo) {
    discoGuideVuoto();
    guidaFinta('una-guida');
    $developer = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    $this->actingAs($developer)->get("/guida/una-guida/{$pezzo}")->assertNotFound();
})->with(['manifest', '..%2Fmanifest.json', 'video.mp4']);

it('declares the content type of a guide file itself, never from the file', function () {
    discoGuideVuoto();
    guidaFinta('una-guida');
    $developer = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $developer->assignRole('Developer');

    $this->actingAs($developer)->get('/guida/una-guida/copertina')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

// --- Il disco `local` servito dal framework ---

it('never serves a private file through the framework storage route without a signature', function () {
    // `filesystems.disks.local.serve = true` registra `GET /storage/{path}` su
    // `storage/app/private`: senza firma deve restare chiuso.
    Storage::fake('local');
    Storage::disk('local')->put('segreto.txt', 'dato riservato');

    $risposta = $this->actingAs($this->admin)->get('/storage/segreto.txt');

    expect($risposta->status())->toBeIn([403, 404])
        ->and($risposta->getContent())->not->toContain('dato riservato');
});
