<?php

use App\Actions\Ricambi\RegistraRicambiIntervento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Il servizio che registra i pezzi montati (ADR-022). È testato qui, senza
 * montare la UI, perché il form intervento non sarà il suo unico chiamante: il
 * tab Ricambi e la vista mobile devono ottenere lo stesso risultato.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($dept)->create();
    $this->intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    $this->servizio = new RegistraRicambiIntervento;
    $this->riga = fn (string $nome, string $scadenza = '2029-01-15') => [
        'nome' => $nome, 'scadenza_garanzia' => $scadenza,
    ];
});

it('creates catalogue, mounting row and warranty for each line', function () {
    $esito = $this->servizio->esegui($this->intervento, [
        ($this->riga)('Guarnizione O-Ring'),
        ($this->riga)('Filtro HEPA', '2027-06-30'),
    ]);

    expect($esito['creati'])->toHaveCount(2)
        ->and(Ricambio::count())->toBe(2)
        ->and(RicambioUtilizzo::count())->toBe(2)
        ->and(Garanzia::count())->toBe(2);

    $garanzia = Garanzia::whereRelation('ricambioUtilizzo.ricambio', 'nome', 'Filtro HEPA')->firstOrFail();

    // La scadenza è quella digitata, non un arrotondamento in mesi.
    expect($garanzia->data_scadenza_effettiva->toDateString())->toBe('2027-06-30')
        ->and($garanzia->durata_mesi)->toBeNull()
        ->and($garanzia->soggetto->value)->toBe('ricambio')
        ->and($garanzia->strumento_id)->toBeNull();
});

it('links an existing catalogue entry instead of duplicating it', function () {
    Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Guarnizione O-Ring']);

    $this->servizio->esegui($this->intervento, [($this->riga)('  guarnizione   O-RING ')]);

    expect(Ricambio::count())->toBe(1)
        ->and(Ricambio::first()->nome)->toBe('Guarnizione O-Ring'); // la grafia del primo vince
});

it('mounts the part on the intervento strumento and date', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->fatto('2026-03-04')->create();

    $utilizzo = $this->servizio->esegui($intervento, [($this->riga)('Cinghia')])['creati']->first();

    expect($utilizzo->strumento_id)->toBe($this->strumento->id)
        ->and($utilizzo->intervento_id)->toBe($intervento->id)
        ->and($utilizzo->quantita)->toBe(1)
        ->and($utilizzo->data->toDateString())->toBe('2026-03-04')
        // La garanzia decorre dal montaggio, non dalla scadenza pianificata.
        ->and($utilizzo->garanzia->data_inizio->toDateString())->toBe('2026-03-04');
});

it('moves the mounting date to the execution date when the intervento is closed', function () {
    // Rientro dall'uso reale (9 Ago 2026): un pezzo registrato su un intervento
    // PIANIFICATO risultava «montato» oggi, giorni prima che qualcuno lo
    // toccasse. La data vera si conosce solo alla chiusura, quindi è lì che si
    // scrive — e sta in `segnaFatto()` perché è l'unica via per chiudere un
    // intervento, quindi ogni chiamante la eredita.
    $pianificato = Intervento::factory()->forStrumento($this->strumento)
        ->pianificato()->create(['data_scadenza' => today()->addDays(2)->toDateString()]);

    $this->servizio->esegui($pianificato, [($this->riga)('Cinghia', '2030-01-01')]);

    // ⚠️ Aggiornato consapevolmente il 9 Ago 2026. Qui c'era
    // «Segnaposto finché l'intervento è aperto» con un assert su `today()`:
    // congelava il difetto invece di chiuderlo. Su un intervento pianificato il
    // pezzo NON è montato, quindi non c'è nessuna data — e la scheda lo dice
    // («montaggio ancora non effettuato») invece di inventarne una.
    expect(RicambioUtilizzo::firstOrFail()->data)->toBeNull();

    $pianificato->segnaFatto(today()->addDays(2));

    expect(RicambioUtilizzo::firstOrFail()->data->toDateString())
        ->toBe(today()->addDays(2)->toDateString())
        // La garanzia decorre dal montaggio vero.
        ->and(Garanzia::firstOrFail()->data_inizio->toDateString())
        ->toBe(today()->addDays(2)->toDateString())
        // La scadenza dichiarata NON si tocca: è ciò che pilota il semaforo.
        ->and(Garanzia::firstOrFail()->data_scadenza_effettiva->toDateString())->toBe('2030-01-01');
});

it('shows no mounting date while the intervento is still planned', function () {
    // Il difetto visto a schermo: «montato il <oggi>» per un pezzo che nessuno
    // aveva toccato. Correggere il dato alla chiusura non bastava — fino ad
    // allora la riga restava falsa, e sarebbe comparsa in qualunque ricerca per
    // periodo. Ora non c'è data finché non c'è montaggio.
    $pianificato = Intervento::factory()->forStrumento($this->strumento)->pianificato()->create();

    $this->servizio->esegui($pianificato, [($this->riga)('Guarnizione', '2030-01-01')]);

    expect(RicambioUtilizzo::firstOrFail()->data)->toBeNull()
        // La garanzia invece un inizio lo vuole sempre: asimmetria voluta,
        // «non montato» è rappresentabile, «garanzia senza inizio» no.
        ->and(Garanzia::firstOrFail()->data_inizio->toDateString())->toBe(today()->toDateString());
});

it('puts the parts still to be mounted at the top of the list', function () {
    // NULL = «aspetta qualcosa», quindi in cima. L'ordinamento è esplicito
    // perché SQLite mette i NULL per primi e Postgres per ultimi: senza il
    // CASE, l'ordine cambierebbe fra locale e produzione.
    $eseguito = Intervento::factory()->forStrumento($this->strumento)->fatto('2026-01-10')->create();
    $pianificato = Intervento::factory()->forStrumento($this->strumento)->pianificato()->create();

    $this->servizio->esegui($eseguito, [($this->riga)('Montato', '2030-01-01')]);
    $this->servizio->esegui($pianificato, [($this->riga)('Da montare', '2030-01-01')]);

    expect($this->strumento->ricambiUtilizzati()->with('ricambio')->get()->pluck('ricambio.nome')->all())
        ->toBe(['Da montare', 'Montato']);
});

it('does not break the closure when moving the start would pass the declared expiry', function () {
    // Caso limite del confine `scadenza > data_inizio`: con una scadenza
    // ravvicinata (un refuso, tipicamente) spostare l'inizio in avanti farebbe
    // esplodere la CHIUSURA dell'intervento. Si preferisce lasciare la garanzia
    // com'è: un dato informativo incoerente è meno grave di un'operazione che
    // non si può più completare.
    $pianificato = Intervento::factory()->forStrumento($this->strumento)
        ->pianificato()->create(['data_scadenza' => today()->addDays(10)->toDateString()]);

    $this->servizio->esegui($pianificato, [
        ($this->riga)('Cinghia', today()->addDays(3)->toDateString()),
    ]);

    $pianificato->segnaFatto(today()->addDays(10));

    expect(fn () => $pianificato->fresh())->not->toThrow(Exception::class)
        // Il montaggio si sposta comunque…
        ->and(RicambioUtilizzo::firstOrFail()->data->toDateString())
        ->toBe(today()->addDays(10)->toDateString())
        // …ma l'inizio della garanzia resta indietro, senza rompere nulla.
        ->and(Garanzia::firstOrFail()->data_inizio->toDateString())->toBe(today()->toDateString())
        ->and(Garanzia::firstOrFail()->data_scadenza_effettiva->toDateString())
        ->toBe(today()->addDays(3)->toDateString());
});

it('aligns the warranty even for a causer who cannot see ricambio rows', function () {
    // Stesso principio della rimozione: la relazione passa dal privacy scope, e
    // senza il bypass la garanzia resterebbe con una data d'inizio decisa dai
    // permessi di chi ha chiuso l'intervento.
    $pianificato = Intervento::factory()->forStrumento($this->strumento)->pianificato()->create();
    $this->servizio->esegui($pianificato, [($this->riga)('Cinghia', '2030-01-01')]);

    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $this->actingAs($tenant);

    $pianificato->segnaFatto(today()->addDay());

    expect(Garanzia::withoutGlobalScopes()->firstOrFail()->data_inizio->toDateString())
        ->toBe(today()->addDay()->toDateString());
});

it('removes a line together with its warranty', function () {
    $this->servizio->esegui($this->intervento, [($this->riga)('Cinghia')]);
    $utilizzo = RicambioUtilizzo::firstOrFail();

    $esito = $this->servizio->esegui($this->intervento, [], [$utilizzo->id]);

    expect($esito['rimossi'])->toBe(1)
        ->and(RicambioUtilizzo::count())->toBe(0)
        // Senza il bypass del privacy scope la garanzia resterebbe viva e
        // orfana, continuando a pesare sul semaforo di un pezzo smontato.
        ->and(Garanzia::withoutGlobalScopes()->whereNull('deleted_at')->count())->toBe(0)
        // Il catalogo NON si tocca: è la voce condivisa, non la riga.
        ->and(Ricambio::count())->toBe(1);
});

it('removes the warranty even for a causer who cannot see ricambio rows', function () {
    // Il permesso governa il dettaglio mostrato, non l'integrità del dato.
    $this->servizio->esegui($this->intervento, [($this->riga)('Cinghia')]);
    $utilizzo = RicambioUtilizzo::firstOrFail();

    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $this->actingAs($tenant);

    $this->servizio->esegui($this->intervento, [], [$utilizzo->id]);

    expect(Garanzia::withoutGlobalScopes()->whereNull('deleted_at')->count())->toBe(0);
});

it('refuses to remove a row belonging to another intervento', function () {
    $altro = Intervento::factory()->forStrumento($this->strumento)->create();
    $this->servizio->esegui($altro, [($this->riga)('Cinghia')]);
    $estraneo = RicambioUtilizzo::firstOrFail();

    expect(fn () => $this->servizio->esegui($this->intervento, [], [$estraneo->id]))
        ->toThrow(ModelNotFoundException::class);
});

it('does not delete anything when asked to add nothing', function () {
    // È la traduzione della nota del wireframe: la checkbox deselezionata
    // produce un array vuoto, e un array vuoto non è "cancella tutto".
    $this->servizio->esegui($this->intervento, [($this->riga)('Cinghia')]);

    $this->servizio->esegui($this->intervento, []);

    expect(RicambioUtilizzo::count())->toBe(1)
        ->and(Garanzia::count())->toBe(1);
});

it('rolls back every line when one of them fails', function () {
    // La riga buona non deve sopravvivere alla riga rotta: è l'atomicità che
    // ADR-022 chiede, ed è una proprietà del servizio e non del chiamante.
    expect(fn () => $this->servizio->esegui($this->intervento, [
        ($this->riga)('Cinghia'),
        ($this->riga)('Filtro HEPA', '1999-01-01'), // scadenza prima del montaggio
    ]))->toThrow(InvalidArgumentException::class);

    expect(RicambioUtilizzo::count())->toBe(0)
        ->and(Garanzia::count())->toBe(0)
        ->and(Ricambio::count())->toBe(0);
});
