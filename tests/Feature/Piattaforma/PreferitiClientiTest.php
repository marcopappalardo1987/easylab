<?php

use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * La ★ dei clienti preferiti nell'elenco Clienti della cabina (🔗 ADR-037).
 *
 * Il gesto è piccolo — un clic che accende una stella — ma **scrive** a partire
 * da un id che arriva dal browser, dentro l'unica schermata del progetto che
 * guarda oltre il proprio Ente (🔗 ADR-018). I casi che contano sono quindi i
 * negativi: chi non può, cosa non deve finire in tabella, e che la preferenza
 * resti **della persona** e non del cliente.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    $this->rossi = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($this->rossi)->create(['nome' => 'Sede di Milano']);

    $this->bianchi = Account::factory()->create(['ragione_sociale' => 'Bianchi SRL']);
    UnitaOrganizzativa::factory()->ente()->perAccount($this->bianchi)->create(['nome' => 'Sede di Bergamo']);
});

// --- Negativi: chi non può, e cosa non deve finire in tabella ---

it('refuses the star to whoever does not hold the platform permission', function () {
    $estraneo = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->actingAs($estraneo->fresh());

    // ⚠️ **Chiamata sull'istanza nuda e non da `Livewire::test()`**, e la ragione
    // è che qui si misura *l'azione*: `tenants.view_all` è lo stesso permesso
    // che gata il render, quindi montare il componente esploderebbe prima di
    // arrivare all'azione e il caso sarebbe verde anche con l'azione aperta.
    // Così invece si prova esattamente ciò che l'affermazione dice.
    expect(fn () => (new Cabina)->alternaPreferito($this->rossi->id))
        ->toThrow(AuthorizationException::class);

    // 🔴 E non un no-op silenzioso: se l'eccezione un giorno sparisse, questa
    // riga direbbe comunque che non si è scritto. Le due asserzioni coprono i
    // due modi in cui la guardia può cedere.
    $this->assertDatabaseMissing('clienti_preferiti', [
        'user_id' => $estraneo->id,
        'account_id' => $this->rossi->id,
    ]);
});

it('never stores a forged account id', function () {
    // Tre forme dello stesso id forgiato, tutte raggiungibili con un `$wire`:
    // inesistente, cestinato, e l'account **di piattaforma** — che esiste, non è
    // cestinato, e cliente non è. Nessuna delle tre trapela righe (l'intersezione
    // del Parco le scarterebbe), ma tutte lascerebbero in tabella un preferito
    // che nessuna schermata sa disegnare: invisibile e quindi non togliibile.
    $cestinato = Account::factory()->create(['ragione_sociale' => 'Ex Cliente']);
    $cestinato->delete();

    $piattaforma = Account::factory()->diPiattaforma()->create(['ragione_sociale' => 'EasyLab']);

    $inesistente = Account::max('id') + 999;

    $t = Livewire::test(Cabina::class);

    foreach ([$inesistente, $cestinato->id, $piattaforma->id] as $forgiato) {
        // Non solleva: chi manda questo id non stava usando la pagina, e non
        // c'è niente da dirgli. Fail-closed e muto (docblock di `Preferiti`).
        $t->call('alternaPreferito', $forgiato)->assertOk();

        $this->assertDatabaseMissing('clienti_preferiti', [
            'user_id' => $this->superadmin->id,
            'account_id' => $forgiato,
        ]);
    }

    expect(DB::table('clienti_preferiti')->count())->toBe(0);
});

it('keeps one row when the same id arrives twice', function () {
    // Il doppio clic — o il doppio invio di una risposta lenta — non deve
    // produrre due righe: l'unique del pivot lo impedirebbe con un errore, e
    // `toggle()` non ci arriva nemmeno. Qui si prova l'esito visibile.
    Livewire::test(Cabina::class)
        ->call('alternaPreferito', $this->rossi->id)
        ->call('alternaPreferito', $this->rossi->id)
        ->call('alternaPreferito', $this->rossi->id);

    // Numero **dispari** di invii: acceso, spento, acceso. Una riga sola.
    expect(DB::table('clienti_preferiti')
        ->where('user_id', $this->superadmin->id)
        ->where('account_id', $this->rossi->id)
        ->count())->toBe(1);
});

// --- La stella è di chi guarda ---

it('turns the star on and off, and keeps it personal', function () {
    $t = Livewire::test(Cabina::class);

    expect($t->instance()->ePreferito($this->rossi->id))->toBeFalse();

    $t->call('alternaPreferito', $this->rossi->id);

    // ⚠️ Letto **dopo** l'azione sullo stesso ciclo: è il caso che il memo di
    // `SegnaPreferiti` può sbagliare, restituendo lo stato di prima del clic.
    expect($t->instance()->ePreferito($this->rossi->id))->toBeTrue()
        ->and($t->instance()->ePreferito($this->bianchi->id))->toBeFalse();

    $t->call('alternaPreferito', $this->rossi->id);

    expect($t->instance()->ePreferito($this->rossi->id))->toBeFalse();
});

it('shows a different star to a second superadmin on the same page', function () {
    // 🔴 Il cuore della scelta di ADR-037: la riga lega una PERSONA a un Account.
    // Un flag su `accounts` renderebbe «preferito» un attributo del rapporto
    // commerciale, e i due colleghi si sovrascriverebbero a vicenda.
    Livewire::test(Cabina::class)->call('alternaPreferito', $this->rossi->id);

    $collega = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $collega->assignRole('Superadmin');
    $this->actingAs($collega->fresh());

    $suo = Livewire::test(Cabina::class);

    expect($suo->instance()->ePreferito($this->rossi->id))->toBeFalse();

    $suo->call('alternaPreferito', $this->bianchi->id);

    expect($suo->instance()->ePreferito($this->bianchi->id))->toBeTrue();

    // E il primo non ha cambiato idea: due righe, una per persona.
    $this->actingAs($this->superadmin->fresh());

    expect(Livewire::test(Cabina::class)->instance()->ePreferito($this->rossi->id))->toBeTrue();
});

it('marks the starred row in the markup, readable without colour', function () {
    // ⛔ **Il glifo si cerca DENTRO la sua riga, mai da solo.** Un
    // `assertSeeHtml('★')` nudo qui non poteva fallire: la stella compare anche
    // nella riga di spiegazione sopra la tabella («La ★ mette un cliente fra i
    // tuoi preferiti»), quindi l'asserzione era soddisfatta pure con un pulsante
    // che disegnava sempre ☆. Provato rompendo la vista: la suite restava verde.
    // È la stessa forma di difetto dell'`assertSee` su una stringa presente
    // altrove nella pagina, e in questo progetto è già costata due volte.
    $html = Livewire::test(Cabina::class)
        ->call('alternaPreferito', $this->rossi->id)
        ->html();

    // ⚠️ Si **ritaglia il pulsante** invece di cercare nella pagina intera, e
    // non basta una regex con `.*?`: fra la stella di un cliente e quella del
    // successivo c'è tutto il resto della riga, quindi un ago «pieno» trovato
    // dopo la `wire:key` di Rossi potrebbe benissimo essere il glifo di
    // Bianchi. Il confine è `</button>`.
    $pulsante = function (int $id) use ($html): string {
        $da = mb_strpos($html, 'wire:key="preferito-'.$id.'"');

        expect($da)->not->toBeFalse('La riga di questo cliente non ha affatto la stella.');

        return mb_substr($html, $da, mb_strpos($html, '</button>', $da) - $da);
    };

    // Lo stato annunciato a chi la pagina non la vede (`aria-pressed`) e il
    // glifo che lo dice a chi la vede in bianco e nero. Il colore accompagna,
    // non informa — e le negative sono ciò che rende le positive falsificabili.
    expect($pulsante($this->rossi->id))
        ->toContain('aria-pressed="true"')
        ->toContain('★')
        ->not->toContain('☆');

    expect($pulsante($this->bianchi->id))
        ->toContain('aria-pressed="false"')
        ->toContain('☆')
        ->not->toContain('★');

    expect($html)->toContain('aria-label="Togli dai preferiti: Gruppo Rossi"')
        ->and($html)->toContain('aria-label="Aggiungi ai preferiti: Bianchi SRL"');
});

// --- Costo ---

it('reads the starred ids in one query, not one per row', function () {
    // ⚠️ Scaldare la cache dei permessi prima di misurare: al primo render
    // spatie carica ruoli e permessi, e senza questa riga il confronto
    // misurerebbe l'ordine dei due render invece del numero di clienti.
    // (Stessa precauzione del conteggio di `TabellaClientiTest`.)
    Livewire::test(Cabina::class);

    $conta = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Cabina::class);
        // Filtrato sulla sola tabella del pivot, come ogni altro conteggio del
        // progetto: un totale assoluto conta anche sessione e permessi, che
        // variano fra driver e fra prima e seconda chiamata.
        $n = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'clienti_preferiti'))
            ->count();
        DB::disableQueryLog();

        return $n;
    };

    expect($conta())->toBe(1);

    // Dieci clienti in più in pagina: con un `exists()` per riga sarebbero
    // undici query. È il difetto N+1 che `dettagliDellaPagina()` evita in questa
    // stessa vista, rifatto una colonna più in là.
    for ($i = 0; $i < 10; $i++) {
        $account = Account::factory()->create();
        UnitaOrganizzativa::factory()->ente()->perAccount($account)->create();
    }

    expect($conta())->toBe(1);
});
