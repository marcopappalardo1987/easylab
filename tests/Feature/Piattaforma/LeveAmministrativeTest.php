<?php

use App\Enums\VisibilitaGaranzieRicambio;
use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Le tre leve che agiscono su un cliente esistente (S6 — Wireframe §4).
 *
 * Lockout (ADR-013), visibilità garanzie ricambio (ADR-029) e dati fiscali
 * (ADR-032). Sono le prime scritture della cabina, e la prima volta che una
 * pagina del progetto **scrive fuori dal proprio tenant**: il rischio non è il
 * salvataggio che fallisce, è quello che riesce sulla riga sbagliata.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->assignRole('Superadmin');
    $this->actingAs($this->superadmin->fresh());

    $this->cliente = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->cliente)
        ->create(['nome' => 'Sede di Milano']);
});

// --- Lockout: due interruttori, mai uno ---

it('does not reopen the door when Stripe still holds it shut', function () {
    // 🔴 Il caso che la separazione dei due campi esiste per impedire. Un
    // contenzioso si chiude a mano; se nel frattempo è saltato anche un
    // pagamento, riaprire il contenzioso **non** deve far rientrare chi non ha
    // pagato. `is_locked` significa «almeno una sorgente accesa».
    $this->cliente->blocca('Contenzioso aperto');
    $this->cliente->bloccaPerStripe('Pagamento fallito');

    Livewire::test(Cabina::class)
        ->call('apriLockout', $this->cliente->id)
        ->call('sbloccaAccount');

    $fresco = $this->cliente->fresh();

    expect($fresco->locked_at)->toBeNull()
        ->and($fresco->stripe_locked_at)->not->toBeNull()
        ->and($fresco->is_locked)->toBeTrue();
});

it('never exposes the Stripe unlock outside the webhook that owns it', function () {
    // Il suo inverso è un evento di pagamento: un umano che dichiarasse «ha
    // pagato» verrebbe smentito dal webhook successivo, e nel frattempo il
    // cliente sarebbe rientrato senza pagare. La guardia non è un test di
    // comportamento — è che il gesto **non esista** in nessuna superficie.
    //
    // ⚠️ Tre correzioni rispetto alla prima stesura, tutte trovate dal confronto:
    //  1. si scansiona **`app/` intero** con un'allowlist nominata, sul modello
    //     di `BypassNudiGuardrailTest`. Prima guardava solo `app/Livewire` —
    //     mentre il posto naturale di una futura leva è un controller, cioè
    //     proprio ciò che non guardava;
    //  2. si salta `T_WHITESPACE` all'indietro, o `$account-> sbloccaPerStripe()`
    //     sfuggirebbe (stessa lezione già pagata dal guardrail dei bypass);
    //  3. si prende anche la **chiamata dinamica** — `->{'sbloccaPerStripe'}()` o
    //     una variabile che contenga quel nome — perché un `T_STRING` non è
    //     l'unico modo di scriverla.
    $ATTESI = ['StripeWebhookController.php'];

    $incriminati = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->filter(fn ($f) => ! in_array($f->getFilename(), $ATTESI, true))
        ->filter(function ($f) {
            $token = token_get_all(file_get_contents($f->getPathname()));

            foreach ($token as $i => $t) {
                if (! is_array($t)) {
                    continue;
                }

                // La chiamata dinamica: il nome sta dentro una stringa.
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($t[1], 'sbloccaPerStripe')) {
                    return true;
                }

                if ($t[0] !== T_STRING || $t[1] !== 'sbloccaPerStripe') {
                    continue;
                }

                for ($j = $i - 1; $j >= 0; $j--) {
                    if (is_array($token[$j]) && $token[$j][0] === T_WHITESPACE) {
                        continue;
                    }

                    if (is_array($token[$j]) && in_array($token[$j][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                        return true;
                    }

                    break;
                }
            }

            return false;
        })
        ->map(fn ($f) => $f->getFilename());

    // Nelle viste non ci sono docblock che ne parlino: lì basta il testo.
    $viste = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_contains(file_get_contents($f->getPathname()), 'sbloccaPerStripe'))
        ->map(fn ($f) => $f->getFilename());

    expect($incriminati->merge($viste)->values()->all())->toBe([]);

    // E l'allowlist non è un elenco di comodo: il file che ci sta dentro deve
    // davvero contenere la chiamata, o la voce è un permesso rimasto aperto su
    // qualcosa che non esiste più.
    expect(file_get_contents(app_path('Http/Controllers/StripeWebhookController.php')))
        ->toContain('sbloccaPerStripe');
});

it('refuses a lockout without a reason, and writes nothing', function () {
    // Il motivo non è burocrazia: `/bloccato` è muta di proposito, quindi
    // questa schermata è il **solo** posto dove ritrovarlo fra sei mesi. Un
    // lockout senza motivo diventa un cliente dimenticato.
    Livewire::test(Cabina::class)
        ->call('apriLockout', $this->cliente->id)
        ->set('motivoLockout', '')
        ->call('bloccaAccount')
        ->assertHasErrors('motivoLockout');

    expect($this->cliente->fresh()->locked_at)->toBeNull();
});

it('shows the reason here, where the blocked customer never sees it', function () {
    Livewire::test(Cabina::class)
        ->call('apriLockout', $this->cliente->id)
        ->set('motivoLockout', 'Fattura 2026/114 scaduta da 60 giorni')
        ->call('bloccaAccount');

    expect($this->cliente->fresh()->locked_reason)->toBe('Fattura 2026/114 scaduta da 60 giorni');

    Livewire::test(Cabina::class)
        ->call('apriLockout', $this->cliente->id)
        ->assertSee('Fattura 2026/114 scaduta da 60 giorni');

    // E il cliente, dalla propria parte, continua a non leggerlo.
    $suo = User::factory()->create(['tenant_id' => $this->sede->id]);
    $suo->assignRole('Tenant');
    $this->cliente->aggiungiMembro($suo);

    $this->actingAs($suo->fresh())->get('/bloccato')
        ->assertOk()
        ->assertDontSee('Fattura 2026/114');
});

it('refuses the lockout lever to whoever may see the page but not use it', function () {
    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');

    $this->actingAs($osservatore->fresh());

    Livewire::test(Cabina::class)->call('apriLockout', $this->cliente->id)->assertForbidden();
    Livewire::test(Cabina::class)->call('apriFiscali', $this->cliente->id)->assertForbidden();
});

it('refuses the fiscal lever on an account the user does not belong to', function () {
    // 🔴 Il caso che distingue la **Policy** dal permesso nudo, e l'unico che lo
    // fa: `billing.manage_own` risponde «questo utente amministra il proprio
    // abbonamento», non «**quale**». Con `teams = false` i permessi sono
    // globali, quindi la stringa nuda concederebbe su **ogni** account della
    // piattaforma. È esattamente l'errore già commesso una volta con
    // `garanzie.ricambio.manage` in `_panoramica.blade.php`.
    $intruso = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $intruso->givePermissionTo(['tenants.view_all', 'billing.manage_own']);

    $this->actingAs($intruso->fresh());

    Livewire::test(Cabina::class)->call('apriFiscali', $this->cliente->id)->assertForbidden();

    // E membro dello stesso account, passa: la Policy restringe, non vieta.
    $this->cliente->aggiungiMembro($intruso);

    Livewire::test(Cabina::class)->call('apriFiscali', $this->cliente->id)->assertOk();
});

it('refuses every lever on an account that is not a customer', function () {
    $piattaforma = Account::factory()->create(['di_piattaforma' => true]);

    foreach (['apriLockout', 'apriFiscali'] as $azione) {
        expect(fn () => Livewire::test(Cabina::class)->call($azione, $piattaforma->id))
            ->toThrow(ModelNotFoundException::class);
    }
});

it('refuses the three writing actions to an unauthorised user, property or no property', function () {
    // 🔴 I test di questo file verificavano l'autorizzazione **solo sulle
    // `apri*`**, e le tre azioni che *scrivono* sono chiamabili senza passare di
    // lì: `accountInLavorazione` e `pannello` sono property pubbliche, quindi la
    // sequenza `set` + `call` è a un `$wire` di distanza. Togliendo
    // `Gate::authorize` dalle azioni, la suite intera restava verde — e con quella
    // mutazione in piedi un utente col solo `tenants.view_all` blocca un cliente
    // qualunque, o gli riscrive la ragione sociale.
    $this->cliente->blocca('Contenzioso');

    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');
    $this->actingAs($osservatore->fresh());

    $apparecchia = fn (string $pannello) => Livewire::test(Cabina::class)
        ->set('accountInLavorazione', $this->cliente->id)
        ->set('pannello', $pannello);

    $apparecchia('lockout')->set('motivoLockout', 'preso di mira')->call('bloccaAccount')->assertForbidden();
    $apparecchia('lockout')->call('sbloccaAccount')->assertForbidden();
    $apparecchia('fiscali')->set('fiscali.ragione_sociale', 'Riscritta')->call('salvaFiscali')->assertForbidden();

    $fresco = $this->cliente->fresh();

    expect($fresco->locked_reason)->toBe('Contenzioso')
        ->and($fresco->ragione_sociale)->toBe('Gruppo Rossi');
});

it('does not even let an unauthorised user read the panel', function () {
    // 🔴 `accountAperto()` gata anche in **lettura**, e non era coperto: senza
    // quel controllo, chi setta le due property si vede renderizzare il modale
    // completo con `locked_reason` — il dato che ADR-013 tiene fuori da
    // `/bloccato` apposta — e il pulsante di sblocco.
    $this->cliente->blocca('Fattura 2026/999 scaduta');

    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');
    $this->actingAs($osservatore->fresh());

    Livewire::test(Cabina::class)
        ->set('accountInLavorazione', $this->cliente->id)
        ->set('pannello', 'lockout')
        ->assertDontSee('Fattura 2026/999 scaduta')
        ->assertDontSee('Riapri la porta');

    Livewire::test(Cabina::class)
        ->set('accountInLavorazione', $this->cliente->id)
        ->set('pannello', 'fiscali')
        ->assertDontSee('Codice destinatario SDI');
});

it('keeps lockout and manage apart: the two abilities are not interchangeable', function () {
    // 🔴 `AccountPolicy::lockout` è scritta **senza** condizione di
    // appartenenza, perché è un'abilità di piattaforma che si esercita su
    // account altrui; `manage` invece richiede di esserne membro. Scambiarle
    // metterebbe la leva antipagamento in mano al cliente: un Admin membro
    // potrebbe **sbloccare il proprio account**.
    $membro = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $membro->givePermissionTo(['tenants.view_all', 'billing.manage_own']);
    $this->cliente->aggiungiMembro($membro);

    $this->actingAs($membro->fresh());

    // Sui dati fiscali passa: è membro.
    Livewire::test(Cabina::class)->call('apriFiscali', $this->cliente->id)->assertOk();

    // Sul lockout no: quello chiede `billing.lockout`, che è nel set 🔒.
    Livewire::test(Cabina::class)->call('apriLockout', $this->cliente->id)->assertForbidden();

    $this->cliente->blocca('Insoluto');

    Livewire::test(Cabina::class)
        ->set('accountInLavorazione', $this->cliente->id)
        ->set('pannello', 'lockout')
        ->call('sbloccaAccount')
        ->assertForbidden();

    expect($this->cliente->fresh()->is_locked)->toBeTrue();
});

it('does not carry the fiscal data of one customer over to another', function () {
    // `chiudiPannello()` azzerava il motivo del lockout ma non i dati fiscali:
    // chiuso il pannello di A e riaperto quello di B via property, i valori di A
    // restavano in memoria e finivano su B al primo salvataggio.
    $altro = Account::factory()->create(['ragione_sociale' => 'Bianchi SRL']);
    UnitaOrganizzativa::factory()->ente()->perAccount($altro)->create();

    Livewire::test(Cabina::class)
        ->call('apriFiscali', $this->cliente->id)
        ->set('fiscali.partita_iva', '01234567890')
        ->call('chiudiPannello')
        ->set('accountInLavorazione', $altro->id)
        ->set('pannello', 'fiscali')
        ->set('fiscali.ragione_sociale', 'Bianchi SRL')
        ->call('salvaFiscali');

    expect($altro->fresh()->partita_iva)->toBeNull();
});

it('turns a half-built fiscal payload into a validation error, not a 500', function () {
    // `validate()` restituisce **solo le chiavi presenti**, e con regole
    // `nullable` una chiave assente passa senza comparire nel risultato:
    // `$fiscali` è settabile in blocco, e un payload con un campo solo dava
    // `Undefined array key`.
    Livewire::test(Cabina::class)
        ->call('apriFiscali', $this->cliente->id)
        ->set('fiscali', ['ragione_sociale' => 'Solo questa'])
        ->call('salvaFiscali')
        ->assertHasNoErrors();

    expect($this->cliente->fresh()->ragione_sociale)->toBe('Solo questa');
});

it('does not let a padded reason slip under the minimum length', function () {
    Livewire::test(Cabina::class)
        ->call('apriLockout', $this->cliente->id)
        ->set('motivoLockout', '  ab  ')
        ->call('bloccaAccount')
        ->assertHasErrors('motivoLockout');

    expect($this->cliente->fresh()->locked_at)->toBeNull();
});

// --- Visibilità garanzie ricambio: l'unica scrittura cross-tenant ---

it('writes the warranty visibility on an Ente of another tenant', function () {
    // È la consegna di ADR-029 (S4): il Superadmin è tenant-bound e ne vede un
    // Ente solo, quindi per gli altri si passava dalla console. Questa è la
    // vista che interroga la piattaforma senza scoping, ed è qui che il
    // controllo diventa usabile davvero.
    Livewire::test(Cabina::class)
        ->call('fissaVisibilita', $this->sede->id, VisibilitaGaranzieRicambio::Nascosta->value);

    expect($this->sede->fresh()->visibilita_garanzie_ricambio)
        ->toBe(VisibilitaGaranzieRicambio::Nascosta);
});

it('shows the current visibility as the selected option, not the first one', function () {
    // 🔴 Il difetto peggiore del blocco, e invisibile a ogni test che asserisca
    // sul DB. Se la colonna non è nel `select` della query, il model esce da
    // `newFromBuilder()` con `setRawAttributes()`, che spazza via il default
    // dichiarato in `$attributes`; il cast restituisce `null` senza errori,
    // nessuna `<option>` porta `selected` e il browser mostra **la prima** —
    // «Nascoste». Il default vero è `Modifica`.
    //
    // Cioè: la cabina dice «questo cliente non vede le garanzie ricambio» di un
    // cliente che le vede e le modifica. E nella direzione peggiore — chi vuole
    // *imporre* «Nascoste» la trova già selezionata, non tocca nulla, e la
    // clausola non viene mai scritta. Su una schermata che esiste per rendere
    // quel controllo usabile fuori dalla console, la leva sarebbe consegnata
    // rotta.
    $this->sede->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Lettura);

    Livewire::test(Cabina::class)
        ->call('espandi', $this->cliente->id)
        ->assertSeeHtml('value="lettura" selected')
        ->assertDontSeeHtml('value="nascosta" selected');
});

it('writes exactly one audit row, not two', function () {
    // L'audit lo scrive il gesto di dominio, che è l'unica via per quella
    // colonna: un `activity()` anche qui racconterebbe la stessa cosa due volte,
    // e renderebbe rosso il guardrail trait-vs-esplicita.
    Livewire::test(Cabina::class)
        ->call('fissaVisibilita', $this->sede->id, VisibilitaGaranzieRicambio::Lettura->value);

    expect(Activity::where('log_name', AuditLog::NAME)
        ->where('subject_type', UnitaOrganizzativa::class)->count())->toBe(1);
});

it('fails closed on an id that is not an Ente', function () {
    // `account_id` vive solo sui nodi ente, ma è una guardia sul modello e non
    // un CHECK: l'id arriva dal browser e va verificato qui.
    // ⚠️ Scritto **scavalcando la guardia sul modello**, perché è così che quella
    // riga arriverebbe davvero: `UnitaOrganizzativa` rifiuta un `account_id` su
    // un non-Ente su `creating`/`updating`, ma non c'è nessun CHECK a DB — una
    // migration di correzione o un import la scriverebbero senza ostacoli. Senza
    // questo passaggio il test sarebbe verde anche togliendo il filtro `tipo`,
    // perché `account_id` è nullo sui dipartimenti e il `whereIn` li escluderebbe
    // comunque: la guardia risulterebbe coperta senza esserlo.
    $dipartimento = UnitaOrganizzativa::factory()->dipartimento()->under($this->sede)->create();
    DB::table('unita_organizzativa')->where('id', $dipartimento->id)
        ->update(['account_id' => $this->cliente->id]);

    expect(fn () => Livewire::test(Cabina::class)
        ->call('fissaVisibilita', $dipartimento->id, VisibilitaGaranzieRicambio::Nascosta->value))
        ->toThrow(ModelNotFoundException::class);
});

it('fails closed on an Ente that belongs to no customer', function () {
    $piattaforma = Account::factory()->create(['di_piattaforma' => true]);
    $suaSede = UnitaOrganizzativa::factory()->ente()->perAccount($piattaforma)->create();

    expect(fn () => Livewire::test(Cabina::class)
        ->call('fissaVisibilita', $suaSede->id, VisibilitaGaranzieRicambio::Nascosta->value))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses the warranty lever to whoever lacks roles.manage', function () {
    // ⚠️ Asserito **sull'azione del componente** e non su `Gate::denies`: quel
    // permesso l'Admin non ce l'ha per config, quindi un test sul Gate sarebbe
    // un test del seeder travestito. Il gate qui è `roles.manage` e non
    // `unita_organizzativa.update`, che l'Admin dell'Ente ha: questa è una
    // clausola di contratto, non un'impostazione di anagrafica.
    $osservatore = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $osservatore->givePermissionTo('tenants.view_all');

    $this->actingAs($osservatore->fresh());

    Livewire::test(Cabina::class)
        ->call('fissaVisibilita', $this->sede->id, VisibilitaGaranzieRicambio::Nascosta->value)
        ->assertForbidden();

    expect($this->sede->fresh()->visibilita_garanzie_ricambio)
        ->not->toBe(VisibilitaGaranzieRicambio::Nascosta);
});

it('refuses a visibility value that is not one of the three states', function () {
    Livewire::test(Cabina::class)
        ->call('fissaVisibilita', $this->sede->id, 'quasi_visibile')
        ->assertHasErrors('visibilita');
});

// --- Dati fiscali ---

it('saves the fiscal data through the model, letting the trait trace it', function () {
    Livewire::test(Cabina::class)
        ->call('apriFiscali', $this->cliente->id)
        ->set('fiscali.partita_iva', '01234567890')
        ->set('fiscali.pec', 'amministrazione@rossi.pec.it')
        ->set('fiscali.codice_destinatario_sdi', 'ABC1234')
        ->call('salvaFiscali')
        ->assertHasNoErrors();

    $fresco = $this->cliente->fresh();

    expect($fresco->partita_iva)->toBe('01234567890')
        ->and($fresco->pec)->toBe('amministrazione@rossi.pec.it')
        ->and($fresco->codice_destinatario_sdi)->toBe('ABC1234');
});

it('accepts the two SDI lengths and refuses everything between and around', function () {
    // Sei = Pubblica Amministrazione (codice univoco IPA), sette = privati. Sono
    // **due codici diversi con lo stesso nome**: un `between:6,7` accetterebbe
    // entrambi e nessuno dei due.
    $prova = function (string $codice) {
        return Livewire::test(Cabina::class)
            ->call('apriFiscali', $this->cliente->id)
            ->set('fiscali.codice_destinatario_sdi', $codice)
            ->call('salvaFiscali');
    };

    $prova('UFY9MH')->assertHasNoErrors();      // 6, PA
    $prova('ABC1234')->assertHasNoErrors();     // 7, privati
    $prova('ABC12')->assertHasErrors('fiscali.codice_destinatario_sdi');
    $prova('ABC12345')->assertHasErrors('fiscali.codice_destinatario_sdi');
    $prova('ABC-123')->assertHasErrors('fiscali.codice_destinatario_sdi');
});

it('empties a fiscal field to null, not to an empty string', function () {
    // Una stringa vuota in colonna non è «non compilato»: è un valore, e
    // finirebbe su una fattura come tale.
    $this->cliente->update(['partita_iva' => '01234567890']);

    Livewire::test(Cabina::class)
        ->call('apriFiscali', $this->cliente->id)
        ->set('fiscali.partita_iva', '')
        ->call('salvaFiscali');

    expect($this->cliente->fresh()->partita_iva)->toBeNull();
});
