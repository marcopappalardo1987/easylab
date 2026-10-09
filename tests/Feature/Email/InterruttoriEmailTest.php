<?php

use App\Models\User;
use App\Notifications\AvvisoObsolescenza;
use App\Notifications\DigestScadenze;
use App\Support\AuditLog;
use App\Support\Email\CampioniEmail;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;

/**
 * Il catalogo delle email e i loro interruttori (🔗 ADR-047).
 *
 * È il posto da cui dipende **cosa esce dall'applicazione verso i clienti**:
 * un'email che nasce accesa per errore, o un interruttore che non spegne, non
 * si vedono in nessuna schermata — si vedono nella casella di qualcuno.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

const EMAIL_NUOVE = [
    CatalogoEmail::MACCHINA_SEGNALATA,
    CatalogoEmail::INTERVENTO_PROGRAMMATO,
    CatalogoEmail::INTERVENTO_ESEGUITO,
    CatalogoEmail::INTERVENTO_ASSEGNATO,
    CatalogoEmail::ACCOUNT_BLOCCATO,
    CatalogoEmail::PIANO_CAMBIATO,
];

const EMAIL_DI_SERVIZIO = [
    CatalogoEmail::INVITO,
    CatalogoEmail::PROPOSTA_PIANO,
    CatalogoEmail::VERIFICA_INDIRIZZO,
    CatalogoEmail::ACCOUNT_GIA_ESISTENTE,
    CatalogoEmail::BENVENUTO,
    CatalogoEmail::ACCOUNT_ELIMINATO,
    CatalogoEmail::NUOVO_ERRORE,
    CatalogoEmail::RECUPERO_PASSWORD,
];

// ─── Il catalogo ─────────────────────────────────────────────────────────────

it('keeps every new email off until somebody turns it on', function (string $chiave) {
    // 🔴 Un deploy non deve cominciare a scrivere ai clienti di sua iniziativa.
    expect(CatalogoEmail::trova($chiave)->nataAccesa)->toBeFalse()
        ->and(InterruttoriEmail::attiva($chiave))->toBeFalse();
})->with(EMAIL_NUOVE);

it('leaves the two emails that already existed on, as they were', function () {
    expect(InterruttoriEmail::attiva(CatalogoEmail::RIEPILOGO_SCADENZE))->toBeTrue()
        ->and(InterruttoriEmail::attiva(CatalogoEmail::AVVISO_OBSOLESCENZA))->toBeTrue();
});

it('gives no switch to the service emails, whatever is written in the table', function (string $chiave) {
    // Senza l'invito o il recupero password qualcuno non entra: una riga
    // scritta a mano non deve poterle spegnere.
    DB::table('interruttori_email')->insert(['chiave' => $chiave, 'attiva' => false, 'created_at' => now(), 'updated_at' => now()]);

    expect(CatalogoEmail::trova($chiave)->sospendibile)->toBeFalse()
        ->and(InterruttoriEmail::attiva($chiave))->toBeTrue()
        ->and(fn () => InterruttoriEmail::imposta($chiave, false))->toThrow(InvalidArgumentException::class);
})->with(EMAIL_DI_SERVIZIO);

it('partitions the catalogue into informative and service emails, with nothing left out', function () {
    $sospendibili = array_keys(array_filter(CatalogoEmail::tutte(), fn ($t) => $t->sospendibile));

    expect($sospendibili)->toEqualCanonicalizing([
        CatalogoEmail::RIEPILOGO_SCADENZE, CatalogoEmail::AVVISO_OBSOLESCENZA, ...EMAIL_NUOVE,
    ])->and(array_keys(CatalogoEmail::tutte()))->toEqualCanonicalizing([...$sospendibili, ...EMAIL_DI_SERVIZIO]);
});

it('has a sample for every email of the catalogue, and the sample renders', function (string $chiave) {
    // La prova di invio passa di qui: un'email aggiunta al catalogo senza il
    // suo esemplare farebbe esplodere il bottone, e questa riga lo dice prima.
    $messaggio = CampioniEmail::messaggio($chiave, 'prova@esempio.test');

    expect($messaggio->subject)->toStartWith('[Prova] ')
        ->and((string) $messaggio->render())->not->toBe('');
})->with(fn () => array_keys(CatalogoEmail::tutte()));

it('lists in the catalogue every notification that sends mail', function () {
    // 🔴 La rete che tiene onesto il catalogo: una notifica nuova in
    // `app/Notifications` che non compare in pagina è un'email che parte senza
    // che nessuno sappia che esiste. `EmailDiProva` è il veicolo delle prove,
    // non un'email del prodotto.
    $classi = collect(File::files(app_path('Notifications')))
        ->map(fn ($f) => $f->getFilenameWithoutExtension())
        ->reject(fn (string $c) => $c === 'EmailDiProva')
        ->sort()->values()->all();

    // Una per voce di catalogo, tranne il recupero password che è del framework.
    expect($classi)->toHaveCount(count(CatalogoEmail::tutte()) - 1);
});

// ─── L'interruttore di piattaforma ───────────────────────────────────────────

it('turns an email on and off, and writes who did it', function () {
    $chi = User::factory()->create();

    InterruttoriEmail::imposta(CatalogoEmail::INTERVENTO_ESEGUITO, true, $chi);

    expect(InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_ESEGUITO))->toBeTrue();

    $riga = Activity::where('log_name', AuditLog::NAME)->latest('id')->first();

    expect($riga->description)->toBe('Email accesa')
        ->and($riga->causer_id)->toBe($chi->id)
        ->and($riga->properties['email'])->toBe(CatalogoEmail::INTERVENTO_ESEGUITO);

    InterruttoriEmail::imposta(CatalogoEmail::INTERVENTO_ESEGUITO, false, $chi);

    expect(InterruttoriEmail::attiva(CatalogoEmail::INTERVENTO_ESEGUITO))->toBeFalse()
        ->and(Activity::where('log_name', AuditLog::NAME)->latest('id')->first()->description)->toBe('Email spenta');
});

it('turns off an email that was born on', function () {
    // Il verso che conta per le due email esistenti: lo stato di nascita è
    // «accesa», e la riga in tabella deve poterlo contraddire.
    InterruttoriEmail::imposta(CatalogoEmail::RIEPILOGO_SCADENZE, false);

    expect(InterruttoriEmail::attiva(CatalogoEmail::RIEPILOGO_SCADENZE))->toBeFalse()
        // Le altre non si muovono: l'interruttore è per email, non uno solo.
        ->and(InterruttoriEmail::attiva(CatalogoEmail::AVVISO_OBSOLESCENZA))->toBeTrue();
});

it('writes nothing, and no second audit row, when the state does not change', function () {
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, true);
    $righe = Activity::where('log_name', AuditLog::NAME)->count();
    $nataIl = DB::table('interruttori_email')->where('chiave', CatalogoEmail::PIANO_CAMBIATO)->value('created_at');

    $this->travel(5)->minutes();
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, true);

    expect(Activity::where('log_name', AuditLog::NAME)->count())->toBe($righe);

    // E quando cambia davvero, la data di nascita dell'interruttore resta.
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, false);

    expect(DB::table('interruttori_email')->where('chiave', CatalogoEmail::PIANO_CAMBIATO)->value('created_at'))->toBe($nataIl)
        ->and(DB::table('interruttori_email')->count())->toBe(1);
});

it('refuses a key that is not in the catalogue', function () {
    expect(fn () => InterruttoriEmail::attiva('inventata'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InterruttoriEmail::imposta('inventata', true))->toThrow(InvalidArgumentException::class)
        ->and(CatalogoEmail::esiste('inventata'))->toBeFalse()
        ->and(CatalogoEmail::esiste(['riepilogo_scadenze']))->toBeFalse();
});

// ─── La scelta della persona ─────────────────────────────────────────────────

it('respects the person who gave an email up, and only for that email', function () {
    $persona = User::factory()->create();
    $persona->forceFill(['riceve_email_interventi_eseguiti' => false])->save();
    $persona = $persona->fresh();

    expect(InterruttoriEmail::voluta(CatalogoEmail::INTERVENTO_ESEGUITO, $persona))->toBeFalse()
        ->and(InterruttoriEmail::voluta(CatalogoEmail::INTERVENTO_PROGRAMMATO, $persona))->toBeTrue()
        ->and(InterruttoriEmail::voluta(CatalogoEmail::RIEPILOGO_SCADENZE, $persona))->toBeTrue();
});

it('asks the database when the preference column was not loaded', function () {
    // 🔴 Un'istanza appena creata non ha i default di colonna, e una query con
    // `select()` può lasciarla fuori. `null` letto come «no» toglierebbe
    // l'email a chi non vi ha mai rinunciato; letto come «sì» la manderebbe a
    // chi vi ha rinunciato.
    $persona = User::factory()->create();
    $persona->forceFill(['riceve_email_interventi_assegnati' => false])->save();

    $parziale = User::query()->select('id', 'name', 'email')->findOrFail($persona->id);
    $appenaNata = User::factory()->create();

    expect(InterruttoriEmail::voluta(CatalogoEmail::INTERVENTO_ASSEGNATO, $parziale))->toBeFalse()
        ->and(InterruttoriEmail::voluta(CatalogoEmail::INTERVENTO_ASSEGNATO, $appenaNata))->toBeTrue();
});

it('has no preference to read for a bare address, or for an email nobody can give up', function () {
    $casella = (new AnonymousNotifiable)->route('mail', 'qualcuno@esempio.test');
    $persona = User::factory()->create()->fresh();

    expect(InterruttoriEmail::voluta(CatalogoEmail::RIEPILOGO_SCADENZE, $casella))->toBeTrue()
        ->and(CatalogoEmail::trova(CatalogoEmail::ACCOUNT_BLOCCATO)->preferenza)->toBeNull()
        ->and(InterruttoriEmail::voluta(CatalogoEmail::ACCOUNT_BLOCCATO, $persona))->toBeTrue();
});

it('lets an email leave only when the platform and the person both say yes', function () {
    $vuole = User::factory()->create()->fresh();
    $rinuncia = User::factory()->create();
    $rinuncia->forceFill(['riceve_email_interventi_programmati' => false])->save();
    $rinuncia = $rinuncia->fresh();

    // Spenta in piattaforma: non parte per nessuno, nemmeno per chi la vuole.
    expect(InterruttoriEmail::parte(CatalogoEmail::INTERVENTO_PROGRAMMATO, $vuole))->toBeFalse();

    InterruttoriEmail::imposta(CatalogoEmail::INTERVENTO_PROGRAMMATO, true);

    expect(InterruttoriEmail::parte(CatalogoEmail::INTERVENTO_PROGRAMMATO, $vuole))->toBeTrue()
        ->and(InterruttoriEmail::parte(CatalogoEmail::INTERVENTO_PROGRAMMATO, $rinuncia))->toBeFalse();
});

// ─── Le due email che esistevano già ─────────────────────────────────────────

it('drops the mail and keeps the bell when the platform turns a scheduled email off', function () {
    // Spegnere un'email non spegne ciò che si vede entrando: la campanella
    // resta, per entrambe. E ogni interruttore spegne la sua, non l'altra.
    $persona = User::factory()->create()->fresh();
    $riepilogo = new DigestScadenze(1, 'Ente A', []);
    $obsolescenza = new AvvisoObsolescenza(1, 'Ente A', 10, []);

    expect($riepilogo->via($persona))->toBe(['database', 'mail'])
        ->and($obsolescenza->via($persona))->toBe(['database', 'mail']);

    InterruttoriEmail::imposta(CatalogoEmail::RIEPILOGO_SCADENZE, false);

    expect($riepilogo->via($persona))->toBe(['database'])
        ->and($obsolescenza->via($persona))->toBe(['database', 'mail']);

    InterruttoriEmail::imposta(CatalogoEmail::RIEPILOGO_SCADENZE, true);
    InterruttoriEmail::imposta(CatalogoEmail::AVVISO_OBSOLESCENZA, false);

    expect($riepilogo->via($persona))->toBe(['database', 'mail'])
        ->and($obsolescenza->via($persona))->toBe(['database']);
});

it('still lets a person give the two scheduled emails up, as before', function () {
    $persona = User::factory()->create();
    $persona->forceFill(['riceve_email_scadenze' => false])->save();
    $persona = $persona->fresh();

    expect((new DigestScadenze(1, 'Ente A', []))->via($persona))->toBe(['database'])
        ->and((new AvvisoObsolescenza(1, 'Ente A', 10, []))->via($persona))->toBe(['database']);
});
