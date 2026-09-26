<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Piattaforma\ParcoClienti;
use App\Support\Piattaforma\Perimetro;
use App\Support\Piattaforma\Preferiti;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * 🔴 I **clienti preferiti** (🔗 ADR-037), area rossa come la porta che serve.
 *
 * `Preferiti` è l'unico posto che **scrive** a partire da un id di Account
 * arrivato dal browser, ed è l'unico costruttore del perimetro omonimo. I
 * negativi contano quindi più dei positivi, e sono di quattro specie:
 *
 *   1. **Il permesso**: senza `tenants.view_all` non si legge e non si scrive —
 *      e il rifiuto è un'eccezione, non un insieme vuoto che si leggerebbe come
 *      «non hai preferiti».
 *   2. **La persona**: i preferiti sono di chi guarda. Quelli di un Superadmin
 *      non devono comparire a un altro, o sarebbero una proprietà del cliente.
 *   3. **L'oggetto**: un id che non è di un cliente visibile (inesistente,
 *      cestinato, l'account di piattaforma) non diventa una riga di pivot.
 *   4. **L'intersezione**: un preferito segnato quando l'account era vivo
 *      sopravvive al suo cestinamento, e **non deve** portare righe.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->rossi = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sedeRossi = UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede Rossi']);

    $this->bianchi = Account::factory()->create(['ragione_sociale' => 'Lab Bianchi']);
    $this->sedeBianchi = UnitaOrganizzativa::factory()->ente()->perAccount($this->bianchi)->create(['nome' => 'Sede Bianchi']);

    $this->easylab = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);

    $this->superadmin = User::factory()->create([
        'tenant_id' => $this->sedeRossi->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->superadmin->assignRole('Superadmin');
    $this->rossi->aggiungiMembro($this->superadmin);
});

// ─── 1. Il permesso: un'eccezione, non un elenco vuoto ───────────────────────

it('refuses every entry point without the platform permission', function (string $ruolo) {
    $utente = User::factory()->create(['tenant_id' => $this->sedeRossi->id]);
    $utente->assignRole($ruolo);

    $this->actingAs($utente);

    expect(fn () => Preferiti::id())->toThrow(AuthorizationException::class)
        ->and(fn () => Preferiti::clienti())->toThrow(AuthorizationException::class)
        ->and(fn () => Preferiti::perimetro())->toThrow(AuthorizationException::class)
        ->and(fn () => Preferiti::contiene($this->bianchi->id))->toThrow(AuthorizationException::class)
        ->and(fn () => Preferiti::alterna($this->bianchi->id))->toThrow(AuthorizationException::class);

    // ⚠️ E il rifiuto deve essere **prima** della scrittura: un'eccezione dopo
    // un `toggle()` andato a buon fine lascerebbe la riga in tabella.
    $this->assertDatabaseMissing('clienti_preferiti', ['user_id' => $utente->id]);
})->with(['Admin', 'Tecnico', 'Tenant']);

it('names the same permission as the parco door, so the two cannot diverge', function () {
    expect(Preferiti::PERMESSO)->toBe(ParcoClienti::PERMESSO);
});

// ─── 2. La persona: i preferiti non sono un attributo del cliente ────────────

it('keeps favourites personal: another superadmin sees their own', function () {
    $altro = User::factory()->create([
        'tenant_id' => $this->sedeBianchi->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $altro->assignRole('Superadmin');
    $this->bianchi->aggiungiMembro($altro);

    $this->actingAs($this->superadmin);
    Preferiti::alterna($this->rossi->id);

    $this->actingAs($altro);
    Preferiti::alterna($this->bianchi->id);

    expect(Preferiti::id())->toBe([$this->bianchi->id]);

    $this->actingAs($this->superadmin);
    expect(Preferiti::id())->toBe([$this->rossi->id]);
});

it('toggles on and off, and says which of the two happened', function () {
    $this->actingAs($this->superadmin);

    expect(Preferiti::alterna($this->bianchi->id))->toBeTrue()
        ->and(Preferiti::contiene($this->bianchi->id))->toBeTrue();

    $this->assertDatabaseHas('clienti_preferiti', [
        'user_id' => $this->superadmin->id,
        'account_id' => $this->bianchi->id,
    ]);

    expect(Preferiti::alterna($this->bianchi->id))->toBeFalse()
        ->and(Preferiti::contiene($this->bianchi->id))->toBeFalse();

    $this->assertDatabaseMissing('clienti_preferiti', [
        'user_id' => $this->superadmin->id,
        'account_id' => $this->bianchi->id,
    ]);
});

it('orders favourites by ragione sociale, with the id as tie-break', function () {
    // ⚠️ Due omonimi: senza il tie-break sull'id l'ordine fra i pari è una
    // proprietà del motore, e Postgres non promette quello di SQLite.
    $primo = Account::factory()->create(['ragione_sociale' => 'Alfa Lab']);
    $secondo = Account::factory()->create(['ragione_sociale' => 'Alfa Lab']);

    $this->actingAs($this->superadmin);

    Preferiti::alterna($secondo->id);
    Preferiti::alterna($this->rossi->id);
    Preferiti::alterna($primo->id);

    expect(Preferiti::id())->toBe([$primo->id, $secondo->id, $this->rossi->id]);
});

// ─── 3. L'oggetto: chi non è un cliente visibile non si segna ────────────────

it('refuses to bookmark anything that is not a visible client', function (string $quale) {
    $this->actingAs($this->superadmin);

    $id = match ($quale) {
        'inesistente' => 999999,
        'piattaforma' => $this->easylab->id,
        'cestinato' => tap(Account::factory()->create(['ragione_sociale' => 'Ex Cliente']), fn ($a) => $a->delete())->id,
    };

    // Nessuna eccezione: chi manda questo id non sta usando la pagina, e non
    // c'è niente da dirgli. Ma nemmeno una riga in tabella.
    expect(Preferiti::alterna($id))->toBeFalse()
        ->and(Preferiti::id())->toBe([]);

    $this->assertDatabaseMissing('clienti_preferiti', ['account_id' => $id]);
})->with(['inesistente', 'piattaforma', 'cestinato']);

// ─── 4. L'intersezione: il perimetro non allarga mai ─────────────────────────

it('builds a perimeter that only holds the clients still visible', function () {
    $this->actingAs($this->superadmin);

    Preferiti::alterna($this->rossi->id);
    Preferiti::alterna($this->bianchi->id);

    // Il preferito era legittimo quando è stato segnato: è il cliente a essere
    // finito nel cestino DOPO. La riga di pivot resta, le sue non devono.
    $this->bianchi->delete();

    // La riga di pivot resta: nessuno l'ha tolta, e non deve sparire da sola —
    // un cliente si può ripescare dal cestino.
    $this->assertDatabaseHas('clienti_preferiti', [
        'user_id' => $this->superadmin->id,
        'account_id' => $this->bianchi->id,
    ]);

    $perimetro = Preferiti::perimetro();

    expect($perimetro->modo)->toBe(Perimetro::PREFERITI);

    // ⚠️ Due difese in fila, e servono **entrambe**: la relazione porta il
    // `SoftDeletingScope` di `Account`, quindi il cestinato non arriva nemmeno
    // nel perimetro; e se un giorno arrivasse, l'intersezione di
    // `ParcoClienti::clienti()` non gli farebbe comunque produrre righe.
    expect($perimetro->accountIds)->not->toContain($this->bianchi->id);

    $visti = ParcoClienti::clienti($perimetro)->pluck('accounts.id')->all();

    expect($visti)->toBe([$this->rossi->id]);

    // E i preferiti «veri» — quelli che si mostrano a schermo — sono uno solo:
    // dire «2 preferiti» sopra una tabella costruita su 1 sarebbe una bugia.
    expect(Preferiti::clienti()->pluck('id')->all())->toBe([$this->rossi->id]);
});

it('is empty, never everyone, when nothing has been bookmarked', function () {
    $this->actingAs($this->superadmin);

    $perimetro = Preferiti::perimetro();

    expect($perimetro->eNessuno())->toBeTrue()
        ->and(ParcoClienti::clienti($perimetro)->count())->toBe(0);
});

// ─── 5. `Perimetro`: la forma, e da dove NON si costruisce ───────────────────

it('normalises the ids of a favourites perimeter like a hand-picked one', function () {
    // Interi, deduplicati, positivi: la stessa lista deve dare lo stesso
    // perimetro comunque ci sia arrivata.
    $perimetro = Perimetro::preferiti(['7', 7, '0', -3, '12']);

    expect($perimetro->accountIds)->toBe([7, 12])
        ->and($perimetro->modo)->toBe(Perimetro::PREFERITI);
});

it('never builds the favourites perimeter from browser input', function () {
    // 🔴 `daRichiesta()` riceve l'array pubblico di un componente Livewire. Se
    // sapesse costruire questo modo, il browser potrebbe dettare la lista che
    // il modo esiste per sottrargli: cade su `nessuno()`, cioè zero righe.
    $perimetro = Perimetro::daRichiesta(Perimetro::PREFERITI, ['1', '2', '3']);

    expect($perimetro->accountIds)->toBe([])
        ->and($perimetro->eNessuno())->toBeTrue();
});

it('reports an empty favourites perimeter as empty, so the page can say so', function () {
    expect(Perimetro::preferiti([])->eNessuno())->toBeTrue()
        ->and(Perimetro::preferiti([5])->eNessuno())->toBeFalse();
});

// ─── 6. Il gate, che è falsificabile solo per via STRUTTURALE ────────────────

it('names every public method, so a new one cannot be forgotten by the gate test', function () {
    $pubblici = collect((new ReflectionClass(Preferiti::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === Preferiti::class)
        ->map(fn (ReflectionMethod $m) => $m->name)
        ->values();

    // La rete della rete: il test qui sotto enumera i metodi per riflessione,
    // quindi coprirebbe da sé anche i futuri — ma questa lista è il posto in
    // cui **accorgersi** che ne è nato uno, e chiedersi se la sua porta è
    // quella giusta invece di scoprirlo dal fatto che la suite è verde.
    expect($pubblici->all())->toEqualCanonicalizing([
        'id', 'clienti', 'perimetro', 'contiene', 'alterna',
    ]);
});

it('gates every public method as its first statement', function () {
    // 🔴 **Il test che rende falsificabile il gate**, gemello di quello di
    // `ParcoClientiTest` e per la stessa ragione: togliendo `self::porta()` da
    // `alterna()` i negativi sul permesso di questo file restano **verdi** —
    // perché `VistaPiattaforma::accounts()`, un livello più in là, il permesso
    // lo chiede comunque. Un gate che nessun test può far diventare rosso non è
    // una guardia, è un commento.
    //
    // Qui pesa più che altrove: `alterna()` **scrive**, ed è l'unico metodo del
    // pacchetto che lo fa. Il giorno in cui la rilettura dell'account cambiasse
    // forma — o venisse tolta perché «tanto l'intersezione filtra» — questa
    // classe resterebbe gatata invece di doverlo ridiventare.
    //
    // ⚠️ Si chiede il **primo** statement e non la presenza: autorizzare dopo
    // aver scritto è autorizzare a cose fatte.
    $codice = collect(token_get_all(file_get_contents(app_path('Support/Piattaforma/Preferiti.php'))))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    $senzaPorta = collect((new ReflectionClass(Preferiti::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === Preferiti::class)
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
        "Un metodo pubblico di `Preferiti` non apre con `self::porta()`.\n\n".
        "Il gate su `tenants.view_all` deve essere il PRIMO statement: oggi il permesso viene chiesto\n".
        "comunque da `VistaPiattaforma` un livello più in là, quindi nessun test comportamentale se ne\n".
        "accorgerebbe — e `alterna()` scrive.\n\n".
        'Senza porta: '.$senzaPorta->implode(', ')
    );
});
