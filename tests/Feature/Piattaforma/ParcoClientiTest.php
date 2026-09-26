<?php

use App\Enums\SoggettoGaranzia;
use App\Livewire\Piattaforma\ParcoGlobale;
use App\Models\Account;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piattaforma\ParcoClienti;
use App\Support\Piattaforma\Perimetro;
use App\Support\Tenancy\VistaPiattaforma;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 🔴 La porta del **Parco clienti** (🔗 ADR-037), area rossa.
 *
 * Qui si rimuovono deliberatamente gli scope su cui poggia l'isolamento
 * dell'intero progetto, quindi i **negativi contano più dei positivi**. Sono di
 * tre specie, e nessuna delle tre copre le altre:
 *
 *   1. **Il permesso**: chi non ha `tenants.view_all` non ottiene un elenco
 *      vuoto, ottiene un 403.
 *   2. **Il perimetro**: arriva dal browser, quindi ogni forma di forgiatura —
 *      un id inventato, un id altrui, il piano che non esiste, il modo che non
 *      esiste, la selezione vuota — deve **restringere o non cambiare nulla**,
 *      mai allargare.
 *   3. **Il non-trapelamento**: aprire la porta per questa vista non deve
 *      allentare lo scope per il resto della richiesta.
 *
 * ⚠️ **Il gate di questa classe è falsificabile solo per via strutturale**, e va
 * detto invece di lasciar credere che il negativo comportamentale lo provi: ogni
 * lettore attraversa comunque `VistaPiattaforma`, che il gate ce l'ha, quindi
 * togliendo `self::porta()` da un metodo i test del punto 1 resterebbero verdi.
 * Il test che diventa rosso è quello che **legge il sorgente**, ed è l'ultimo di
 * questo file.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Due clienti su due piani diversi, ciascuno con la sua sede e le sue righe.
    $this->rossi = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi', 'piano' => 'free']);
    $this->sedeRossi = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede Rossi']);
    $this->autoclaveRossi = Strumento::factory()->forNode($this->sedeRossi)->create(['nome' => 'Autoclave Rossi']);
    Intervento::factory()->forStrumento($this->autoclaveRossi)->create(['descrizione' => 'Taratura Rossi']);
    Garanzia::factory()->forStrumento($this->autoclaveRossi)->create();
    Ricambio::factory()->forTenant($this->sedeRossi)->create(['nome' => 'Guarnizione Rossi']);

    $this->bianchi = Account::factory()->saas()->create(['ragione_sociale' => 'Lab Bianchi']);
    $this->sedeBianchi = UnitaOrganizzativa::factory()->ente()->perAccount($this->bianchi)->create(['nome' => 'Sede Bianchi']);
    $this->autoclaveBianchi = Strumento::factory()->forNode($this->sedeBianchi)->create(['nome' => 'Autoclave Bianchi']);
    Intervento::factory()->forStrumento($this->autoclaveBianchi)->create(['descrizione' => 'Taratura Bianchi']);
    Garanzia::factory()->forStrumento($this->autoclaveBianchi)->create();
    Ricambio::factory()->forTenant($this->sedeBianchi)->create(['nome' => 'Guarnizione Bianchi']);

    // EasyLab stessa: un Ente e delle macchine che non sono di nessun cliente.
    $this->easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);
    $this->sedeEasylab = UnitaOrganizzativa::factory()->ente()->perAccount($this->easylab)->create(['nome' => 'Sede EasyLab']);
    $this->autoclaveEasylab = Strumento::factory()->forNode($this->sedeEasylab)->create(['nome' => 'Autoclave EasyLab']);
    Ricambio::factory()->forTenant($this->sedeEasylab)->create(['nome' => 'Guarnizione EasyLab']);

    $this->superadmin = User::factory()->create([
        'tenant_id' => $this->sedeRossi->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->superadmin->assignRole('Superadmin');
    $this->rossi->aggiungiMembro($this->superadmin);
});

// ─── 1. Il permesso: 403, non un elenco vuoto ────────────────────────────────

it('refuses to open without the platform permission', function (string $ruolo) {
    $utente = User::factory()->create(['tenant_id' => $this->sedeRossi->id]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente);

    // ⚠️ La catena **enumera i metodi a mano**, quindi va estesa a ogni lettore
    // nuovo: un ingresso non provato lascerebbe la suite tutta verde. Il test
    // «names every public method» qui sotto è la rete che lo rende impossibile
    // dimenticare.
    $perimetro = Perimetro::tutti();

    expect(fn () => ParcoClienti::selezionabili())->toThrow(AuthorizationException::class)
        ->and(fn () => ParcoClienti::clienti($perimetro))->toThrow(AuthorizationException::class)
        ->and(fn () => ParcoClienti::idClienti($perimetro))->toThrow(AuthorizationException::class)
        ->and(fn () => ParcoClienti::sedi($perimetro))->toThrow(AuthorizationException::class)
        ->and(fn () => ParcoClienti::idSedi($perimetro))->toThrow(AuthorizationException::class)
        ->and(fn () => ParcoClienti::strumenti($perimetro))->toThrow(AuthorizationException::class)
        ->and(fn () => ParcoClienti::interventi($perimetro))->toThrow(AuthorizationException::class)
        ->and(fn () => ParcoClienti::garanzie($perimetro))->toThrow(AuthorizationException::class)
        ->and(fn () => ParcoClienti::ricambi($perimetro))->toThrow(AuthorizationException::class);
})->with(RUOLI_SENZA_PIATTAFORMA);

it('refuses to open for a guest', function () {
    // Nessun utente: `Gate::authorize()` nega sempre. È anche la ragione per cui
    // questa porta non è usabile in console, nei job e nello scheduler — dove
    // però il confine non c'è già, quindi la query nuda è la forma corretta.
    expect(fn () => ParcoClienti::strumenti(Perimetro::tutti()))->toThrow(AuthorizationException::class)
        ->and(fn () => ParcoClienti::ricambi(Perimetro::tutti()))->toThrow(AuthorizationException::class);
});

it('throws instead of handing back an empty builder', function () {
    // Un builder vuoto si leggerebbe come «i clienti non hanno macchine»: la
    // forma peggiore di negare, perché è silenziosa e plausibile. 403, non zero.
    $admin = User::factory()->create(['tenant_id' => $this->sedeRossi->id]);
    $admin->assignRole('Admin');

    $this->actingAs($admin);

    expect(fn () => ParcoClienti::strumenti(Perimetro::tutti())->count())
        ->toThrow(AuthorizationException::class);
});

it('opens for both platform roles and for nobody else', function (string $ruolo) {
    $utente = User::factory()->create(['tenant_id' => $this->sedeRossi->id]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente);

    expect(ParcoClienti::strumenti(Perimetro::tutti())->count())->toBe(2);
})->with(RUOLI_CON_PIATTAFORMA);

it('keeps the permission the same one the placeholder components already declare', function () {
    // La costante si legge da `VistaPiattaforma` per non far dipendere
    // `app/Support` da `app/Livewire`. Il prezzo è che due costanti potrebbero
    // divergere in silenzio, e questo test è il pegno: se qualcuno desse al
    // parco un permesso proprio, lo scoprirebbe qui e non in produzione.
    expect(ParcoClienti::PERMESSO)->toBe('tenants.view_all')
        ->and(ParcoClienti::PERMESSO)->toBe(ParcoGlobale::PERMESSO)
        ->and(ParcoClienti::PERMESSO)->toBe(VistaPiattaforma::PERMESSO);
});

// ─── 2. Il perimetro: restringe o non cambia, mai allarga ────────────────────

it('never widens the perimeter with a forged account id', function () {
    // 🔴 Il perimetro è una proprietà pubblica di un componente Livewire: arriva
    // dal browser a ogni update, e un id si scrive a mano in due secondi. La
    // difesa non è una validazione ma la **forma** della query: il `whereIn` si
    // applica SOPRA `VistaPiattaforma::accounts()`, quindi è un'intersezione —
    // un id che non è là dentro non aggiunge niente, per costruzione.
    $this->actingAs($this->superadmin);

    $perimetro = Perimetro::scelti([$this->rossi->id, $this->bianchi->id + 9_999]);

    expect(ParcoClienti::clienti($perimetro)->pluck('ragione_sociale')->all())->toBe(['Gruppo Rossi'])
        ->and(ParcoClienti::strumenti($perimetro)->pluck('nome')->all())->toBe(['Autoclave Rossi'])
        ->and(ParcoClienti::ricambi($perimetro)->pluck('nome')->all())->toBe(['Guarnizione Rossi']);
});

it('keeps the platform account out, even when its id is picked on purpose', function () {
    // ⚠️ EasyLab non è un cliente, e sul database di sviluppo il suo Ente pesa
    // **1.217 strumenti su 5.105**: dentro un parco «di tutti i clienti»
    // sarebbero un quarto delle righe di nessuno. Il filtro non sta qui ma in
    // `VistaPiattaforma::accounts()`, e questo test prova che passarci sopra un
    // id scelto a mano non lo scavalca.
    $this->actingAs($this->superadmin);

    $scelto = Perimetro::scelti([$this->easylab->id]);
    $tutti = Perimetro::tutti();

    expect(ParcoClienti::clienti($scelto)->count())->toBe(0)
        ->and(ParcoClienti::strumenti($scelto)->count())->toBe(0)
        ->and(ParcoClienti::strumenti($tutti)->pluck('nome')->all())
        ->not->toContain('Autoclave EasyLab')
        ->and(ParcoClienti::ricambi($tutti)->pluck('nome')->all())
        ->not->toContain('Guarnizione EasyLab')
        ->and(ParcoClienti::selezionabili()->pluck('ragione_sociale')->all())
        ->not->toContain('EasyLab');
});

it('keeps a trashed account out, even when its id is still in a saved querystring', function () {
    // Il perimetro vive nell'URL (`#[Url]`), quindi un id sopravvive a un
    // segnalibro. `VistaPiattaforma::accounts()` ha il `SoftDeletingScope`
    // addosso perché gli scope si tolgono per NOME: un account cestinato non
    // torna in scena passando dal filtro.
    $this->actingAs($this->superadmin);

    $this->bianchi->delete();

    $perimetro = Perimetro::scelti([$this->rossi->id, $this->bianchi->id]);

    expect(ParcoClienti::clienti($perimetro)->pluck('ragione_sociale')->all())->toBe(['Gruppo Rossi'])
        ->and(ParcoClienti::strumenti($perimetro)->pluck('nome')->all())->toBe(['Autoclave Rossi'])
        ->and(ParcoClienti::strumenti(Perimetro::tutti())->pluck('nome')->all())
        ->not->toContain('Autoclave Bianchi');
});

it('never lets the per-plan perimeter admit another plan', function () {
    $this->actingAs($this->superadmin);

    $free = Perimetro::perPiano('free');
    $saas = Perimetro::perPiano('saas');

    expect(ParcoClienti::clienti($free)->pluck('ragione_sociale')->all())->toBe(['Gruppo Rossi'])
        ->and(ParcoClienti::clienti($saas)->pluck('ragione_sociale')->all())->toBe(['Lab Bianchi'])
        ->and(ParcoClienti::strumenti($free)->pluck('nome')->all())->toBe(['Autoclave Rossi'])
        ->and(ParcoClienti::interventi($saas)->pluck('descrizione')->all())->toBe(['Taratura Bianchi']);
});

it('falls back to nobody, never to everybody, on input it did not understand', function (Perimetro $perimetro) {
    // 🔴 **La regola centrale del perimetro.** Fra i due modi di sbagliare — non
    // mostrare abbastanza e mostrare troppo — su una vista cross-cliente il
    // secondo è quello che non si vede: la tabella è plausibile, ha solo più
    // righe del dovuto. Ogni input che non si è capito cade quindi
    // sull'insieme vuoto.
    $this->actingAs($this->superadmin);

    expect(ParcoClienti::clienti($perimetro)->count())->toBe(0)
        ->and(ParcoClienti::strumenti($perimetro)->count())->toBe(0)
        ->and(ParcoClienti::interventi($perimetro)->count())->toBe(0)
        ->and(ParcoClienti::garanzie($perimetro)->count())->toBe(0)
        ->and(ParcoClienti::ricambi($perimetro)->count())->toBe(0)
        ->and($perimetro->eNessuno())->toBeTrue();
})->with([
    'un piano che non è a catalogo' => fn () => Perimetro::perPiano('enterprise-inventato'),
    'un modo che non esiste' => fn () => Perimetro::daRichiesta('tutti-davvero'),
    'il modo per piano senza piano' => fn () => Perimetro::daRichiesta('piano'),
    'nessun modo affatto' => fn () => Perimetro::daRichiesta(null),
    'una selezione vuota' => fn () => Perimetro::scelti([]),
    'una selezione di soli id non validi' => fn () => Perimetro::scelti(['', '0', '-3', 'pippo']),
]);

it('reads an empty selection as nobody, never as no filter', function () {
    // ⛔ La trappola vista da dentro, e merita un test suo perché è la più facile
    // da sbagliare *leggendo*: «nessun cliente selezionato» somiglia moltissimo a
    // «nessun filtro attivo». Su questa vista la differenza fra le due letture è
    // fra zero righe e le righe di tutti. È la forma dell'export che ignorava i
    // filtri e portava via l'intero elenco.
    $this->actingAs($this->superadmin);

    expect(ParcoClienti::strumenti(Perimetro::scelti([]))->count())->toBe(0)
        ->and(ParcoClienti::strumenti(Perimetro::tutti())->count())->toBe(2);
});

it('normalises what the browser sends, without letting it in', function () {
    // Gli id di una `<select multiple>` arrivano come **stringhe**, e un id
    // ripetuto duplicherebbe le righe di un `whereIn` senza che si capisca
    // perché. La normalizzazione sta in `Perimetro`; l'appartenenza si decide
    // comunque in `ParcoClienti`, ed è il motivo per cui le due metà non sono la
    // stessa metà scritta due volte.
    $this->actingAs($this->superadmin);

    $perimetro = Perimetro::daRichiesta('scelti', [(string) $this->rossi->id, (string) $this->rossi->id]);

    expect($perimetro->accountIds)->toBe([$this->rossi->id])
        ->and(ParcoClienti::strumenti($perimetro)->pluck('nome')->all())->toBe(['Autoclave Rossi']);
});

// ─── 3. Il positivo: si vede il parco di tutti ───────────────────────────────

it('sees the rows of every client once opened', function () {
    $this->actingAs($this->superadmin);

    $tutti = Perimetro::tutti();

    expect(ParcoClienti::sedi($tutti)->pluck('nome')->all())
        ->toContain('Sede Rossi', 'Sede Bianchi')
        ->and(ParcoClienti::strumenti($tutti)->pluck('nome')->all())
        ->toContain('Autoclave Rossi', 'Autoclave Bianchi')
        ->and(ParcoClienti::interventi($tutti)->pluck('descrizione')->all())
        ->toContain('Taratura Rossi', 'Taratura Bianchi')
        ->and(ParcoClienti::ricambi($tutti)->pluck('nome')->all())
        ->toContain('Guarnizione Rossi', 'Guarnizione Bianchi')
        ->and(ParcoClienti::garanzie($tutti)->count())->toBe(2);
});

it('keeps the trash out, because the scopes come off by name', function () {
    // ⚠️ Il difetto già pagato in `Account::enti()`: con `withoutGlobalScopes()`
    // nudo se ne va anche `SoftDeletingScope`. Qui farebbe comparire nel parco di
    // un cliente le righe che quel cliente ha cestinato — cioè dati che nella sua
    // applicazione non esistono più.
    $this->actingAs($this->superadmin);

    Ricambio::withoutGlobalScopes()->where('nome', 'Guarnizione Bianchi')->first()->delete();
    Intervento::withoutGlobalScopes()->where('descrizione', 'Taratura Bianchi')->first()->delete();
    $this->autoclaveBianchi->delete();

    $tutti = Perimetro::tutti();

    expect(ParcoClienti::strumenti($tutti)->pluck('nome')->all())->toBe(['Autoclave Rossi'])
        ->and(ParcoClienti::interventi($tutti)->pluck('descrizione')->all())->toBe(['Taratura Rossi'])
        ->and(ParcoClienti::ricambi($tutti)->pluck('nome')->all())->toBe(['Guarnizione Rossi']);
});

it('keeps the ricambio privacy scope on, because it is not a tenancy scope', function () {
    // 🔴 **L'unico scope che la porta NON toglie**, e il test che lo congela.
    // `GaranziaRicambioPrivacyScope` risponde a «questo utente ha titolo a vedere
    // le garanzie dei pezzi montati?» (ADR-029), che resta una domanda sensata
    // anche cross-cliente. Toglierlo «per uniformità» concederebbe in silenzio
    // una categoria di righe a chi non l'ha mai avuta.
    //
    // Il ruolo su misura non è un artificio: `tenants.view_all` è un permesso, e
    // la matrice si modifica **a runtime** dall'editor ruoli — un ruolo con la
    // vista di piattaforma e senza le garanzie ricambio è a un click di distanza.
    $utilizzo = RicambioUtilizzo::create([
        'tenant_id' => $this->sedeBianchi->id,
        'strumento_id' => $this->autoclaveBianchi->id,
        'ricambio_id' => Ricambio::withoutGlobalScopes()->where('nome', 'Guarnizione Bianchi')->first()->id,
        'quantita' => 1,
        'data_montaggio' => today()->toDateString(),
    ]);

    Garanzia::factory()->forRicambio($utilizzo)->create();

    $ruolo = Role::findOrCreate('Osservatore Parco', 'web');
    $ruolo->givePermissionTo(Permission::findByName('tenants.view_all', 'web'));

    $osservatore = User::factory()->create(['tenant_id' => $this->sedeRossi->id]);
    $osservatore->assignRole($ruolo);

    $this->actingAs($this->superadmin);
    expect(ParcoClienti::garanzie(Perimetro::tutti())->count())->toBe(3);

    // ⛔ Il conteggio PRIMA di `each`: su una collezione vuota `each` è
    // soddisfatto sempre, e un'asserzione che non può fallire occupa il posto di
    // quella vera. È la stessa cicatrice di `toContain()` variadico.
    $this->actingAs($osservatore);
    $soggetti = ParcoClienti::garanzie(Perimetro::tutti())->pluck('soggetto');

    expect($soggetti)->toHaveCount(2)
        ->and($soggetti->all())->each->toBe(SoggettoGaranzia::Macchina);
});

// ─── 4. Non-trapelamento: la porta non allenta nulla per il resto ────────────

it('does not loosen the scope for the rest of the request', function () {
    // Aprire la porta per una vista non deve rendere non-scopato il resto: il
    // Superadmin è tenant-bound in ogni **altra** schermata (ADR-018), e resta
    // tale dopo aver guardato il parco di tutti.
    $this->actingAs($this->superadmin);

    expect(ParcoClienti::strumenti(Perimetro::tutti())->count())->toBe(2)
        ->and(Strumento::pluck('nome')->all())->toBe(['Autoclave Rossi'])
        ->and(Ricambio::pluck('nome')->all())->toBe(['Guarnizione Rossi']);
});

// ─── 5. La porta stessa: strutturale, ed è l'unica prova del gate ────────────

it('names every public method of the door, so none can be added without a negative', function () {
    // La rete che rende inutile ricordarsi di estendere la catena del primo
    // negativo: un lettore nuovo non provato è la peggiore delle omissioni, e
    // non ha alcun segnale.
    $pubblici = collect((new ReflectionClass(ParcoClienti::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === ParcoClienti::class)
        ->map(fn (ReflectionMethod $m) => $m->name)
        ->values()->all();

    expect($pubblici)->toEqualCanonicalizing([
        'selezionabili', 'clienti', 'idClienti', 'sedi', 'idSedi',
        'strumenti', 'interventi', 'garanzie', 'ricambi',
    ]);
});

it('gates every public method as its first statement', function () {
    // 🔴 **Il test che rende falsificabile il gate**, e va spiegato perché è
    // strutturale e non comportamentale. Ogni lettore attraversa comunque
    // `VistaPiattaforma`, che il permesso lo chiede: togliendo `self::porta()`
    // da un metodo, i negativi del punto 1 di questo file resterebbero **verdi**.
    // Un gate che nessun test può far diventare rosso non è una guardia, è un
    // commento — e la prima persona che «semplifica» la classe lo toglie senza
    // che niente lo dica.
    //
    // Serve davvero, e non è zelo: il giorno in cui un lettore non passasse più
    // dalla porta della cabina — un modello nuovo, una lettura diretta — questa
    // classe sarebbe **già** gatata invece di doverlo diventare. `interventi()`,
    // `garanzie()` e `ricambi()` tolgono gli scope da sé: è solo il `whereIn` sul
    // perimetro che oggi li fa passare comunque di là.
    //
    // ⚠️ Si chiede il **primo** statement e non la semplice presenza: un
    // `porta()` chiamato dopo aver costruito il builder autorizzerebbe a cose
    // fatte, e su un metodo che un domani leggesse prima di filtrare sarebbe
    // troppo tardi.
    $codice = collect(token_get_all(file_get_contents(app_path('Support/Piattaforma/ParcoClienti.php'))))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    $senzaPorta = collect((new ReflectionClass(ParcoClienti::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === ParcoClienti::class)
        ->map(fn (ReflectionMethod $m) => $m->name)
        ->reject(function (string $nome) use ($codice) {
            $firma = strpos($codice, 'function'.$nome.'(');

            if ($firma === false) {
                return false;
            }

            $corpo = strpos($codice, '{', $firma);

            return $corpo !== false && str_starts_with(substr($codice, $corpo + 1), 'self::porta();');
        })
        ->values();

    expect($senzaPorta->all())->toBe(
        [],
        "Un lettore del Parco clienti non apre con `self::porta()`.\n\n".
        "Il gate su `tenants.view_all` deve essere il PRIMO statement di ogni metodo pubblico: è ciò che\n".
        "rende la classe già corretta il giorno in cui un lettore smettesse di passare da `VistaPiattaforma`\n".
        "(un modello nuovo, una lettura diretta). Nessun test comportamentale se ne accorgerebbe, perché\n".
        "oggi il permesso viene chiesto comunque un livello più in là.\n\n".
        'Senza porta: '.$senzaPorta->implode(', ')
    );
});
