<?php

use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Tenancy\AccessibleNodes;
use App\Support\Utenti\AssegnazioneRifiutata;
use App\Support\Utenti\LaboratoriDelResponsabile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Finder\Finder;

/**
 * Le regole con cui si assegnano i laboratori a un Responsabile Reparto
 * (🔗 ADR-057; ADR-006 lo scope di reparto).
 *
 * **Area rossa**: ciò che si scrive qui è ciò che `AccessibleNodes` legge per
 * decidere che cosa una persona vede. Le due schermate che assegnano («Persone»
 * e «Laboratori») hanno i propri test; questi tengono ferme le invarianti che
 * valgono per chiunque chiami.
 *
 * Mondo: l'Ospedale ha Chimica (con dentro Cromatografia, e dentro questa
 * Colonne) e Fisica. La Clinica è l'altro cliente.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ospedale = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ospedale']);
    $this->chimica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ospedale)->create(['nome' => 'Chimica']);
    $this->cromatografia = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->chimica)->create(['nome' => 'Cromatografia']);
    $this->colonne = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->cromatografia)->create(['nome' => 'Colonne']);
    $this->fisica = UnitaOrganizzativa::factory()->dipartimento()->under($this->ospedale)->create(['nome' => 'Fisica']);

    $this->clinica = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Clinica']);
    $this->radiologia = UnitaOrganizzativa::factory()->dipartimento()->under($this->clinica)->create(['nome' => 'Radiologia']);

    $this->persona = function (string $nome, string $ruolo, UnitaOrganizzativa $ente): User {
        $u = User::factory()->create(['name' => $nome, 'tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u->fresh();
    };

    $this->admin = ($this->persona)('Aldo Admin', 'Admin', $this->ospedale);
    $this->rita = ($this->persona)('Rita Responsabile', 'Responsabile Reparto', $this->ospedale);

    /** Le righe del pivot di una persona, così come stanno nel database. */
    $this->righe = fn (User $chi): array => DB::table('responsabile_unita')
        ->where('user_id', $chi->id)->orderBy('unita_organizzativa_id')->pluck('unita_organizzativa_id')->map(fn ($id) => (int) $id)->all();
});

// ─── Che cosa si può assegnare ───────────────────────────────────────────────

it('offers every laboratorio of the ente with its path, the sede never, and a sub right after what contains it', function () {
    // «Chimica 2» è il tranello dell'ordine: scritto per esteso verrebbe prima
    // di «Chimica › Cromatografia».
    $chimica2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->ospedale)->create(['nome' => 'Chimica 2']);

    $assegnabili = LaboratoriDelResponsabile::assegnabili($this->ospedale->id);

    expect($assegnabili->pluck('percorso')->values()->all())->toBe([
        'Chimica',
        'Chimica › Cromatografia',
        'Chimica › Cromatografia › Colonne',
        'Chimica 2',
        'Fisica',
    ])
        ->and($assegnabili->keys()->all())->toBe([$this->chimica->id, $this->cromatografia->id, $this->colonne->id, $chimica2->id, $this->fisica->id])
        // 🔴 La sede no: assegnarla farebbe di un Responsabile un Admin che non amministra.
        ->and($assegnabili->has($this->ospedale->id))->toBeFalse()
        // 🔴 E niente dell'altro cliente.
        ->and($assegnabili->has($this->radiologia->id))->toBeFalse();
});

it('does not offer a laboratorio that was deleted, nor what is left under it', function () {
    $this->cromatografia->delete();

    expect(LaboratoriDelResponsabile::assegnabili($this->ospedale->id)->pluck('percorso')->values()->all())
        // «Colonne» è viva, ma sta sotto un laboratorio che non c'è più:
        // nessuna pagina ci arriva.
        ->toBe(['Chimica', 'Fisica']);
});

// ─── Assegnare ───────────────────────────────────────────────────────────────

it('writes the laboratori of a responsabile, who then sees those and what they contain', function () {
    $inChimica = Strumento::factory()->forNode($this->cromatografia)->create(['nome' => 'HPLC']);
    $inFisica = Strumento::factory()->forNode($this->fisica)->create(['nome' => 'Laser']);

    // Prima: niente. Un Responsabile senza laboratori non vede nulla.
    expect(AccessibleNodes::forUser($this->rita))->toBe([]);

    $esito = LaboratoriDelResponsabile::imposta($this->rita, [$this->chimica->id], $this->admin);

    expect($esito)->toBe(['prima' => [], 'dopo' => [$this->chimica->id]])
        ->and(($this->righe)($this->rita))->toBe([$this->chimica->id])
        ->and(AccessibleNodes::forUser($this->rita))->toEqualCanonicalizing([$this->chimica->id, $this->cromatografia->id, $this->colonne->id]);

    // E vede solo quelli: è la ragione per cui si assegnano.
    $this->actingAs($this->rita);

    expect(Strumento::query()->pluck('nome')->all())->toBe(['HPLC'])
        ->and(UnitaOrganizzativa::query()->orderBy('id')->pluck('nome')->all())->toBe(['Chimica', 'Cromatografia', 'Colonne'])
        ->and(Strumento::query()->whereKey($inFisica->id)->exists())->toBeFalse()
        ->and(Strumento::query()->whereKey($inChimica->id)->exists())->toBeTrue();
});

it('replaces what was there, and takes everything away when nothing is chosen', function () {
    LaboratoriDelResponsabile::imposta($this->rita, [$this->chimica->id], $this->admin);

    expect(LaboratoriDelResponsabile::imposta($this->rita, [$this->fisica->id], $this->admin))
        ->toBe(['prima' => [$this->chimica->id], 'dopo' => [$this->fisica->id]])
        ->and(($this->righe)($this->rita))->toBe([$this->fisica->id]);

    LaboratoriDelResponsabile::imposta($this->rita, [], $this->admin);

    expect(($this->righe)($this->rita))->toBe([])
        ->and(AccessibleNodes::forUser($this->rita))->toBe([]);
});

it('keeps only the roots: a sub of a chosen laboratorio is already inside it', function () {
    // Una seconda riga per «Colonne» resterebbe lì a concedere, da sola, il
    // giorno in cui «Chimica» viene tolta.
    LaboratoriDelResponsabile::imposta($this->rita, [$this->colonne->id, $this->chimica->id, $this->cromatografia->id, $this->fisica->id], $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->chimica->id, $this->fisica->id]);

    // Due sotto-laboratori senza il loro laboratorio restano due righe.
    $spettrometria = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->chimica)->create(['nome' => 'Spettrometria']);
    LaboratoriDelResponsabile::imposta($this->rita, [$this->colonne->id, $spettrometria->id], $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->colonne->id, $spettrometria->id]);
});

it('takes strings from a form and the same laboratorio twice as what they mean', function () {
    LaboratoriDelResponsabile::imposta($this->rita, [(string) $this->fisica->id, $this->fisica->id, (string) $this->colonne->id], $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->colonne->id, $this->fisica->id]);
});

// ─── Ciò che viene rifiutato ─────────────────────────────────────────────────

it('refuses a laboratorio of another ente, and writes nothing of what came with it', function () {
    LaboratoriDelResponsabile::imposta($this->rita, [$this->fisica->id], $this->admin);

    expect(fn () => LaboratoriDelResponsabile::imposta($this->rita, [$this->chimica->id, $this->radiologia->id], $this->admin))
        ->toThrow(AssegnazioneRifiutata::class, 'non è di questo Ente');

    // 🔴 Nemmeno «Chimica», che da sola sarebbe stata lecita: o tutto o niente.
    expect(($this->righe)($this->rita))->toBe([$this->fisica->id]);
});

it('refuses the sede, a deleted laboratorio, and an id that is nothing', function (Closure $nodo) {
    expect(fn () => LaboratoriDelResponsabile::imposta($this->rita, [$nodo()], $this->admin))
        ->toThrow(AssegnazioneRifiutata::class);

    expect(($this->righe)($this->rita))->toBe([]);
})->with([
    'la sede' => [fn () => test()->ospedale->id],
    'la sede di un altro' => [fn () => test()->clinica->id],
    'un laboratorio cestinato' => [function () {
        test()->fisica->delete();

        return test()->fisica->id;
    }],
    'un id che non esiste' => [fn () => 999999],
    'una parola' => [fn () => 'chimica'],
    'zero' => [fn () => 0],
]);

it('assigns only to who is a responsabile reparto', function (string $ruolo) {
    // Per ogni altro ruolo il pivot non conta — e una riga lasciata lì
    // tornerebbe a contare il giorno in cui la persona lo diventa.
    $altro = ($this->persona)('Tina', $ruolo, $this->ospedale);

    expect(fn () => LaboratoriDelResponsabile::imposta($altro, [$this->chimica->id], $this->admin))
        ->toThrow(AssegnazioneRifiutata::class, 'solo a chi ha il ruolo Responsabile Reparto');

    expect(($this->righe)($altro))->toBe([]);
})->with(['Admin', 'Tenant', 'Tecnico']);

it('refuses a responsabile who belongs to no ente', function () {
    $senzaEnte = User::factory()->create(['tenant_id' => null]);
    $senzaEnte->assignRole('Responsabile Reparto');

    expect(fn () => LaboratoriDelResponsabile::imposta($senzaEnte->fresh(), [$this->chimica->id], $this->admin))
        ->toThrow(AssegnazioneRifiutata::class);

    expect(DB::table('responsabile_unita')->count())->toBe(0);
});

it('judges a laboratorio by the ente of the person, not by where who acts happens to be', function () {
    // Chi chiama è della Clinica; Rita è dell'Ospedale. A contare è lei.
    $adminClinica = ($this->persona)('Carla', 'Admin', $this->clinica);
    $this->actingAs($adminClinica);

    LaboratoriDelResponsabile::imposta($this->rita, [$this->chimica->id], $adminClinica);

    expect(($this->righe)($this->rita))->toBe([$this->chimica->id]);

    expect(fn () => LaboratoriDelResponsabile::imposta($this->rita, [$this->radiologia->id], $adminClinica))
        ->toThrow(AssegnazioneRifiutata::class);
});

// ─── Uno alla volta, dalla pagina del laboratorio ────────────────────────────

it('adds one laboratorio to those a person already follows, and takes one away', function () {
    // Un collega segue un altro laboratorio della stessa sede: «quelli che la
    // persona segue già» sono i SUOI, non quelli di chiunque lavori lì.
    $franco = ($this->persona)('Franco', 'Responsabile Reparto', $this->ospedale);
    LaboratoriDelResponsabile::imposta($franco, [$this->colonne->id], $this->admin);

    LaboratoriDelResponsabile::aggiungi($this->rita, $this->fisica->id, $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->fisica->id])
        ->and(($this->righe)($franco))->toBe([$this->colonne->id]);

    LaboratoriDelResponsabile::aggiungi($this->rita, $this->cromatografia->id, $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->cromatografia->id, $this->fisica->id]);

    // Aggiungere il laboratorio che contiene un sotto-laboratorio già
    // assegnato lo assorbe.
    LaboratoriDelResponsabile::aggiungi($this->rita, $this->chimica->id, $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->chimica->id, $this->fisica->id]);

    // E aggiungere ciò che è già compreso non cambia niente.
    expect(LaboratoriDelResponsabile::aggiungi($this->rita, $this->colonne->id, $this->admin))
        ->toBe(['prima' => [$this->chimica->id, $this->fisica->id], 'dopo' => [$this->chimica->id, $this->fisica->id]]);

    LaboratoriDelResponsabile::togli($this->rita, $this->chimica->id, $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->fisica->id]);

    // Togliere ciò che non c'è non è un errore.
    LaboratoriDelResponsabile::togli($this->rita, $this->chimica->id, $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->fisica->id])
        // E al collega, in tutto questo, non è successo niente.
        ->and(($this->righe)($franco))->toBe([$this->colonne->id]);
});

it('is not stopped by a row left on a laboratorio that is no longer there, and sweeps it away', function () {
    // 🔴 Senza questo, a chi aveva un laboratorio poi cestinato non si
    // potrebbe più assegnare niente: la riga vecchia farebbe rifiutare tutto.
    LaboratoriDelResponsabile::imposta($this->rita, [$this->fisica->id, $this->colonne->id], $this->admin);
    $this->cromatografia->delete();   // «Colonne» resta viva, ma irraggiungibile
    $this->fisica->delete();

    LaboratoriDelResponsabile::aggiungi($this->rita, $this->chimica->id, $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->chimica->id]);
});

it('writes one sede at a time: what a person follows in another sede is left alone', function () {
    // 🔴 Chi segue laboratori anche in un'altra sede dello stesso contratto li
    // ritrova quando ci passa: `AccessibleNodes` conta le righe della sede in
    // cui la persona si trova. Assegnarle quelli di qui non deve portarglieli
    // via — ed è ciò che un `sync()` farebbe. (È il caso dei dati dimostrativi:
    // una Responsabile con tre laboratori a Milano e tre a Bologna.)
    $succursale = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Succursale']);
    $analisi = UnitaOrganizzativa::factory()->dipartimento()->under($succursale)->create(['nome' => 'Analisi']);
    DB::table('responsabile_unita')->insert(['user_id' => $this->rita->id, 'unita_organizzativa_id' => $analisi->id, 'created_at' => now(), 'updated_at' => now()]);

    // La riga dell'altra sede non è né «prima» né «dopo»: da qui non esiste.
    expect(LaboratoriDelResponsabile::imposta($this->rita, [$this->chimica->id], $this->admin))
        ->toBe(['prima' => [], 'dopo' => [$this->chimica->id]])
        ->and(($this->righe)($this->rita))->toBe([$this->chimica->id, $analisi->id]);

    LaboratoriDelResponsabile::aggiungi($this->rita, $this->fisica->id, $this->admin);
    LaboratoriDelResponsabile::togli($this->rita, $this->chimica->id, $this->admin);

    expect(($this->righe)($this->rita))->toBe([$this->fisica->id, $analisi->id]);

    LaboratoriDelResponsabile::imposta($this->rita, [], $this->admin);

    expect(($this->righe)($this->rita))->toBe([$analisi->id]);

    // E nel registro di questa sede non compare mai.
    expect(Activity::query()->where('description', 'Laboratori assegnati')->get()
        ->flatMap(fn (Activity $riga) => [...$riga->properties['da'], ...$riga->properties['a']])->unique()->sort()->values()->all())
        ->toBe(['Chimica', 'Fisica']);

    // Passata alla succursale, è quella riga a contare.
    $this->rita->forceFill(['tenant_id' => $succursale->id])->save();

    expect(AccessibleNodes::forUser($this->rita->fresh()))->toBe([$analisi->id]);
});

it('refuses to add a laboratorio of another ente one at a time too', function () {
    expect(fn () => LaboratoriDelResponsabile::aggiungi($this->rita, $this->radiologia->id, $this->admin))
        ->toThrow(AssegnazioneRifiutata::class)
        ->and(fn () => LaboratoriDelResponsabile::aggiungi($this->rita, $this->ospedale->id, $this->admin))
        ->toThrow(AssegnazioneRifiutata::class)
        ->and(fn () => LaboratoriDelResponsabile::aggiungi(($this->persona)('Tina', 'Tenant', $this->ospedale), $this->chimica->id, $this->admin))
        ->toThrow(AssegnazioneRifiutata::class);

    expect(DB::table('responsabile_unita')->count())->toBe(0);
});

// ─── Leggere ─────────────────────────────────────────────────────────────────

it('reads the laboratori of many people at once, and only those that still count', function () {
    $franco = ($this->persona)('Franco', 'Responsabile Reparto', $this->ospedale);
    $bruno = ($this->persona)('Bruno', 'Responsabile Reparto', $this->clinica);

    LaboratoriDelResponsabile::imposta($this->rita, [$this->chimica->id, $this->fisica->id], $this->admin);
    LaboratoriDelResponsabile::imposta($franco, [$this->fisica->id], $this->admin);
    LaboratoriDelResponsabile::imposta($bruno, [$this->radiologia->id], $this->admin);

    // Due righe che non contano: un laboratorio cestinato, e uno di un altro
    // Ente scritto da fuori.
    $this->fisica->delete();
    DB::table('responsabile_unita')->insert(['user_id' => $franco->id, 'unita_organizzativa_id' => $this->radiologia->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(LaboratoriDelResponsabile::assegnati([$this->rita->id, $franco->id, $bruno->id, $this->admin->id]))->toBe([
        $this->rita->id => [$this->chimica->id],
        $bruno->id => [$this->radiologia->id],
    ])
        // Solo le persone chieste: Bruno ha un laboratorio, ma qui non è fra loro.
        ->and(LaboratoriDelResponsabile::assegnati([$this->rita->id]))->toBe([$this->rita->id => [$this->chimica->id]])
        ->and(LaboratoriDelResponsabile::assegnati([]))->toBe([]);
});

it('lists the responsabili of an ente, alive and of that ente only, in a stable order', function () {
    $zeno = ($this->persona)('Zeno', 'Responsabile Reparto', $this->ospedale);
    $omonimaPrima = ($this->persona)('Anna', 'Responsabile Reparto', $this->ospedale);
    $omonimaDopo = ($this->persona)('Anna', 'Responsabile Reparto', $this->ospedale);
    ($this->persona)('Bruno', 'Responsabile Reparto', $this->clinica);
    ($this->persona)('Cestinata', 'Responsabile Reparto', $this->ospedale)->delete();

    expect(LaboratoriDelResponsabile::responsabiliDellEnte($this->ospedale->id)->pluck('id')->all())
        ->toBe([$omonimaPrima->id, $omonimaDopo->id, $this->rita->id, $zeno->id]);

    // ⚠️ A parità di nome l'ordine lo decide il motore: si prova sull'SQL.
    DB::enableQueryLog();
    LaboratoriDelResponsabile::responsabiliDellEnte($this->ospedale->id);

    expect(collect(DB::getQueryLog())->pluck('query')->implode(' '))->toMatch('/order by "name" asc, "id" asc/');
});

it('says who follows a laboratorio, and from where they get it', function () {
    $franco = ($this->persona)('Franco', 'Responsabile Reparto', $this->ospedale);
    $zoe = ($this->persona)('Zoe', 'Responsabile Reparto', $this->ospedale);

    LaboratoriDelResponsabile::imposta($this->rita, [$this->chimica->id], $this->admin);
    LaboratoriDelResponsabile::imposta($franco, [$this->colonne->id], $this->admin);
    LaboratoriDelResponsabile::imposta($zoe, [$this->cromatografia->id, $this->fisica->id], $this->admin);

    $chi = fn (UnitaOrganizzativa $nodo) => array_map(
        fn (array $voce) => [$voce['persona']->name, $voce['tramite']],
        LaboratoriDelResponsabile::chiSegue($nodo->id, $this->ospedale->id),
    );

    expect($chi($this->colonne))->toBe([
        ['Franco', null],
        ['Rita Responsabile', 'Chimica'],
        ['Zoe', 'Chimica › Cromatografia'],
    ])
        ->and($chi($this->chimica))->toBe([['Rita Responsabile', null]])
        ->and($chi($this->fisica))->toBe([['Zoe', null]])
        // La sede non è un laboratorio, e quello di un altro Ente non è di questo.
        ->and($chi($this->ospedale))->toBe([])
        ->and(LaboratoriDelResponsabile::chiSegue($this->radiologia->id, $this->ospedale->id))->toBe([]);

    // Un seeder, o un dato di prima: Rita ha una riga sia su «Chimica» sia su
    // «Colonne». Su «Colonne» la segue direttamente — è da lì che si toglie.
    DB::table('responsabile_unita')->insert(['user_id' => $this->rita->id, 'unita_organizzativa_id' => $this->colonne->id, 'created_at' => now(), 'updated_at' => now()]);

    expect($chi($this->colonne))->toContain(['Rita Responsabile', null])
        ->and($chi($this->cromatografia))->toContain(['Rita Responsabile', 'Chimica']);
});

it('does not list as following a laboratorio who is no longer a responsabile, or is of another ente', function () {
    LaboratoriDelResponsabile::imposta($this->rita, [$this->chimica->id], $this->admin);

    // Righe scritte da fuori: una persona che non è Responsabile, e una di un altro Ente.
    $tenant = ($this->persona)('Tina', 'Tenant', $this->ospedale);
    $bruno = ($this->persona)('Bruno', 'Responsabile Reparto', $this->clinica);
    foreach ([$tenant, $bruno] as $estranea) {
        DB::table('responsabile_unita')->insert(['user_id' => $estranea->id, 'unita_organizzativa_id' => $this->chimica->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    expect(array_map(fn (array $voce) => $voce['persona']->name, LaboratoriDelResponsabile::chiSegue($this->chimica->id, $this->ospedale->id)))
        ->toBe(['Rita Responsabile']);
});

// ─── Chi smette di essere Responsabile ───────────────────────────────────────

it('takes every laboratorio away from who stops being a responsabile, and says so in the audit', function () {
    LaboratoriDelResponsabile::imposta($this->rita, [$this->chimica->id, $this->fisica->id], $this->admin);

    // Anche quello che seguiva in un'altra sede: il ruolo è della persona, non
    // della sede, e chi non è più Responsabile non lo è da nessuna parte.
    DB::table('responsabile_unita')->insert(['user_id' => $this->rita->id, 'unita_organizzativa_id' => $this->radiologia->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->rita->syncRoles(['Tenant']);

    LaboratoriDelResponsabile::svuota($this->rita->fresh(), $this->admin);

    expect(($this->righe)($this->rita))->toBe([]);

    $riga = Activity::query()->where('description', 'Laboratori tolti col ruolo')->sole();

    expect($riga->causer_id)->toBe($this->admin->id)
        ->and($riga->subject_id)->toBe($this->rita->id)
        ->and($riga->properties->all())->toBe(['tolti' => 3, 'tenant_id' => $this->ospedale->id]);

    // Chi non ne aveva non lascia una riga che non dice niente.
    LaboratoriDelResponsabile::svuota($this->rita->fresh(), $this->admin);

    expect(Activity::query()->where('description', 'Laboratori tolti col ruolo')->count())->toBe(1);
});

// ─── Il registro ─────────────────────────────────────────────────────────────

it('leaves in the audit who changed the laboratori of whom, from what to what', function () {
    LaboratoriDelResponsabile::imposta($this->rita, [$this->fisica->id], $this->admin);
    LaboratoriDelResponsabile::imposta($this->rita, [$this->colonne->id, $this->fisica->id], $this->admin);

    // ⚠️ Con un ordine esplicito: due righe scritte nello stesso istante.
    $righe = Activity::query()->where('log_name', AuditLog::NAME)->where('description', 'Laboratori assegnati')->orderBy('id')->get();

    expect($righe)->toHaveCount(2)
        ->and($righe[1]->causer_id)->toBe($this->admin->id)
        ->and($righe[1]->subject_id)->toBe($this->rita->id)
        ->and($righe[1]->properties->all())->toBe([
            'da' => ['Fisica'],
            'a' => ['Chimica › Cromatografia › Colonne', 'Fisica'],
            'tenant_id' => $this->ospedale->id,
        ]);

    // Un salvataggio che non cambia niente non lascia una riga.
    LaboratoriDelResponsabile::imposta($this->rita, [$this->fisica->id, $this->colonne->id], $this->admin);

    expect(Activity::query()->where('description', 'Laboratori assegnati')->count())->toBe(2);
});

// ─── Una strada sola ─────────────────────────────────────────────────────────

it('is the only place in the application that writes the pivot', function () {
    // Le regole qui sopra valgono finché tutto passa da questa classe. Una
    // seconda schermata che facesse `attach()` per conto suo le salterebbe
    // tutte — il ruolo, l'Ente, la sede — senza che nessun test lo dicesse.
    $scrive = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $sorgente = $file->getContents();

        $daRelazione = preg_match('/(unitaResponsabili|responsabili)\(\)\s*->\s*(attach|detach|sync|syncWithoutDetaching|toggle|updateExistingPivot)\b/', $sorgente) === 1;
        $daTabella = preg_match("/table\('responsabile_unita'\)(?:(?!;).)*->\s*(insert|insertOrIgnore|update|upsert|delete|truncate)\b/s", $sorgente) === 1;

        if ($daRelazione || $daTabella) {
            $scrive[] = $file->getRelativePathname();
        }
    }

    expect($scrive)->toEqualCanonicalizing([
        'Support/Utenti/LaboratoriDelResponsabile.php',
        // Elimina un cliente intero, righe del pivot comprese (ADR-040).
        'Support/Piattaforma/EliminaCliente.php',
    ]);
});
