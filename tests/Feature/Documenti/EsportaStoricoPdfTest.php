<?php

use App\Enums\TipoIntervento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * Storico macchina in PDF (🔗 ADR-031 — S4 STRETCH).
 *
 * Il foglio è un documento e non la pagina stampata: HTML dedicato, nessun
 * riuso dei blade della scheda. Qui si verifica **cosa dice** e **chi può
 * chiederlo**, non come è impaginato.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Microbiologia']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->url = route('strumenti.storico-pdf', $this->strumento);
});

it('redirects guests to login', function () {
    $this->get($this->url)->assertRedirect(route('login'));
});

it('returns a real PDF', function () {
    $risposta = $this->actingAs($this->admin)->get($this->url);

    $risposta->assertOk()->assertHeader('content-type', 'application/pdf');
    // La firma del formato, non la sola intestazione HTTP: dompdf potrebbe
    // restituire una pagina d'errore col content-type giusto.
    expect(substr($risposta->getContent(), 0, 4))->toBe('%PDF');
});

it('forbids whoever cannot export, the Tecnico first of all', function () {
    // Il Tecnico è l'unico ruolo senza `documenti.export_pdf`: sul campo legge e
    // chiude, ma non si porta via un foglio con lo storico di un cliente.
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $tecnico->assignRole('Tecnico');
    Intervento::factory()->forStrumento($this->strumento)->create(['tecnico_id' => $tecnico->id]);

    expect($tecnico->can('documenti.export_pdf'))->toBeFalse();

    $this->actingAs($tecnico)->get($this->url)->assertForbidden();
});

it('never exports a machine of another Ente', function () {
    // 404 e non 403: il route-model binding è scopato, quindi quella macchina
    // «non esiste» — la stessa risposta che darebbe la sua scheda.
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $estranea = Strumento::factory()->forNode($altroDept)->create();

    $this->actingAs($this->admin)
        ->get(route('strumenti.storico-pdf', $estranea))
        ->assertNotFound();
});

it('puts the machine, its location and its history on the sheet', function () {
    Intervento::factory()->forStrumento($this->strumento)->create([
        'descrizione' => 'Taratura annuale',
        'tipo' => TipoIntervento::TaraturaECertificazione,
    ]);

    // dompdf comprime il contenuto, quindi il testo non si cerca nei byte del
    // PDF: si verifica la view che lo produce, che è dove il contenuto è deciso.
    $vista = view('pdf.storico-strumento', [
        'strumento' => $this->strumento,
        'percorso' => $this->strumento->percorsoUbicazione(),
        'interventi' => $this->strumento->interventi()->with('tecnico')->get(),
        'garanzia' => null,
        'generatoIl' => now(),
        'generatoDa' => $this->admin->name,
    ])->render();

    expect($vista)
        ->toContain('Autoclave')
        ->toContain('Ente A › Microbiologia')
        ->toContain('Taratura annuale')
        // Il foglio dichiara quando è stato prodotto: è una fotografia, e
        // ritrovato fra un anno deve dire di quando parla.
        ->toContain(now()->format('d/m/Y'))
        // `e()` e non il nome nudo: faker produce anche cognomi con l'apostrofo
        // (O'Reilly), che Blade scrive `O&#039;Reilly`. Senza escape il test
        // passa quasi sempre e fallisce quando capita quel nome — cioè in CI,
        // su una build che non c'entra nulla. Trovato proprio così.
        ->toContain(e($this->admin->name));
});

it('carries the report di fine lavoro next to the intervento that produced it', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    $intervento->segnaFatto(today(), 'Guarnizione sostituita, tenuta verificata.');

    $vista = view('pdf.storico-strumento', [
        'strumento' => $this->strumento,
        'percorso' => '',
        'interventi' => $this->strumento->interventi()->with('tecnico')->get(),
        'garanzia' => null,
        'generatoIl' => now(),
        'generatoDa' => null,
    ])->render();

    // È il «foglio di intervento» che l'Elenco Funzionalità chiede di archiviare.
    expect($vista)->toContain('Guarnizione sostituita, tenuta verificata.');
});

it('says done or to-do in words, not by colour alone', function () {
    // Su un foglio stampato in bianco e nero il colore non esiste: stesso
    // principio del Design System §4, applicato alla carta.
    Intervento::factory()->forStrumento($this->strumento)->create(['descrizione' => 'Da fare']);

    $vista = view('pdf.storico-strumento', [
        'strumento' => $this->strumento,
        'percorso' => '',
        'interventi' => $this->strumento->interventi()->get(),
        'garanzia' => null,
        'generatoIl' => now(),
        'generatoDa' => null,
    ])->render();

    expect($vista)->toContain('da fare');
});

it('leaves the machine warranty off the sheet for whoever may not see it', function () {
    // Un PDF non sa degradare per permesso una volta uscito dall'applicazione:
    // ciò che non deve leggere chi lo esporta non ci deve proprio entrare.
    Garanzia::factory()->forStrumento($this->strumento)->create();

    Role::create(['name' => 'Archivista'])
        ->givePermissionTo(['strumenti.view', 'interventi.view', 'documenti.export_pdf']);
    $archivista = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $archivista->assignRole('Archivista');

    expect($archivista->can('garanzie.macchina.view'))->toBeFalse();

    $this->actingAs($archivista)->get($this->url)->assertOk();

    $vistaSenza = view('pdf.storico-strumento', [
        'strumento' => $this->strumento, 'percorso' => '', 'interventi' => collect(),
        'garanzia' => null, 'generatoIl' => now(), 'generatoDa' => null,
    ])->render();

    expect($vistaSenza)->not->toContain('Garanzia macchina');
});
