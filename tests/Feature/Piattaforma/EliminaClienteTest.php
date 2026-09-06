<?php

use App\Livewire\Piattaforma\Cabina;
use App\Livewire\Piattaforma\Concerns\AmministraAccount;
use App\Models\Account;
use App\Models\AvvisoScadenza;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\AccountEliminato;
use App\Support\AuditLog;
use App\Support\Piattaforma\EliminaCliente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 L'eliminazione **definitiva** di un cliente (🔗 ADR-040).
 *
 * **Area rossa doppia**: distrugge dati senza ripristino, e tocca denaro. I
 * negativi sono la maggioranza del file, e il più importante non è una guardia
 * ma un **inventario**: che dopo il gesto non resti niente in nessuna tabella.
 * Una tabella dimenticata non dà errore — `avvisi_scadenza` non ha nemmeno la
 * FK — quindi il difetto tipico di questo codice è silenzioso, e solo un test
 * che le nomina tutte lo rende rumoroso.
 *
 * Le leve della cabina e la loro autorizzazione stanno in
 * `LeveAmministrativeTest`; qui si prova cosa succede **dopo** il click.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    $this->cliente = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)
        ->create(['nome' => 'Sede di Milano']);
});

/** Un cliente con dentro una riga per ciascuna tabella che l'eliminazione deve svuotare. */
function clientePieno(Account $account, UnitaOrganizzativa $sede): Strumento
{
    $strumento = Strumento::factory()->forNode($sede)->create();
    $intervento = Intervento::factory()->forStrumento($strumento)->create();
    $ricambio = Ricambio::factory()->forTenant($sede)->create();

    Documento::factory()->perStrumento($strumento)->create();
    Garanzia::factory()->forStrumento($strumento)->create();
    SpostamentoStrumento::factory()->forStrumento($strumento)->create();
    Fornitore::factory()->forTenant($sede)->create();

    RicambioUtilizzo::factory()
        ->forStrumento($strumento)->forRicambio($ricambio)->forIntervento($intervento)
        ->create();

    // ⚠️ A mano e non da factory: `AvvisoScadenza` non ne ha una, ed è **la
    // tabella che conta di più** in questo test — il suo `tenant_id` non è una
    // FK ma un indice denormalizzato, quindi dimenticarla nell'eliminazione non
    // produce nessun errore, solo righe orfane che il digest continua a leggere.
    AvvisoScadenza::query()->create([
        'tenant_id' => $sede->id,
        'riferimento_type' => Intervento::class,
        'riferimento_id' => $intervento->id,
        'transizione' => 'imminente',
        'data_scadenza' => today()->addDays(10),
    ]);

    return $strumento;
}

// ─── L'inventario: non deve restare niente ───────────────────────────────────

it('leaves not a single row behind, in any table that carried that tenant', function () {
    // ⛔ **Il test che conta più di tutti**, e scritto scorrendo l'elenco invece
    // che a campione: una tabella dimenticata è precisamente il difetto che
    // questo gesto può avere, e metà di esse non protesterebbero.
    Notification::fake();
    Storage::fake('documenti');

    clientePieno($this->cliente, $this->sede);
    $tenantId = $this->sede->id;

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    // ⛔ **L'elenco è scritto QUI, non letto da `EliminaCliente::TABELLE`.**
    // Scorrere la costante del codice sotto esame renderebbe il test cieco
    // proprio al difetto che deve cogliere: togliendo una voce da lì,
    // l'inventario smetterebbe di controllarla e resterebbe verde. Provato
    // rompendolo. Due elenchi che devono coincidere sono il punto: se
    // divergono, uno dei due è sbagliato e il test lo dice.
    $tabelle = [
        'ricambio_utilizzo', 'garanzie', 'documenti', 'spostamenti_strumento',
        'interventi', 'strumenti', 'ricambi', 'fornitori', 'avvisi_scadenza',
    ];

    foreach ($tabelle as $tabella) {
        expect(DB::table($tabella)->where('tenant_id', $tenantId)->count())
            ->toBe(0, "Sono rimaste righe in «{$tabella}»");
    }

    // E che i due elenchi non divergano: una tabella aggiunta al codice senza
    // essere aggiunta qui sarebbe una riga non provata da nessuno.
    expect(EliminaCliente::TABELLE)->toBe($tabelle);

    expect(DB::table('unita_organizzativa')->where('id', $tenantId)->count())->toBe(0)
        ->and(Account::withTrashed()->whereKey($this->cliente->id)->count())->toBe(0);
});

it('empties the tables that the database would never complain about', function () {
    // ⚠️ Il gemello del test sopra, e non è un doppione: `avvisi_scadenza` non
    // ha una FK verso `unita_organizzativa`, quindi togliendola dall'elenco il
    // test di sopra resterebbe **verde su tutte le altre** e nessun vincolo
    // protesterebbe. Nominata da sola, l'omissione diventa rossa.
    Notification::fake();
    Storage::fake('documenti');

    clientePieno($this->cliente, $this->sede);
    $tenantId = $this->sede->id;

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    expect(AvvisoScadenza::query()->where('tenant_id', $tenantId)->count())->toBe(0);
});

it('deletes the whole tree of sedi, leaves first', function () {
    // `unita_organizzativa.parent_id` punta a sé stessa con RESTRICT: cancellare
    // un nodo che ha ancora figli sbatte sulla FK. L'ordine di creazione non è
    // l'ordine dell'albero, quindi un `orderByDesc('id')` non basterebbe.
    Notification::fake();
    Storage::fake('documenti');

    $reparto = UnitaOrganizzativa::factory()->perAccount($this->cliente)
        ->create(['parent_id' => $this->sede->id, 'tenant_id' => $this->sede->id]);

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    expect(UnitaOrganizzativa::query()->whereIn('id', [$this->sede->id, $reparto->id])->count())->toBe(0);
});

// ─── Le persone ──────────────────────────────────────────────────────────────

it('deletes the people left without any contract, which is what frees their email', function () {
    // 🔴 La ragione per cui questa funzione esiste: `users.email` è unique senza
    // condizione (ADR-038), quindi un utente vivo e senza contratto rifiuterebbe
    // per sempre ogni nuova registrazione con quella casella.
    Notification::fake();
    Storage::fake('documenti');

    $admin = User::factory()->create(['email' => 'admin@gruppo-rossi.it']);
    $this->cliente->aggiungiMembro($admin);

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    expect(User::withTrashed()->where('email', 'admin@gruppo-rossi.it')->count())->toBe(0);
});

it('never deletes a person who also belongs to another customer', function () {
    // ⛔ Il negativo che protegge un tecnico EasyLab o un consulente su due
    // clienti: eliminarne uno non deve farlo sparire dall'altro.
    Notification::fake();
    Storage::fake('documenti');

    $altro = Account::factory()->create(['ragione_sociale' => 'Bianchi SRL']);
    $condiviso = User::factory()->create(['email' => 'condiviso@easylab.it']);

    $this->cliente->aggiungiMembro($condiviso);
    $altro->aggiungiMembro($condiviso);

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    expect(User::query()->where('email', 'condiviso@easylab.it')->count())->toBe(1)
        ->and($condiviso->fresh()->accounts()->count())->toBe(1);
});

// ─── L'avviso al cliente ─────────────────────────────────────────────────────

it('warns everyone who had access, at the address collected before the deletion', function () {
    // ⚠️ Gli indirizzi si raccolgono **prima** e si notificano **dopo**: quando
    // l'email parte l'utente non esiste più, quindi `$user->notify()` non
    // avrebbe nessuno a cui riferirsi.
    Notification::fake();
    Storage::fake('documenti');

    $admin = User::factory()->create(['email' => 'admin@gruppo-rossi.it']);
    $this->cliente->aggiungiMembro($admin);

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    Notification::assertSentOnDemand(
        AccountEliminato::class,
        fn ($notifica, $canali, $notifiable) => $notifiable->routes['mail'] === 'admin@gruppo-rossi.it'
    );
});

// ─── I servizi esterni non fermano il gesto ──────────────────────────────────

it('keeps the deletion done when the file archive fails, and says what is left over', function () {
    // ⛔ La dottrina: annullare un'eliminazione perché un terzo non risponde
    // legherebbe un gesto di dominio alla disponibilità di un servizio esterno —
    // e a quel punto i dati sono già andati. Il guasto si **dice**, con il
    // prefisso da rimuovere a mano.
    Notification::fake();

    Storage::shouldReceive('disk')->with('documenti')->andReturnSelf();
    Storage::shouldReceive('deleteDirectory')->andThrow(new RuntimeException('B2 non risponde'));

    $guasti = app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    expect(Account::withTrashed()->whereKey($this->cliente->id)->count())->toBe(0)
        ->and($guasti)->not->toBeEmpty()
        // Il prefisso, non un generico «errore»: è la stringa che si va a
        // cercare nel bucket.
        ->and($guasti[0])->toContain("tenant-{$this->sede->id}");
});

// ─── L'audit ─────────────────────────────────────────────────────────────────

it('records what was destroyed, because nothing else survives to answer that', function () {
    // Le righe non ci sono più e nessun backup le riporta: i **conteggi** nel
    // registro sono la sola cosa che resta a rispondere «cosa c'era».
    Notification::fake();
    Storage::fake('documenti');

    clientePieno($this->cliente, $this->sede);

    app(EliminaCliente::class)->esegui($this->cliente->fresh(), $this->superadmin);

    $riga = Activity::query()->where('log_name', AuditLog::NAME)
        ->where('description', 'Cliente eliminato definitivamente')->latest('id')->firstOrFail();

    expect($riga->causer_id)->toBe($this->superadmin->id)
        ->and($riga->properties['ragione_sociale'])->toBe('Gruppo Rossi')
        ->and($riga->properties['strumenti'])->toBe(1)
        ->and($riga->properties['interventi'])->toBe(1);
});

// ─── La conferma digitata ────────────────────────────────────────────────────

it('refuses to delete anything when the typed name does not match', function () {
    // ⛔ Il negativo della conferma: protegge dal click sulla riga sbagliata e
    // dal doppio invio, che è il modo in cui questi incidenti accadono davvero.
    Notification::fake();

    Livewire::test(Cabina::class)
        ->call('apriEliminazione', $this->cliente->id)
        ->set('confermaEliminazione', 'Gruppo Bianchi')
        ->call('eliminaAccount')
        ->assertHasErrors('confermaEliminazione');

    expect(Account::whereKey($this->cliente->id)->count())->toBe(1);
});

it('accepts the name however it was capitalised or padded', function () {
    // Pretendere la maiuscola esatta punirebbe un copia-incolla corretto senza
    // proteggere da niente: ciò che la riscrittura verifica è «hai guardato
    // quale riga stai eliminando», non «sai scrivere».
    Notification::fake();
    Storage::fake('documenti');

    Livewire::test(Cabina::class)
        ->call('apriEliminazione', $this->cliente->id)
        ->set('confermaEliminazione', '  gruppo rossi  ')
        ->call('eliminaAccount')
        ->assertHasNoErrors();

    expect(Account::withTrashed()->whereKey($this->cliente->id)->count())->toBe(0);
});

it('asks for the admin email when the customer has no ragione sociale', function () {
    // Succede: un account nato da un Payment Link prende la ragione sociale da
    // un campo di Stripe, che può arrivare vuoto. Chiedere di riscrivere una
    // stringa vuota renderebbe quel cliente **non eliminabile**, cioè
    // esattamente il vicolo cieco da cui questa funzione è nata.
    Notification::fake();
    Storage::fake('documenti');

    $senzaNome = Account::factory()->create(['ragione_sociale' => '']);
    UnitaOrganizzativa::factory()->ente()->perAccount($senzaNome)->create();
    $admin = User::factory()->create(['email' => 'solo@email.it']);
    $senzaNome->aggiungiMembro($admin);

    expect(AmministraAccount::parolaDiConferma($senzaNome))
        ->toBe('solo@email.it');

    Livewire::test(Cabina::class)
        ->call('apriEliminazione', $senzaNome->id)
        ->set('confermaEliminazione', 'solo@email.it')
        ->call('eliminaAccount')
        ->assertHasNoErrors();

    expect(Account::withTrashed()->whereKey($senzaNome->id)->count())->toBe(0);
});

// ─── L'autorizzazione ────────────────────────────────────────────────────────

it('never lets someone who may see the page delete a customer, with or without the property', function () {
    // 🔴 Il gemello dei negativi di `LeveAmministrativeTest`: `$wire.set()` non
    // passa dall'azione, quindi impostare la property e chiamare il metodo è la
    // strada che aggira una guardia scritta solo in `apriEliminazione()`.
    $developer = utenteConRuolo('Tecnico');
    $developer->givePermissionTo('tenants.view_all');
    $this->actingAs($developer->fresh());

    Livewire::test(Cabina::class)
        ->set('accountInLavorazione', $this->cliente->id)
        ->set('pannello', 'eliminazione')
        ->set('confermaEliminazione', 'Gruppo Rossi')
        ->call('eliminaAccount')
        ->assertForbidden();

    expect(Account::whereKey($this->cliente->id)->count())->toBe(1);
});

it('never deletes the platform account itself', function () {
    // Lo esclude già `VistaPiattaforma::accounts()`, ma la difesa va provata
    // dove la si esercita: EasyLab non è un cliente, e cancellarla porterebbe
    // via i tecnici di piattaforma insieme al proprio contratto.
    $piattaforma = Account::factory()->create([
        'ragione_sociale' => 'EasyLab',
        'di_piattaforma' => true,
    ]);
    UnitaOrganizzativa::factory()->ente()->perAccount($piattaforma)->create();

    // ⚠️ `ModelNotFoundException` e non un 403: la porta non lo **trova**
    // affatto, quindi non c'è nessuna ability da negare. È la stessa forma che
    // `LeveAmministrativeTest` prova già per le altre leve — fail-closed prima
    // della Gate, non dopo.
    expect(fn () => Livewire::test(Cabina::class)->call('apriEliminazione', $piattaforma->id))
        ->toThrow(ModelNotFoundException::class);

    expect(Account::whereKey($piattaforma->id)->count())->toBe(1);
});
