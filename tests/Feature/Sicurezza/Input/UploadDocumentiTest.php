<?php

use App\Actions\Documenti\CaricaDocumento;
use App\Enums\TipoDocumento;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Documento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Documenti\NomeFileSicuro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/**
 * 🔴 L'upload dei documenti come superficie d'ingresso (S7, T1c · ADR-009/026/042).
 *
 * ⚠️ **Perché qui non si passa da `Livewire::test()->set('fileDocumento', …)`.**
 * Sotto test, `TemporaryUploadedFile::getMimeType()` restituisce il MIME che il
 * *fake* dichiara (dedotto dall'estensione), non quello dei byte: un HTML
 * chiamato `x.pdf` passa la validazione nel test e verrebbe respinto in
 * produzione. Un test di quel tipo non misura il contenuto. Qui si usano file
 * VERI (`UploadedFile` di Symfony con `test: true`), su cui `mimes:` interroga
 * `finfo` sui byte — cioè il comportamento di produzione sul disco locale.
 */
const REGOLE_FILE_DOCUMENTO = ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'];

function fileVero(string $nome, string $contenuto, string $mimeDelClient = 'application/pdf'): UploadedFile
{
    $percorso = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($percorso, $contenuto);

    return new UploadedFile($percorso, $nome, $mimeDelClient, null, true);
}

function pdfMinimo(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

function pngMinimo(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}

it('keeps on the component the content-based rule these tests exercise', function () {
    // Il ponte fra questo file e la schermata: se qualcuno ripiegasse su
    // `extensions:` (che guarda solo il nome), i casi qui sotto smetterebbero
    // di descrivere la produzione senza diventare rossi.
    $sorgente = file_get_contents(app_path('Livewire/Concerns/ManagesDocumentiStrumento.php'));

    expect($sorgente)->toContain("'fileDocumento' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png']")
        ->and($sorgente)->not->toContain('extensions:');
});

it('refuses a file whose bytes are not what its extension claims', function (string $nome, string $contenuto) {
    $v = Validator::make(['f' => fileVero($nome, $contenuto)], ['f' => REGOLE_FILE_DOCUMENTO]);

    expect($v->fails())->toBeTrue();
})->with([
    'html travestito da pdf' => ['fattura.pdf', '<!DOCTYPE html><html><body><script>alert(document.cookie)</script></body></html>'],
    'svg travestito da png' => ['foto.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
    'php travestito da jpg' => ['foto.jpg', "<?php system(\$_GET['c']); ?>"],
    'eseguibile travestito da pdf' => ['manuale.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff".str_repeat("\x00", 64)."PE\x00\x00"],
]);

it('accepts a real PDF and a real PNG, whatever MIME the client declared', function (string $nome, string $contenuto) {
    // Il MIME dichiarato dal browser è falsificabile e non conta: decide il contenuto.
    $v = Validator::make(['f' => fileVero($nome, $contenuto, 'text/html')], ['f' => REGOLE_FILE_DOCUMENTO]);

    expect($v->passes())->toBeTrue();
})->with([
    'pdf' => ['certificato.pdf', pdfMinimo()],
    'png' => ['foto.png', pngMinimo()],
]);

it('refuses a file over 20 MB', function () {
    $v = Validator::make(
        ['f' => UploadedFile::fake()->create('enorme.pdf', 20_481, 'application/pdf')],
        ['f' => REGOLE_FILE_DOCUMENTO],
    );

    expect($v->errors()->has('f'))->toBeTrue()
        ->and($v->failed()['f'])->toHaveKey('Max');
});

// --- Il nome del file ---

it('strips from the name what would break the download header', function () {
    expect(NomeFileSicuro::da("verbale\r\nSet-Cookie: x=1.pdf"))->toBe('verbaleSet-Cookie: x=1.pdf')
        ->and(NomeFileSicuro::da("a\0b.pdf"))->toBe('ab.pdf')
        ->and(NomeFileSicuro::da('il "vero" verbale.pdf'))->toBe('il _vero_ verbale.pdf');
});

it('removes the right-to-left override that disguises an extension', function () {
    $travestito = "fattura\u{202E}fdp.exe";

    expect(NomeFileSicuro::da($travestito))->toBe('fatturafdp.exe')
        ->and(NomeFileSicuro::da($travestito))->not->toContain("\u{202E}");
});

it('never keeps a path in the name', function () {
    expect(NomeFileSicuro::da('../../etc/passwd'))->toBe('_.._etc_passwd')
        ->and(NomeFileSicuro::da('..\\..\\boot.ini'))->toBe('_.._boot.ini');
});

it('shortens a name longer than the column, keeping the extension', function () {
    $nome = NomeFileSicuro::da(str_repeat('è', 400).'.pdf');

    expect(mb_strlen($nome))->toBe(NomeFileSicuro::MASSIMO)
        ->and($nome)->toEndWith('.pdf');
});

it('falls back to a neutral name when nothing printable is left', function (string $vuoto) {
    expect(NomeFileSicuro::da($vuoto))->toBe(NomeFileSicuro::RIPIEGO);
})->with(["\r\n", '   ', '...', "\u{202E}"]);

it('leaves an ordinary Italian file name untouched', function () {
    expect(NomeFileSicuro::da('Certificato di taratura n°12 – 2026.pdf'))
        ->toBe('Certificato di taratura n°12 – 2026.pdf');
});

it('repairs a name that is not valid UTF-8 instead of failing the insert', function () {
    // Su Postgres una sequenza non UTF-8 è un errore all'INSERT.
    expect(mb_check_encoding(NomeFileSicuro::da("verbale\xE0.pdf"), 'UTF-8'))->toBeTrue();
});

// --- Dall'upload alla riga ---

it('saves the sanitised name, and serves the download as an attachment', function () {
    Storage::fake(Documento::DISCO);
    $this->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $strumento = Strumento::factory()->forNode($ente)->create();
    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');
    $this->actingAs($admin);

    $documento = app(CaricaDocumento::class)->esegui(
        $strumento,
        fileVero("verbale\r\n\u{202E}fdp.exe", pdfMinimo()),
        TipoDocumento::Manuale,
    );

    expect($documento->nome)->toBe('verbalefdp.pdf')
        // Il percorso sul disco non viene mai dal nome del client.
        ->and($documento->path)->not->toContain('verbale')
        ->and($documento->path)->toStartWith("tenant-{$ente->id}/strumento-{$strumento->id}/");

    $risposta = $this->get(route('documenti.download', $documento))->assertOk();

    expect($risposta->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($risposta->headers->get('Content-Disposition'))->not->toContain("\n");
});

// --- Estensione, MIME e nome al download (caccia T1cA-2, T1cB-1, T1cB-2) ---

function scenaDocumenti(): array
{
    Storage::fake(Documento::DISCO);
    test()->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $strumento = Strumento::factory()->forNode($ente)->create();
    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    return [$strumento, $admin];
}

it('downloads a PDF polyglot under .pdf, whatever extension the client gave it', function (string $nome) {
    [$strumento, $admin] = scenaDocumenti();
    $this->actingAs($admin);

    // Un PDF valido che porta dentro uno script: `mimes:` lo ammette come PDF.
    $poliglotta = fileVero($nome, "%PDF-1.4\n<script language=\"VBScript\">CreateObject(\"WScript.Shell\").Run \"calc\"</script>\n%%EOF\n");
    expect(Validator::make(['f' => $poliglotta], ['f' => REGOLE_FILE_DOCUMENTO])->passes())->toBeTrue();

    $documento = app(CaricaDocumento::class)->esegui($strumento, $poliglotta, TipoDocumento::Manuale);
    $disposizione = (string) $this->get(route('documenti.download', $documento))->assertOk()->headers->get('Content-Disposition');

    expect($documento->nome)->toEndWith('.pdf')
        ->and($disposizione)->toMatch('/\.pdf"?$/');
})->with(['manuale.hta', 'fattura.html', 'verbale.svg', 'setup.exe', 'avvio.bat', 'senza-estensione']);

it('keeps a declared extension that is a synonym of the real one', function () {
    expect(NomeFileSicuro::conEstensione('foto.JPEG', 'jpg'))->toBe('foto.JPEG')
        ->and(NomeFileSicuro::conEstensione('verbale.pdf', 'pdf'))->toBe('verbale.pdf')
        ->and(NomeFileSicuro::conEstensione('.hta', 'pdf'))->toBe(NomeFileSicuro::RIPIEGO.'.pdf');
});

it('refuses, even without the screen, a file whose content is not a document', function () {
    [$strumento, $admin] = scenaDocumenti();
    $this->actingAs($admin);

    expect(fn () => app(CaricaDocumento::class)->esegui($strumento, fileVero('fattura.pdf', '<html><script>x</script></html>'), TipoDocumento::Manuale))
        ->toThrow(InvalidArgumentException::class);

    expect(Documento::count())->toBe(0);
});

it('serves a stored name that has no ASCII left', function (string $nome) {
    [$strumento, $admin] = scenaDocumenti();
    $this->actingAs($admin);

    // Riga scritta a mano: è il caso dei documenti caricati prima della sanificazione.
    $documento = app(CaricaDocumento::class)->esegui($strumento, fileVero('x.pdf', pdfMinimo()), TipoDocumento::Manuale);
    $documento->forceFill(['nome' => $nome])->save();

    $disposizione = (string) $this->get(route('documenti.download', $documento))->assertOk()->headers->get('Content-Disposition');

    expect($disposizione)->toContain('filename=documento')
        ->and($disposizione)->toContain("filename*=utf-8''");
})->with(['solo percento' => '%', 'sole emoji' => '📄📄', 'emoji con estensione' => '📄.pdf']);

it('records the MIME of the content for a PDF uploaded from the scheda, and serves it', function () {
    [$strumento, $admin] = scenaDocumenti();

    Livewire::actingAs($admin)
        ->test(SchedaStrumento::class, ['strumento' => $strumento])
        ->call('openCaricaDocumento')
        ->set('fileDocumento', UploadedFile::fake()->createWithContent('manuale.pdf', pdfMinimo()))
        ->set('tipoDocumento', TipoDocumento::Manuale->value)
        ->call('salvaDocumento')
        ->assertHasNoErrors();

    $documento = Documento::sole();
    expect($documento->mime)->toBe('application/pdf');

    $this->actingAs($admin)->get(route('documenti.download', $documento))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('answers a forged document type with a validation error, not a 500', function () {
    [$strumento, $admin] = scenaDocumenti();

    Livewire::actingAs($admin)
        ->test(SchedaStrumento::class, ['strumento' => $strumento])
        ->call('openCaricaDocumento')
        ->set('fileDocumento', UploadedFile::fake()->createWithContent('manuale.pdf', pdfMinimo()))
        ->set('tipoDocumento', 'inventato')
        ->call('salvaDocumento')
        ->assertHasErrors('tipoDocumento');

    expect(Documento::count())->toBe(0);
});
