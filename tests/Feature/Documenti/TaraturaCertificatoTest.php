<?php

use App\Actions\Documenti\CaricaDocumento;
use App\Enums\StatoSemaforo;
use App\Enums\TipoDocumento;
use App\Enums\TipoIntervento;
use App\Enums\TipoMotivoSemaforo;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Documento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Taratura come attività con certificato allegato (🔗 ADR-009/021 — S4,
 * 15 Ago 2026).
 *
 * ⚠️ **Metà di questa voce era già chiusa da S3, e questo file lo mette per
 * iscritto.** Una taratura è un intervento `tipo = taratura_e_certificazione`:
 * la sua `data_scadenza` passa già da `interventiAperti()` → `diagnosiSemaforo()`
 * e dalle query bulk dell'elenco. Il fatto era vero ma coperto **di sbieco**,
 * dentro un test che parlava della colonna «Prossima scadenza»: se qualcuno
 * cambiasse quel test, il contratto di ADR-009 sparirebbe senza che nulla
 * diventi rosso.
 *
 * ⛔ **Nessuna quarta fonte di scadenze**: a pilotare il semaforo è la
 * `data_scadenza` dell'ATTIVITÀ, mai una scadenza del documento. ADR-009 lo
 * vieta, e il costo misurato sarebbe di nove punti d'ingresso — due dentro
 * l'espressione SQL più fragile del progetto.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(Documento::DISCO);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Bilancia analitica']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    $this->scheda = fn () => Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento]);
});

// --- Il presupposto, congelato invece che raccontato ---

it('lights the semaforo from an open taratura, with no separate engine', function () {
    $taratura = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->addDays(10)->toDateString(),
    ]);

    $this->actingAs($this->admin);
    $diagnosi = $this->strumento->diagnosiSemaforo();

    expect($diagnosi->stato)->toBe(StatoSemaforo::Arancione)
        ->and($diagnosi->motivi[0]->tipo)->toBe(TipoMotivoSemaforo::Intervento)
        ->and($diagnosi->motivi[0]->riferimentoId)->toBe($taratura->id);

    // In Panoramica compare con l'etichetta del tipo, non con una voce propria.
    ($this->scheda)()->assertSee('Intervento in scadenza il');
});

it('stops counting a taratura once it is done, which is the honest current behaviour', function () {
    $taratura = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->addDays(10)->toDateString(),
    ]);

    $this->actingAs($this->admin);
    $taratura->segnaFatto(today());

    // ⚠️ **Il limite noto, dichiarato e non nascosto**: chiusa la taratura il
    // suo motivo esce dalla diagnosi, e la validità del certificato (12/24
    // mesi) oggi non è registrata da nessuna parte. «La taratura alimenta il
    // semaforo» è quindi vero per quella DA FARE e falso per quella FATTA —
    // cioè per l'unica che ha un certificato. La prossima si pianifica a mano,
    // e la UI lo dice; il rinnovo automatico è una decisione a sé.
    expect($this->strumento->fresh()->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde);
});

// --- Il certificato, dove l'utente lo cerca ---

it('says out loud when a taratura has no certificate yet', function () {
    Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->addMonth()->toDateString(),
    ]);

    // «Fatto» e «documentato» non sono la stessa cosa: una taratura senza
    // certificato è una taratura che in un audit non vale.
    ($this->scheda)()->assertSee('Certificato mancante');
});

it('shows the certificate on the taratura row, not only in the archive', function () {
    $taratura = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->addMonth()->toDateString(),
    ]);

    $this->actingAs($this->admin);
    app(CaricaDocumento::class)->esegui(
        $taratura,
        UploadedFile::fake()->create('certificato-accredia.pdf', 200, 'application/pdf'),
        TipoDocumento::CertificatoTaratura,
    );

    ($this->scheda)()
        ->assertSee('Certificato')
        ->assertDontSee('Certificato mancante');
});

it('preselects the certificate when attaching from a taratura row', function () {
    $taratura = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->addMonth()->toDateString(),
    ]);

    ($this->scheda)()
        ->call('openCaricaDocumento', $taratura->id)
        ->assertSet('tipoDocumento', TipoDocumento::CertificatoTaratura->value)
        ->assertSet('documentoInterventoId', $taratura->id);

    // Dalla macchina, invece, il default è il manuale.
    ($this->scheda)()
        ->call('openCaricaDocumento')
        ->assertSet('tipoDocumento', TipoDocumento::Manuale->value);
});

it('keeps the machine certificates out of the taratura rows', function () {
    // ⚠️ Gli id vanno SFASATI di proposito: in un test appena creato lo
    // strumento ha id 1 e il primo intervento pure, quindi `keyBy` fonderebbe
    // le due righe e il caso passerebbe anche senza il filtro sul soggetto —
    // l'ha mostrato la prova di mutazione. Tre interventi di riempimento
    // bastano a rendere il difetto visibile.
    Intervento::factory()->count(3)->forStrumento($this->strumento)->create();

    $taratura = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->addMonth()->toDateString(),
    ]);

    Documento::factory()->perIntervento($taratura)->create();
    // Un certificato appeso alla MACCHINA e non a un intervento: senza il
    // filtro sul soggetto finirebbe nella mappa con la chiave dello strumento,
    // e basterebbe una coincidenza di id per mostrarlo sulla riga sbagliata.
    Documento::factory()->perStrumento($this->strumento)
        ->create(['tipo' => TipoDocumento::CertificatoTaratura, 'nome' => 'certificato-macchina.pdf']);

    $this->actingAs($this->admin);
    $mappa = Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->viewData('certificati');

    expect($mappa)->toHaveCount(1)
        ->and($mappa->keys()->all())->toBe([$taratura->id]);
});

it('never shows a certificate of another machine on this row', function () {
    $altra = Strumento::factory()->forNode($this->dept)->create();
    $tarAltrui = Intervento::factory()->forStrumento($altra)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->addMonth()->toDateString(),
    ]);
    Documento::factory()->perIntervento($tarAltrui)->create(['nome' => 'certificato-altrui.pdf']);

    Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->addMonth()->toDateString(),
    ]);

    // La lista dei certificati parte da `strumento_id`: un documento di
    // un'altra macchina non può comparire qui nemmeno per errore di join.
    ($this->scheda)()
        ->assertSee('Certificato mancante')
        ->assertDontSee('certificato-altrui.pdf');
});

// --- Le guardie del model, che il blocco Documenti aveva lasciato scoperte ---

it('refuses a document whose subject belongs to another Ente', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create();
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($altroEnte)->create();
    $strumentoB = Strumento::factory()->forNode($deptB)->create();

    expect(fn () => Documento::create([
        'tenant_id' => $this->ente->id,           // Ente A
        'documentabile_type' => Strumento::class,
        'documentabile_id' => $strumentoB->id,     // macchina dell'Ente B
        'strumento_id' => $strumentoB->id,
        'tipo' => TipoDocumento::Manuale,
        'nome' => 'x.pdf',
        'path' => 'x.pdf',
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses a documentabile_type that is not a machine or an intervento', function () {
    // `morphTo` accetta qualunque stringa: una riga che puntasse a un model
    // senza `tenant_id` uscirebbe da ogni scoping, in silenzio.
    //
    // ⚠️ Si asserisce il MESSAGGIO e non la sola classe di eccezione: la prova
    // di mutazione ha mostrato che disattivando la whitelist il test passava
    // lo stesso — scattava la guardia sul tenant, cioè la ragione sbagliata.
    // Due guardie che sollevano la stessa eccezione sono distinguibili solo
    // così.
    expect(fn () => Documento::create([
        'tenant_id' => $this->ente->id,
        'documentabile_type' => User::class,
        'documentabile_id' => $this->admin->id,
        'strumento_id' => $this->strumento->id,
        'tipo' => TipoDocumento::Altro,
        'nome' => 'x.pdf',
        'path' => 'x.pdf',
    ]))->toThrow(InvalidArgumentException::class, 'si allega a uno Strumento o a un Intervento');
});

it('refuses a strumento_id that is not the subject machine', function () {
    $altra = Strumento::factory()->forNode($this->dept)->create();
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    // È la colonna da cui dipende la restrizione al sotto-albero: sbagliata,
    // la riga sarebbe invisibile al Responsabile giusto e visibile all'altro.
    expect(fn () => Documento::create([
        'tenant_id' => $this->ente->id,
        'documentabile_type' => Intervento::class,
        'documentabile_id' => $intervento->id,
        'strumento_id' => $altra->id,
        'tipo' => TipoDocumento::CertificatoTaratura,
        'nome' => 'x.pdf',
        'path' => 'x.pdf',
    ]))->toThrow(InvalidArgumentException::class);
});

// --- Il rinnovo: chiudere una taratura pianifica la successiva ---

it('plans the next taratura when closing one, with the periodicity asked and not guessed', function () {
    $taratura = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'descrizione' => 'Taratura annuale con certificato ACCREDIA',
        'data_scadenza' => today()->toDateString(),
    ]);

    ($this->scheda)()
        ->call('openCompleta', $taratura->id)
        // Preselezionata sulle tarature: è il caso in cui la prossima serve
        // quasi sempre. Resta una spunta e non un automatismo.
        ->assertSet('pianificaProssimaTaratura', true)
        ->set('dataEsecuzione', today()->toDateString())
        // ⚠️ 24 e non 12: con 12 il caso non distinguerebbe la periodicità
        // scelta da un default fisso in codice — la prova di mutazione l'ha
        // mostrato, e un test che usa il valore più probabile è un test cieco.
        ->set('mesiProssimaTaratura', 24)
        ->call('completa')
        ->assertHasNoErrors();

    $successiva = Intervento::where('strumento_id', $this->strumento->id)
        ->where('id', '!=', $taratura->id)->first();

    expect($successiva)->not->toBeNull()
        ->and($successiva->tipo)->toBe(TipoIntervento::TaraturaECertificazione)
        ->and($successiva->data_scadenza->toDateString())->toBe(today()->addMonths(24)->toDateString())
        ->and($successiva->descrizione)->toBe('Taratura annuale con certificato ACCREDIA')
        ->and($successiva->tecnico_id)->toBe($taratura->tecnico_id);

    // E il semaforo torna ad avere qualcosa da dire: è il limite che questo
    // passo chiude — prima, chiusa la taratura, lo strumento restava verde e la
    // prossima scadenza non esisteva da nessuna parte.
    $this->actingAs($this->admin);
    expect($this->strumento->fresh()->prossimoInterventoAperto()->id)->toBe($successiva->id);
});

it('asks for the periodicity instead of inventing one', function () {
    $taratura = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->toDateString(),
    ]);

    // Nessun documento del progetto quantifica la periodicità: un default in
    // codice avrebbe prodotto scadenze plausibili e non volute su migliaia di
    // macchine.
    ($this->scheda)()
        ->call('openCompleta', $taratura->id)
        ->set('dataEsecuzione', today()->toDateString())
        ->call('completa')
        ->assertHasErrors('mesiProssimaTaratura');

    expect(Intervento::count())->toBe(1)
        ->and($taratura->fresh()->stato->value)->toBe('non_fatto');
});

it('lets a taratura be closed without planning the next one', function () {
    $taratura = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => today()->toDateString(),
    ]);

    ($this->scheda)()
        ->call('openCompleta', $taratura->id)
        ->set('pianificaProssimaTaratura', false)
        ->set('dataEsecuzione', today()->toDateString())
        ->call('completa')
        ->assertHasNoErrors();

    expect(Intervento::count())->toBe(1);
});

it('never asks the periodicity when closing something that is not a taratura', function () {
    // La regola è condizionata: incondizionata bloccherebbe la chiusura di un
    // intervento qualunque su un campo che nella modale non compare nemmeno.
    $ordinario = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::ManutenzioneOrdinaria,
        'data_scadenza' => today()->toDateString(),
    ]);

    ($this->scheda)()
        ->call('openCompleta', $ordinario->id)
        ->assertSet('pianificaProssimaTaratura', false)
        ->set('dataEsecuzione', today()->toDateString())
        ->call('completa')
        ->assertHasNoErrors();

    expect(Intervento::count())->toBe(1)
        ->and($ordinario->fresh()->stato->value)->toBe('fatto');
});
