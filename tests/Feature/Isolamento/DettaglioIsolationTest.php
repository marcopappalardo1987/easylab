<?php

use App\Livewire\Dashboard\Home;
use App\Models\Documento;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\Feature\Isolamento\Support\MondoDueEnti;

/**
 * Isolamento multi-tenant sulle superfici di DETTAGLIO, DOWNLOAD, EXPORT e
 * sui CONTATORI della dashboard (ADR-001/018). Test negativi: un id dell'Ente B
 * chiesto dall'Admin dell'Ente A dà 404 — la riga «non esiste» — e un export
 * non ne porta nemmeno una.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();
    $this->adminA = $this->mondo->riga('A', 'admin');
});

/** @return array<string, array{0: Closure(MondoDueEnti, string): string}> */
function rotteDiDettaglio(): array
{
    return [
        'scheda strumento' => [fn (MondoDueEnti $m, string $x) => route('strumenti.show', $m->riga($x, 'strumento'))],
        'stampa QR' => [fn (MondoDueEnti $m, string $x) => '/strumenti/'.$m->riga($x, 'strumento')->id.'/qr'],
        'storico PDF' => [fn (MondoDueEnti $m, string $x) => route('strumenti.storico-pdf', $m->riga($x, 'strumento'))],
        'download documento' => [fn (MondoDueEnti $m, string $x) => '/documenti/'.$m->riga($x, 'documento')->id],
        'accesso QR firmato' => [fn (MondoDueEnti $m, string $x) => URL::signedRoute('qr.strumento', ['token' => $m->riga($x, 'strumento')->qr_token])],
    ];
}

it('answers 404 to a detail route naming a row of another Ente', function (Closure $url) {
    $this->actingAs($this->adminA)->get($url($this->mondo, 'B'))->assertNotFound();
})->with(rotteDiDettaglio());

it('serves the same detail route for a row of the own Ente', function (Closure $url) {
    // Il positivo che rende credibile il 404 di sopra: la rotta esiste e funziona.
    expect($this->actingAs($this->adminA)->get($url($this->mondo, 'A'))->baseResponse->getStatusCode())->toBeIn([200, 302]);
})->with(rotteDiDettaglio());

it('never leaks the bytes of a document of another Ente', function () {
    $risposta = $this->actingAs($this->adminA)->get('/documenti/'.$this->mondo->riga('B', 'documento')->id);

    $risposta->assertNotFound();
    expect($risposta->getContent())->not->toContain('CONTENUTO-SEGRETO-B');
});

it('answers 404 to a Responsabile asking for a machine outside the assigned subtree', function () {
    $this->actingAs($this->mondo->riga('A', 'responsabile'))
        ->get(route('strumenti.show', $this->mondo->riga('A', 'strumentoAltroReparto')))
        ->assertNotFound();
});

it('exports no document row of another Ente, not even when filtered on its machine', function () {
    $righe = null;
    View::composer('pdf.elenco-documenti', function ($vista) use (&$righe) {
        $righe = $vista->getData()['righe'];
    });

    $this->actingAs($this->adminA)->get('/documenti/export.pdf')->assertOk();
    expect($righe->pluck('id')->all())->toEqualCanonicalizing([
        $this->mondo->riga('A', 'documento')->id,
        $this->mondo->riga('A', 'documentoAltroReparto')->id,
    ]);

    $this->actingAs($this->adminA)
        ->get('/documenti/export.pdf?strumentoId='.$this->mondo->riga('B', 'strumento')->id)
        ->assertOk();
    expect($righe)->toBeEmpty();
});

it('never names a machine of another Ente in the filter line of the export', function () {
    $filtri = null;
    View::composer('pdf.elenco-documenti', function ($vista) use (&$filtri) {
        $filtri = (string) json_encode($vista->getData()['filtri']);
    });

    $this->actingAs($this->adminA)
        ->get('/documenti/export.pdf?strumentoId='.$this->mondo->riga('B', 'strumento')->id)
        ->assertOk();

    expect($filtri)->not->toContain('Strumento-SEGRETO-B');
});

it('puts only the own history on the storico PDF', function () {
    $dati = null;
    View::composer('pdf.storico-strumento', function ($vista) use (&$dati) {
        $dati = $vista->getData();
    });

    $this->actingAs($this->adminA)
        ->get(route('strumenti.storico-pdf', $this->mondo->riga('A', 'strumento')))
        ->assertOk();

    $html = view('pdf.storico-strumento', $dati)->render();
    foreach (MondoDueEnti::marcatori('B') as $marcatore) {
        expect($html)->not->toContain($marcatore);
    }
    expect($html)->toContain('Intervento-SEGRETO-A');
});

it('counts only the machines of the own Ente on the dashboard', function () {
    $parco = Livewire::actingAs($this->adminA)->test(Home::class)->viewData('parco');

    // Due macchine per Ente nel mondo di prova: 4 vorrebbe dire che B è entrato nel conto.
    expect($parco->totale())->toBe(2);
});

it('counts only the assigned subtree on the dashboard of a Responsabile', function () {
    $parco = Livewire::actingAs($this->mondo->riga('A', 'responsabile'))->test(Home::class)->viewData('parco');

    expect($parco->totale())->toBe(1);
});

it('keeps the document of another Ente on the disk after a refused download', function () {
    // Il 404 non deve avere effetti collaterali: nessuna riga di audit sul documento estraneo.
    $documentoB = $this->mondo->riga('B', 'documento');

    $this->actingAs($this->adminA)->get('/documenti/'.$documentoB->id)->assertNotFound();

    expect(Documento::withoutGlobalScopes()->find($documentoB->id))->not->toBeNull();
    $this->assertDatabaseMissing('activity_log', [
        'subject_type' => $documentoB->getMorphClass(),
        'subject_id' => $documentoB->id,
        'description' => 'Documento scaricato',
    ]);
});
