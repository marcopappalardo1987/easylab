<?php

use App\Models\Account;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Documento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Gdpr\EsportazioneNegata;
use App\Support\Gdpr\EsportazioneTenant;
use App\Support\Gdpr\PerimetroTenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Isolamento\Support\MondoDueEnti;

/**
 * L'export GDPR di un Account (ADR-013, ADR-018, ADR-032): isolamento fra due
 * tenant popolati, autorizzazione coi suoi negativi, niente segreti delle
 * persone, audit, ordine dei blocchi verificato sull'SQL.
 *
 * ⚠️ Il mondo nasce PRIMA di qualunque actingAs (vedi MondoDueEnti), e il
 * comando si lancia senza utente: è il contesto in cui gira davvero.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();

    $piattaforma = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente di piattaforma']);
    $this->superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->superadmin->forceFill(['tenant_id' => $piattaforma->id])->save();
    $this->superadmin->assignRole('Superadmin');

    $this->cartella = sys_get_temp_dir().'/easylab-gdpr-test-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->cartella);

    // Un disco tutto nostro, riempito con gli stessi byte del mondo: la cartella
    // di `Storage::fake()` è una sola per checkout, e un'altra suite che gira in
    // parallelo la svuota a metà test (visto: un documento «non leggibile» a caso).
    Storage::set(Documento::DISCO, Storage::build(['driver' => 'local', 'root' => $this->cartella.'/disco', 'throw' => true]));
    foreach (['A', 'B'] as $x) {
        Storage::disk(Documento::DISCO)->put($this->mondo->riga($x, 'documento')->path, "%PDF-1.4 CONTENUTO-SEGRETO-{$x}");
        Storage::disk(Documento::DISCO)->put($this->mondo->riga($x, 'documentoAltroReparto')->path, "%PDF-1.4 CONTENUTO-FUORI-REPARTO-{$x}");
    }
    // La factory scrive una `size` di fantasia, e l'export ora la confronta coi
    // byte copiati: qui si allinea a ciò che c'è davvero sul disco.
    foreach (DB::table('documenti')->get(['id', 'path']) as $d) {
        DB::table('documenti')->where('id', $d->id)->update(['size' => Storage::disk(Documento::DISCO)->size($d->path)]);
    }
});

afterEach(function () {
    File::deleteDirectory($this->cartella);
});

function esporta(Account $account, ?string $operatore, string $percorso)
{
    return test()->artisan('easylab:esporta-tenant', array_filter([
        'account' => $account->id,
        '--operatore' => $operatore,
        '--percorso' => $percorso,
    ], fn ($v) => $v !== null));
}

/** @return array<string, string> nome voce => contenuto */
function vociZip(string $percorso): array
{
    $zip = new ZipArchive;
    expect($zip->open($percorso))->toBeTrue();

    $voci = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $voci[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();

    return $voci;
}

/** @return list<array<string, string>> */
function righeCsvGdpr(string $contenuto): array
{
    $contenuto = preg_replace('/^\xEF\xBB\xBF/', '', $contenuto);
    $righe = array_map(fn ($r) => str_getcsv($r, ';', '"', ''), preg_split('/\r?\n/', trim($contenuto)));
    $intestazioni = array_shift($righe);

    return array_map(fn ($r) => array_combine($intestazioni, $r), $righe);
}

function auditEsportazioni(): int
{
    return Activity::inLog(AuditLog::NAME)->where('description', EsportazioneTenant::DESCRIZIONE_AUDIT)->count();
}

it('exports only the rows and files of the requested account, never those of the other tenant', function () {
    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();

    $voci = vociZip($percorso);
    $tutto = implode("\n", $voci);

    // Positivo prima: un export vuoto passerebbe ogni negativo.
    foreach (['Strumento-SEGRETO-A', 'Intervento-SEGRETO-A', 'Fornitore-SEGRETO-A', 'Ricambio-SEGRETO-A',
        'Spostamento-SEGRETO-A', 'Utente-SEGRETO-A', 'Reparto-SEGRETO-A', 'CONTENUTO-SEGRETO-A'] as $ago) {
        expect($tutto)->toContain($ago);
    }

    foreach ([...MondoDueEnti::marcatori('B'), 'CONTENUTO-SEGRETO-B', 'CONTENUTO-FUORI-REPARTO-B'] as $ago) {
        expect($tutto)->not->toContain($ago);
    }

    foreach (['admin', 'responsabile', 'tecnico'] as $chi) {
        expect($tutto)->not->toContain($this->mondo->riga('B', $chi)->email);
    }

    // Riga per riga: ogni tabella del tenant porta solo tenant_id di A.
    $entiA = PerimetroTenant::di($this->mondo->riga('A', 'account'))->enti;
    foreach (PerimetroTenant::tabelleDelTenant() as $tabella) {
        $righe = righeCsvGdpr($voci["dati/{$tabella}.csv"]);
        expect($righe)->not->toBeEmpty();
        foreach ($righe as $riga) {
            expect((int) $riga['tenant_id'])->toBeIn($entiA);
        }
    }
});

it('exports both documents of the tenant, including the one outside any department filter', function () {
    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();

    $voci = vociZip($percorso);
    $documento = $this->mondo->riga('A', 'documento');

    expect($voci)->toHaveKey("documenti/{$documento->id}/Documento-SEGRETO-A.pdf")
        ->and($voci["documenti/{$documento->id}/Documento-SEGRETO-A.pdf"])->toBe('%PDF-1.4 CONTENUTO-SEGRETO-A')
        ->and(collect(array_keys($voci))->filter(fn ($n) => str_starts_with($n, 'documenti/'))->count())->toBe(2);
});

it('works on a locked account, which is the typical case', function () {
    $account = $this->mondo->riga('A', 'account');
    $account->blocca('Insoluto fattura 42');
    $account->bloccaPerStripe('subscription unpaid');

    $percorso = $this->cartella.'/a.zip';
    esporta($account->fresh(), $this->superadmin->email, $percorso)->assertSuccessful();

    $manifest = json_decode(vociZip($percorso)['manifest.json'], true);
    expect($manifest['account']['bloccato'])->toBeTrue();
});

it('writes the plan by the name the customer reads, never by its internal code', function () {
    // 🔗 ADR-050: il codice del piano senza canone è `free`, e l'archivio lo
    // apre il cliente. Esce il nome che legge in pagina.
    $account = $this->mondo->riga('A', 'account');
    DB::table('accounts')->where('id', $account->id)->update(['piano' => 'free', 'piano_proposto' => 'saas']);

    $percorso = $this->cartella.'/nomi.zip';
    esporta($account->fresh(), $this->superadmin->email, $percorso)->assertSuccessful();

    $riga = righeCsvGdpr(vociZip($percorso)['dati/account.csv'])[0];

    expect($riga['piano'])->toBe("Comodato d'uso")
        ->and($riga['piano_proposto'])->toBe('SaaS');

    // Un piano uscito dal catalogo non ha un nome da dare, e una proposta che
    // non c'è non è un piano: restano come sono, e l'export non si ferma.
    DB::table('accounts')->where('id', $account->id)->update(['piano' => 'fantasma', 'piano_proposto' => null]);

    $percorso = $this->cartella.'/fuori-catalogo.zip';
    esporta($account->fresh(), $this->superadmin->email, $percorso)->assertSuccessful();

    $riga = righeCsvGdpr(vociZip($percorso)['dati/account.csv'])[0];

    expect($riga['piano'])->toBe('fantasma')
        ->and($riga['piano_proposto'])->toBe('');
});

it('writes a manifest whose counts and hashes match the archive', function () {
    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();

    $voci = vociZip($percorso);
    $manifest = json_decode($voci['manifest.json'], true);

    expect($manifest['versione'])->toBe(EsportazioneTenant::VERSIONE_FORMATO)
        ->and($manifest['account']['id'])->toBe($this->mondo->riga('A', 'account')->id)
        ->and(array_column($manifest['enti'], 'nome'))->toBe(['Ente-SEGRETO-A']);

    foreach ($manifest['righe'] as $file => $n) {
        $nome = $file === 'account' ? 'dati/account.csv' : "dati/{$file}.csv";
        expect(count(righeCsvGdpr($voci[$nome])))->toBe($n);
    }

    $attese = collect($voci)->except('manifest.json')->map(fn ($c) => hash('sha256', $c))->all();
    ksort($attese);
    $dichiarate = $manifest['impronte_sha256'];
    ksort($dichiarate);
    expect($dichiarate)->toBe($attese);
});

it('never exports password hashes, remember tokens, 2FA secrets or recovery codes', function () {
    $admin = $this->mondo->riga('A', 'admin');
    $admin->forceFill([
        'remember_token' => 'TOKEN-RICORDA-A',
        'two_factor_secret' => 'SEGRETO-DUE-FATTORI-A',
        'two_factor_recovery_codes' => 'CODICI-RECUPERO-A',
    ])->save();
    $hash = $admin->fresh()->password;

    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();

    $voci = vociZip($percorso);
    $tutto = implode("\n", $voci);

    expect($tutto)->not->toContain('TOKEN-RICORDA-A')
        ->and($tutto)->not->toContain('SEGRETO-DUE-FATTORI-A')
        ->and($tutto)->not->toContain('CODICI-RECUPERO-A')
        ->and($tutto)->not->toContain($hash);

    $riga = collect(righeCsvGdpr($voci['dati/users.csv']))->firstWhere('email', $admin->email);
    expect($riga)->not->toBeNull()
        ->and($riga['doppio_fattore_attivo'])->toBe('1')
        ->and($riga['ruoli'])->toBe('Admin')
        ->and($riga['membro_account'])->toBe('1')
        ->and(array_keys($riga))->not->toContain('password');
});

it('classifies every column of users and accounts, so a new one is a question and not a leak', function () {
    $utenti = array_merge(PerimetroTenant::COLONNE_UTENTI, PerimetroTenant::COLONNE_UTENTI_ESCLUSE);
    $account = array_merge(PerimetroTenant::COLONNE_ACCOUNT, PerimetroTenant::COLONNE_ACCOUNT_ESCLUSE);

    expect(array_values(array_diff(Schema::getColumnListing('users'), $utenti)))->toBe([])
        ->and(array_values(array_diff(Schema::getColumnListing('accounts'), $account)))->toBe([])
        ->and(array_intersect(PerimetroTenant::COLONNE_UTENTI, PerimetroTenant::COLONNE_UTENTI_ESCLUSE))->toBe([]);
});

it('covers the table of every model with BelongsToTenant', function () {
    $tabelle = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $f) => 'App\\Models\\'.basename($f, '.php'))
        ->filter(fn (string $c) => in_array(BelongsToTenant::class, class_uses_recursive($c), true))
        ->map(fn (string $c) => (new $c)->getTable())
        ->values();

    expect($tabelle)->not->toBeEmpty()
        ->and($tabelle->diff(PerimetroTenant::tabelleDelTenant())->values()->all())->toBe([]);
});

it('exports or declares every table of the schema that carries tenant_id, account_id or user_id', function () {
    // Dallo SCHEMA, non da un elenco: una tabella nuova con uno di quei legami
    // è una domanda («è del cliente?»), e il test la fa porre.
    $esportate = [
        ...array_keys(PerimetroTenant::di($this->mondo->riga('A', 'account'))->query()),
        'users', 'accounts',
    ];

    $legate = collect(Schema::getTables())->pluck('name')->unique()
        ->filter(fn (string $t) => array_intersect(['tenant_id', 'account_id', 'user_id'], Schema::getColumnListing($t)) !== [])
        ->values();

    expect($legate)->toContain('registrazioni')
        ->and($legate)->toContain('occorrenze_errore');

    $scoperte = $legate->reject(fn (string $t) => in_array($t, $esportate, true)
        || array_key_exists($t, PerimetroTenant::TABELLE_ESCLUSE))->values()->all();

    expect($scoperte)->toBe([])
        ->and(array_intersect($esportate, array_keys(PerimetroTenant::TABELLE_ESCLUSE)))->toBe([]);
});

it('classifies every column of the self-signup and error tables it exports', function () {
    foreach ([
        'registrazioni' => [PerimetroTenant::COLONNE_REGISTRAZIONI, PerimetroTenant::COLONNE_REGISTRAZIONI_ESCLUSE],
        'occorrenze_errore' => [PerimetroTenant::COLONNE_OCCORRENZE, PerimetroTenant::COLONNE_OCCORRENZE_ESCLUSE],
    ] as $tabella => [$dentro, $fuori]) {
        expect(array_values(array_diff(Schema::getColumnListing($tabella), [...$dentro, ...$fuori])))->toBe([])
            ->and(array_intersect($dentro, $fuori))->toBe([]);
    }
});

it('exports the self-signup row of the account, without its password hash, and not the one of the other', function () {
    foreach (['A', 'B'] as $x) {
        DB::table('registrazioni')->insert([
            'nome_ente' => "Ente-REG-{$x}", 'nome_referente' => "Referente-REG-SEGRETO-{$x}",
            'email' => "referente-{$x}@example.test", 'piano' => 'saas', 'password_hash' => "HASH-REG-{$x}",
            'stripe_session_id' => "cs_SEGRETO_{$x}", 'account_id' => $this->mondo->riga($x, 'account')->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();
    $tutto = implode("\n", vociZip($percorso));

    expect($tutto)->toContain('Referente-REG-SEGRETO-A')
        ->and($tutto)->not->toContain('HASH-REG-A')
        ->and($tutto)->not->toContain('cs_SEGRETO_A')
        ->and($tutto)->not->toContain('Referente-REG-SEGRETO-B');
});

it('exports the error occurrences of the account people, reduced, and not those of the other tenant', function () {
    $errore = DB::table('errori')->insertGetId([
        'impronta' => sha1('t5'), 'classe' => 'RuntimeException', 'messaggio' => 'x', 'file' => 'x.php', 'riga' => 1,
        'prima_occorrenza_at' => now(), 'ultima_occorrenza_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['A', 'B'] as $x) {
        DB::table('occorrenze_errore')->insert([
            'errore_id' => $errore, 'messaggio' => "MSG-SEGRETO-{$x}", 'stack_trace' => "STACK-SEGRETO-{$x}",
            'percorso' => "/reset-password/TOKEN-SEGRETO-{$x}", 'metodo' => 'POST',
            'user_id' => $this->mondo->riga($x, 'admin')->id, 'ip' => "203.0.113.1{$x}0",
            'user_agent' => "UA-PERSONALE-{$x}", 'input' => json_encode(['password' => "INPUT-SEGRETO-{$x}"]),
            'contesto' => 'web', 'avvenuta_at' => now(),
        ]);
    }

    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();
    $voci = vociZip($percorso);
    $tutto = implode("\n", $voci);

    expect($voci['dati/occorrenze_errore.csv'])->toContain('UA-PERSONALE-A');
    foreach (['MSG-SEGRETO-A', 'STACK-SEGRETO-A', 'TOKEN-SEGRETO-A', 'INPUT-SEGRETO-A', 'UA-PERSONALE-B', '203.0.113.1B0'] as $ago) {
        expect($tutto)->not->toContain($ago);
    }
});

it('includes trashed rows and trashed Enti, because they are data still held', function () {
    $strumento = $this->mondo->riga('A', 'strumento');
    $strumento->delete();

    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();

    $riga = collect(righeCsvGdpr(vociZip($percorso)['dati/strumenti.csv']))->firstWhere('id', (string) $strumento->id);
    expect($riga)->not->toBeNull()->and($riga['deleted_at'])->not->toBe('');
});

it('lists an unreadable document in the manifest instead of failing the export', function () {
    Storage::disk(Documento::DISCO)->delete($this->mondo->riga('A', 'documento')->path);

    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();

    $manifest = json_decode(vociZip($percorso)['manifest.json'], true);
    expect($manifest['documenti']['non_leggibili'])->toBe([$this->mondo->riga('A', 'documento')->id])
        ->and($manifest['documenti']['file'])->toBe(1);
});

it('reads every table in blocks ordered by the unique id', function () {
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $this->cartella.'/a.zip')->assertSuccessful();

    $tabelle = [...PerimetroTenant::tabelleDelTenant(), 'responsabile_unita', 'tecnico_cliente', 'account_user', 'users'];
    foreach ($tabelle as $tabella) {
        // Fuori le letture che non sono a blocchi per natura: gli id degli Enti
        // dell'Account, i loro nomi nel manifest (una riga per sede) e la
        // ricerca dell'operatore per email.
        $letture = collect($sql)->filter(fn (string $q) => preg_match('/^select .*? from "'.$tabella.'" where/', $q) === 1
            && ! str_starts_with($q, 'select "id" from "unita_organizzativa" where "account_id"')
            && ! str_starts_with($q, 'select "id", "nome", "deleted_at" from "unita_organizzativa"')
            && ! str_starts_with($q, 'select * from "users" where "email" = ?'));

        expect($letture)->not->toBeEmpty();
        foreach ($letture as $q) {
            expect($q)->toMatch('/order by "id" asc limit \d+$/');
        }
    }
});

it('writes one audit row per export, attributed to the operator and about the account', function () {
    $account = $this->mondo->riga('A', 'account');
    $percorso = $this->cartella.'/a.zip';
    esporta($account, $this->superadmin->email, $percorso)->assertSuccessful();

    $riga = Activity::inLog(AuditLog::NAME)->where('description', EsportazioneTenant::DESCRIZIONE_AUDIT)->sole();
    expect($riga->causer_id)->toBe($this->superadmin->id)
        ->and($riga->subject_type)->toBe($account->getMorphClass())
        ->and($riga->subject_id)->toBe($account->id)
        ->and($riga->properties['sha256'])->toBe(hash_file('sha256', $percorso));

    esporta($account, $this->superadmin->email, $this->cartella.'/a2.zip')->assertSuccessful();
    expect(auditEsportazioni())->toBe(2);
});

it('lets the Developer export too, since it holds the platform permission', function () {
    $developer = User::factory()->create();
    $developer->assignRole('Developer');

    esporta($this->mondo->riga('A', 'account'), $developer->email, $this->cartella.'/a.zip')->assertSuccessful();
});

it('refuses the Admin of a tenant who asks for another tenant', function () {
    $percorso = $this->cartella.'/b.zip';
    esporta($this->mondo->riga('B', 'account'), $this->mondo->riga('A', 'admin')->email, $percorso)->assertFailed();

    expect(File::exists($percorso))->toBeFalse()->and(auditEsportazioni())->toBe(0);
});

it('refuses the Admin of a tenant even on their own account: self-service export is not decided', function () {
    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->mondo->riga('A', 'admin')->email, $percorso)->assertFailed();

    expect(File::exists($percorso))->toBeFalse()->and(auditEsportazioni())->toBe(0);
});

it('refuses a user without the permission', function (string $chi) {
    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->mondo->riga('A', $chi)->email, $percorso)->assertFailed();

    expect(File::exists($percorso))->toBeFalse()->and(auditEsportazioni())->toBe(0);
})->with(['responsabile', 'tecnico']);

it('refuses a plain Tenant user', function () {
    $tenant = User::factory()->create();
    $tenant->forceFill(['tenant_id' => $this->mondo->riga('A', 'ente')->id])->save();
    $tenant->assignRole('Tenant');

    esporta($this->mondo->riga('A', 'account'), $tenant->email, $this->cartella.'/a.zip')->assertFailed();
    expect(auditEsportazioni())->toBe(0);
});

it('refuses without an operator, with an unknown one, and with a trashed one', function () {
    $account = $this->mondo->riga('A', 'account');
    esporta($account, null, $this->cartella.'/a.zip')->assertFailed();
    esporta($account, 'nessuno@example.test', $this->cartella.'/a.zip')->assertFailed();

    $this->superadmin->delete();
    esporta($account, $this->superadmin->email, $this->cartella.'/a.zip')->assertFailed();

    expect(File::exists($this->cartella.'/a.zip'))->toBeFalse()->and(auditEsportazioni())->toBe(0);
});

it('refuses a Superadmin calling it from a session, without impersonation (ADR-018)', function () {
    $this->actingAs($this->superadmin);

    expect(fn () => app(EsportazioneTenant::class)->esegui(
        $this->mondo->riga('B', 'account'), $this->superadmin, $this->cartella.'/b.zip',
    ))->toThrow(EsportazioneNegata::class);

    expect(File::exists($this->cartella.'/b.zip'))->toBeFalse()->and(auditEsportazioni())->toBe(0);
});

it('refuses a Superadmin who is impersonating the Admin of the tenant', function () {
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->mondo->riga('B', 'admin')));
    expect(app('impersonate')->isImpersonating())->toBeTrue();

    expect(fn () => app(EsportazioneTenant::class)->esegui(
        $this->mondo->riga('B', 'account'), $this->superadmin, $this->cartella.'/b.zip',
    ))->toThrow(EsportazioneNegata::class);

    expect(File::exists($this->cartella.'/b.zip'))->toBeFalse()->and(auditEsportazioni())->toBe(0);
});

it('never overwrites an existing file', function () {
    $percorso = $this->cartella.'/a.zip';
    File::put($percorso, 'PREESISTENTE');

    expect(fn () => esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->run())
        ->toThrow(RuntimeException::class);

    expect(File::get($percorso))->toBe('PREESISTENTE')->and(auditEsportazioni())->toBe(0);
});

it('keeps zip entry names inside their folder', function (string $nome, string $atteso) {
    expect(EsportazioneTenant::nomeSicuro($nome))->toBe($atteso);
})->with([
    'risalita' => ['../../etc/passwd', '_.._etc_passwd'],
    'solo punti' => ['..', 'file'],
    'barra inversa' => ['a\\b.pdf', 'a_b.pdf'],
    'accenti' => ['Certificato taratura è.pdf', 'Certificato taratura è.pdf'],
]);

// ─── Dal giro dei cacciatori T5A/T5B ─────────────────────────────────────────

it('exports only the pivot rows of the requested account, checked by id', function () {
    // I pivot portano solo id: i marcatori di testo non li vedono (T5B-1).
    $tecnicoEasyLab = User::factory()->create(['name' => 'TecnicoEsterno-SOLO-B']);
    foreach (['A', 'B'] as $x) {
        DB::table('tecnico_cliente')->insert([
            'tecnico_id' => $tecnicoEasyLab->id, 'ente_id' => $this->mondo->riga($x, 'ente')->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();
    $voci = vociZip($percorso);

    $accountA = (string) $this->mondo->riga('A', 'account')->id;
    $enteA = (string) $this->mondo->riga('A', 'ente')->id;
    $nodiA = DB::table('unita_organizzativa')->where('tenant_id', $enteA)->pluck('id')->map(fn ($id) => (string) $id)->all();

    $membri = righeCsvGdpr($voci['dati/account_user.csv']);
    $tecnici = righeCsvGdpr($voci['dati/tecnico_cliente.csv']);
    $responsabili = righeCsvGdpr($voci['dati/responsabile_unita.csv']);

    expect($membri)->not->toBeEmpty()->and($tecnici)->not->toBeEmpty()->and($responsabili)->not->toBeEmpty()
        ->and(implode("\n", $voci))->not->toContain('TecnicoEsterno-SOLO-B');
    foreach ($membri as $r) {
        expect($r['account_id'])->toBe($accountA);
    }
    foreach ($tecnici as $r) {
        expect($r['ente_id'])->toBe($enteA);
    }
    foreach ($responsabili as $r) {
        expect($r['unita_organizzativa_id'])->toBeIn($nodiA);
    }
});

it('exports a person of both contracts once, and no member of the other account only', function () {
    $accountA = $this->mondo->riga('A', 'account');
    $accountB = $this->mondo->riga('B', 'account');

    $soloB = User::factory()->create(['name' => 'MembroCommerciale-SOLO-B']);
    $accountB->aggiungiMembro($soloB);
    $doppio = User::factory()->create(['name' => 'Doppio-Contratto']);
    $doppio->forceFill(['tenant_id' => $this->mondo->riga('B', 'ente')->id])->save();
    $accountA->aggiungiMembro($doppio);
    $accountB->aggiungiMembro($doppio);

    $percorso = $this->cartella.'/a.zip';
    esporta($accountA, $this->superadmin->email, $percorso)->assertSuccessful();
    $voci = vociZip($percorso);

    expect(implode("\n", $voci))->not->toContain('MembroCommerciale-SOLO-B')
        ->and(collect(righeCsvGdpr($voci['dati/users.csv']))->where('name', 'Doppio-Contratto')->count())->toBe(1);
});

it('still exports a trashed Ente with its rows, and the file of a trashed document', function () {
    DB::table('unita_organizzativa')->where('id', $this->mondo->riga('A', 'ente')->id)->update(['deleted_at' => now()]);
    DB::table('documenti')->where('id', $this->mondo->riga('A', 'documento')->id)->update(['deleted_at' => now()]);

    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();
    $voci = vociZip($percorso);
    $manifest = json_decode($voci['manifest.json'], true);

    expect($manifest['enti'])->toHaveCount(1)
        ->and($manifest['enti'][0]['cestinato'])->toBeTrue()
        ->and($voci['dati/strumenti.csv'])->toContain('Strumento-SEGRETO-A')
        ->and(implode("\n", $voci))->toContain('CONTENUTO-SEGRETO-A');
});

it('refuses a trashed operator passed directly to the service', function () {
    // Il comando non ci arriva (la ricerca per email esclude i cestinati): il
    // ramo si prova solo dal servizio (T5B-4).
    $this->superadmin->delete();

    expect(fn () => app(EsportazioneTenant::class)->esegui(
        $this->mondo->riga('A', 'account'), $this->superadmin, $this->cartella.'/a.zip',
    ))->toThrow(EsportazioneNegata::class);

    expect(File::exists($this->cartella.'/a.zip'))->toBeFalse();
});

it('deletes the archive when the audit row cannot be written', function () {
    Schema::drop('activity_log');

    $percorso = $this->cartella.'/a.zip';
    expect(fn () => app(EsportazioneTenant::class)->esegui($this->mondo->riga('A', 'account'), $this->superadmin, $percorso))
        ->toThrow(QueryException::class);

    expect(File::exists($percorso))->toBeFalse();
});

it('writes the archive and every working file readable only by their owner', function () {
    $modi = [];
    DB::listen(function ($q) use (&$modi) {
        if (str_contains($q->sql, 'from "documenti"') && $modi === []) {
            foreach (glob(EsportazioneTenant::cartellaLavoro().'/*') as $dir) {
                $modi[$dir] = fileperms($dir) & 0o777;
                foreach (glob($dir.'/*.csv') as $csv) {
                    $modi[$csv] = fileperms($csv) & 0o777;
                }
            }
        }
    });

    $percorso = $this->cartella.'/a.zip';
    $prima = umask();
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();

    clearstatcache();
    $modi[$percorso] = fileperms($percorso) & 0o777;

    expect(count($modi))->toBeGreaterThan(2)
        ->and(umask())->toBe($prima);
    foreach ($modi as $file => $modo) {
        expect($modo & 0o077)->toBe(0);
    }
    expect(glob(EsportazioneTenant::cartellaLavoro().'/*'))->toBe([]);
});

it('does not certify a document whose file is shorter than its recorded size', function () {
    $documento = $this->mondo->riga('A', 'documento');
    DB::table('documenti')->where('id', $documento->id)->update(['size' => 1_000_000]);

    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();
    $voci = vociZip($percorso);
    $manifest = json_decode($voci['manifest.json'], true);

    expect($manifest['documenti']['non_leggibili'])->toBe([$documento->id])
        ->and(collect(array_keys($voci))->filter(fn ($n) => str_starts_with($n, "documenti/{$documento->id}/"))->all())->toBe([]);
});

it('never deletes a file it did not create, even when it appears between the check and the open', function () {
    $percorso = $this->cartella.'/a.zip';
    File::put($percorso, 'PREESISTENTE');
    File::partialMock()->shouldReceive('exists')->with($percorso)->andReturn(false);

    expect(fn () => app(EsportazioneTenant::class)->esegui($this->mondo->riga('A', 'account'), $this->superadmin, $percorso))
        ->toThrow(RuntimeException::class);

    expect(file_get_contents($percorso))->toBe('PREESISTENTE');
});

it('answers a non numeric account argument with a clean failure', function () {
    // Su Postgres, senza ctype_digit, è un 22P02 non gestito (T5B-7).
    test()->artisan('easylab:esporta-tenant', [
        'account' => 'Account-SEGRETO-A', '--operatore' => $this->superadmin->email, '--percorso' => $this->cartella.'/x.zip',
    ])->assertFailed();

    expect(File::exists($this->cartella.'/x.zip'))->toBeFalse()->and(auditEsportazioni())->toBe(0);
});

it('renders booleans as 0/1 in the CSV on every driver', function () {
    $percorso = $this->cartella.'/a.zip';
    esporta($this->mondo->riga('A', 'account'), $this->superadmin->email, $percorso)->assertSuccessful();
    $voci = vociZip($percorso);

    expect(righeCsvGdpr($voci['dati/account.csv'])[0]['is_locked'])->toBe('0')
        ->and(righeCsvGdpr($voci['dati/users.csv'])[0]['riceve_email_scadenze'])->toBeIn(['0', '1']);
});
