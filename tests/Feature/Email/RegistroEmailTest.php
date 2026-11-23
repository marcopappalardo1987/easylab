<?php

use App\Models\EmailInviata;
use App\Notifications\AccountGiaEsistente;
use App\Notifications\EmailDiProva;
use App\Support\Email\CampioniEmail;
use App\Support\Email\CatalogoEmail;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SentMessageSymfony;
use Symfony\Component\Mime\Email;

/**
 * Il registro delle email (🔗 ADR-064): che cosa scrive, quando, e che cosa
 * non deve mai fare — fermare la posta, o conservarne il testo.
 *
 * In questi test la posta usa il mailer `array` di `phpunit.xml`: gli eventi
 * di invio partono davvero, solo che il messaggio resta in memoria.
 */
it('records an email accepted by the server, with type, recipients, subject and sender', function () {
    Notification::route('mail', 'cliente@esempio.test')->notifyNow(new AccountGiaEsistente);

    $riga = EmailInviata::query()->sole();

    expect($riga->tipo)->toBe(CatalogoEmail::ACCOUNT_GIA_ESISTENTE)
        ->and($riga->classe)->toBe('AccountGiaEsistente')
        ->and($riga->destinatari)->toBe('cliente@esempio.test')
        ->and($riga->oggetto)->not->toBeEmpty()
        ->and($riga->mittente)->toBe(config('mail.from.address'))
        ->and($riga->mailer)->toBe('array')
        ->and($riga->stato)->toBe(EmailInviata::STATO_ACCETTATA)
        ->and($riga->accettata_at)->not->toBeNull()
        ->and($riga->message_id)->not->toBeEmpty()
        ->and($riga->notifica_id)->not->toBeNull()
        ->and($riga->statoVisibile())->toBe(EmailInviata::STATO_ACCETTATA)
        ->and($riga->nomeTipo())->toBe(CatalogoEmail::trova(CatalogoEmail::ACCOUNT_GIA_ESISTENTE)->nome);
});

it('leaves an email refused by the server «in invio», and calls it not sent after ten minutes', function () {
    // 🔴 Il caso del 23 Nov 2026: il server di posta rifiuta. Laravel non
    // avvisa quando un invio fallisce, quindi la riga resta com'era nata.
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
    ]);

    expect(fn () => Notification::route('mail', 'cliente@esempio.test')->notifyNow(new AccountGiaEsistente))
        ->toThrow(TransportException::class);

    $riga = EmailInviata::query()->sole();

    expect($riga->stato)->toBe(EmailInviata::STATO_IN_INVIO)
        ->and($riga->accettata_at)->toBeNull()
        ->and($riga->statoVisibile())->toBe(EmailInviata::STATO_IN_INVIO)
        ->and(EmailInviata::query()->nelloStato(EmailInviata::STATO_NON_PARTITA)->count())->toBe(0);

    $this->travel(EmailInviata::NON_PARTITA_DOPO_MINUTI + 1)->minutes();

    expect($riga->fresh()->statoVisibile())->toBe(EmailInviata::STATO_NON_PARTITA)
        ->and(EmailInviata::query()->nelloStato(EmailInviata::STATO_NON_PARTITA)->count())->toBe(1)
        ->and(EmailInviata::query()->nelloStato(EmailInviata::STATO_IN_INVIO)->count())->toBe(0);
});

it('never stops an email when the register cannot write', function () {
    // 🔴 Una tabella irraggiungibile toglie una riga al registro, non
    // un'email a un cliente: il guasto si riporta e la posta parte.
    Exceptions::fake();
    Schema::drop('email_inviate');

    Notification::route('mail', 'cliente@esempio.test')->notifyNow(new AccountGiaEsistente);

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
    Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'email_inviate'));
});

it('records a test email from the platform as a test, outside the catalogue', function () {
    $messaggio = CampioniEmail::messaggio(CatalogoEmail::INVITO, 'prove@esempio.test');

    Notification::route('mail', 'prove@esempio.test')->notifyNow(new EmailDiProva($messaggio));

    $riga = EmailInviata::query()->sole();

    expect($riga->tipo)->toBeNull()
        ->and($riga->classe)->toBe('EmailDiProva')
        ->and($riga->nomeTipo())->toBe('Prova di invio')
        ->and($riga->oggetto)->toStartWith('[Prova] ');
});

it('keeps the id the server gave, and records an accepted email even without its first half', function () {
    // La seconda metà da sola: nessuna riga «in invio» da completare, quindi
    // ne nasce una già accettata invece di perdere l'invio. E per SES l'id è
    // quello dell'intestazione che il trasporto aggiunge.
    $email = (new Email)->from('no-reply@easylab.technology')->to('cliente@esempio.test')
        ->cc('copia@esempio.test')->bcc('nascosta@esempio.test')->subject('Oggetto')->text('Testo');
    $email->getHeaders()->addTextHeader('X-SES-Message-ID', 'id-di-ses-123');

    event(new MessageSent(new SentMessage(new SentMessageSymfony($email, Envelope::create($email))), ['mailer' => 'ses']));

    $riga = EmailInviata::query()->sole();

    expect($riga->stato)->toBe(EmailInviata::STATO_ACCETTATA)
        ->and($riga->message_id)->toBe('id-di-ses-123')
        ->and($riga->mailer)->toBe('ses')
        ->and($riga->destinatari)->toBe('cliente@esempio.test, copia@esempio.test, nascosta@esempio.test');
});

it('keeps no text of the email', function () {
    // ⛔ Tipo, destinatari, oggetto e mittente bastano a dire che cosa è
    // partito. Una colonna in più qui è una decisione di privacy (T4), non un
    // dettaglio: questo elenco obbliga a prenderla.
    expect(Schema::getColumnListing('email_inviate'))->toEqualCanonicalizing([
        'id', 'tipo', 'classe', 'destinatari', 'oggetto', 'mittente', 'mailer',
        'stato', 'message_id', 'notifica_id', 'accettata_at', 'created_at', 'updated_at',
    ]);
});

it('prunes the register after thirty days, and not before', function () {
    // Un'ora oltre la soglia se ne va, un'ora prima resta.
    $riga = function (string $nome, int $ore): void {
        $email = (new EmailInviata)->forceFill([
            'destinatari' => "{$nome}@esempio.test", 'stato' => EmailInviata::STATO_ACCETTATA,
        ]);
        $email->save();
        $email->forceFill(['created_at' => now()->subDays(EmailInviata::GIORNI_DI_CONSERVAZIONE)->addHours($ore)])->save();
    };

    $riga('vecchia', -1);
    $riga('recente', 1);

    $this->artisan('model:prune', ['--model' => [EmailInviata::class]])->assertSuccessful();

    expect(EmailInviata::query()->pluck('destinatari')->all())
        ->toBe(['recente@esempio.test']);
});

it('names every notification in the catalogue, and every entry with its notification', function () {
    // 🔴 Senza la mappa completa il registro mostrerebbe un nome di classe al
    // posto del nome che l'email ha in Piattaforma → Email.
    $notifiche = collect(File::files(app_path('Notifications')))
        ->map(fn ($f) => 'App\\Notifications\\'.$f->getFilenameWithoutExtension())
        ->reject(fn (string $c) => $c === EmailDiProva::class)
        ->values()->all();

    expect(array_keys(CatalogoEmail::classi()))->toEqualCanonicalizing($notifiche)
        ->and(array_values(CatalogoEmail::classi()))->toEqualCanonicalizing(array_keys(CatalogoEmail::tutte()));
});

it('writes the register from one place only', function () {
    // 🔴 L'esenzione da `BelongsToTenant` (TenantScopeGuardrailTest) toglie una
    // rete, e questa ne mette un'altra: fuori dal modello e da `RegistroEmail`
    // il registro si legge e basta, e solo dalla sua pagina e dalla potatura.
    $ammessi = [
        'Models/EmailInviata.php',
        'Support/Email/RegistroEmail.php',
        'Livewire/Piattaforma/EmailInviate.php',
        'Support/Retention.php',
    ];

    $chiLoNomina = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => str_contains($f->getContents(), 'EmailInviata'))
        ->map(fn ($f) => $f->getRelativePathname())
        ->values()->all();

    expect($chiLoNomina)->toEqualCanonicalizing($ammessi);

    $pagina = File::get(app_path('Livewire/Piattaforma/EmailInviate.php'));

    foreach (['->save(', 'forceFill(', '->update(', '->delete(', '::create(', '->insert('] as $scrittura) {
        expect(str_contains($pagina, $scrittura))->toBeFalse("La pagina del registro scrive: {$scrittura}");
    }
});
