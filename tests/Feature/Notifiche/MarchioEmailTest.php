<?php

use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\DigestScadenze;
use App\Notifications\InvitoUtente;
use App\Notifications\NuovoErrore;
use App\Support\Mail\MarchioEmail;
use App\Support\Notifiche\RigaAvviso;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

/**
 * 🔴 Il **marchio** delle email (🔗 ADR-011, ADR-018, ADR-033, ADR-034) — area
 * rossa della Policy di Code Review su due fronti insieme: **tenancy** (il logo
 * e il colore di un cliente non devono mai finire nella posta di un altro) e
 * **privacy** (un'email esce dal perimetro dell'applicazione e viene inoltrata a
 * lettori che nessuno ha autorizzato).
 *
 * ## Perché si legge la posta VERA e non `Notification::fake()`
 *
 * Un fake conserva l'**oggetto notifica**: asserire su quello proverebbe cosa si
 * è passato al costruttore, non cosa è finito nell'email. Qui il branding nasce
 * *dentro* `toMail()` e vive nel corpo reso — e il logo, per giunta, non sta nel
 * corpo affatto ma in un `DataPart` allegato. È la stessa scelta di
 * `AlertErroriTest`, e qui vale doppio.
 *
 * ⚠️ **`MailMessage::render()` NON basta per il logo**, ed è una divergenza da
 * segnalare: per una notifica *markdown* quel metodo chiama
 * `Illuminate\Mail\Markdown::render()` direttamente, **senza** passare da
 * `Mailer` — quindi `$message` non esiste nei dati della vista, `cid()` torna
 * `null` e nessuna immagine viene incorporata. La sola strada che esercita
 * l'incorporamento è l'invio vero, che sotto `MAIL_MAILER=array` resta in
 * memoria.
 *
 * ## Il canale di fuga che il branding aggiunge
 *
 * `NotificaScadenzeTest` ha già il non-trapelamento sul **testo** («il digest di
 * un Ente non nomina l'altro»). Il marchio ne apre due nuovi sulla stessa email:
 * il **colore** (una stringa nel corpo) e il **logo** (byte binari, che nel testo
 * non compaiono affatto e vivono negli allegati). Per questo ogni prova qui
 * guarda oggetto, corpo HTML, corpo testuale **e** allegati.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(MarchioEmail::DISCO);
});

/** @return Collection<int, Email> */
function postaDelMarchio(): Collection
{
    return collect(Mail::mailer()->getSymfonyTransport()->messages())
        ->map(fn ($inviata) => $inviata->getOriginalMessage());
}

/** L'email consegnata a un indirizzo. */
function emailPer(string $indirizzo): Email
{
    $trovata = postaDelMarchio()->first(
        fn (Email $email) => collect($email->getTo())->contains(fn ($a) => $a->getAddress() === $indirizzo)
    );

    expect($trovata)->not->toBeNull("Nessuna email consegnata a {$indirizzo}.");

    return $trovata;
}

/**
 * Tutto ciò che un destinatario può **leggere**: oggetto, corpo HTML e corpo
 * testuale.
 *
 * ⚠️ Tutti e tre, come in `AlertErroriTest`: il canale mail di una notifica
 * markdown costruisce **due** corpi, e una fuga presente solo in quello testuale
 * sarebbe invisibile a un test che guarda il primo.
 */
function testoDiTutta(Email $email): string
{
    return implode("\n", [$email->getSubject(), (string) $email->getHtmlBody(), (string) $email->getTextBody()]);
}

/** I byte di tutti gli allegati (il logo viaggia come `DataPart` inline). */
function bytesAllegati(Email $email): string
{
    return collect($email->getAttachments())
        ->map(fn ($parte) => $parte->getBody())
        ->implode("\n");
}

/** Un Ente con un Admin, un'unica macchina e un intervento in scadenza. */
function enteConScadenza(string $nome, ?string $colore, ?string $logo, string $emailAdmin, string $macchina): array
{
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => $nome]);
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();
    $strumento = Strumento::factory()->forNode($dept)->create(['nome' => $macchina]);

    Intervento::factory()->forStrumento($strumento)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    if ($colore !== null || $logo !== null) {
        $ente->fissaMarchioEmail($logo, $colore);
    }

    $admin = User::factory()->create(['tenant_id' => $ente->id, 'email' => $emailAdmin]);
    $admin->assignRole('Admin');

    return [$ente, $admin, $strumento];
}

/** Il digest di un Ente, spedito davvero (coda `sync`, transport `array`). */
function spedisciDigest(UnitaOrganizzativa $ente, User $admin, Strumento $strumento): void
{
    $intervento = Intervento::factory()->forStrumento($strumento)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    $admin->notify(new DigestScadenze($ente->id, $ente->nome, [
        RigaAvviso::daIntervento($intervento, $strumento->nome, $strumento->unita_organizzativa_id),
    ]));
}

it('never lets the branding of one Ente reach the mailbox of another', function () {
    // 🔴 **Il test rosso principale.** Due Enti brandizzati in modo distinto, lo
    // scheduler che fa partire entrambi i digest, e l'email di A che non deve
    // portare niente di B — né il nome, né il colore, né **i byte del logo**,
    // che è il canale che nessun test precedente guardava.
    Storage::disk(MarchioEmail::DISCO)->put('marchi/1/a.png', 'PNG-DEL-LABORATORIO-ROSSI-8811');
    Storage::disk(MarchioEmail::DISCO)->put('marchi/2/b.png', 'PNG-DELLA-CLINICA-AURORA-9922');

    [$enteA, $adminA] = enteConScadenza('Laboratorio Rossi', '#123456', 'marchi/1/a.png', 'anna@rossi.test', 'Agitatore 2358');
    [$enteB] = enteConScadenza('Clinica Aurora', '#abcdef', 'marchi/2/b.png', 'bruno@aurora.test', 'Centrifuga B');

    $this->artisan('easylab:notifica-scadenze')->assertSuccessful();

    $email = emailPer('anna@rossi.test');
    $testo = testoDiTutta($email);
    $allegati = bytesAllegati($email);

    // Il positivo di controllo: senza, «non contiene B» sarebbe soddisfatto
    // anche da un'email vuota o non brandizzata affatto.
    expect($testo)->toContain('Laboratorio Rossi')
        ->and($testo)->toContain('#123456')
        ->and($allegati)->toContain('PNG-DEL-LABORATORIO-ROSSI-8811');

    // 🔴 E il negativo, un ago per `expect()`: `toContain()` è VARIADICO, e un
    // secondo argomento diventa un secondo ago invece di un messaggio — in
    // un'asserzione negativa renderebbe il guardrail sempre soddisfatto.
    expect($testo)->not->toContain('Clinica Aurora');
    expect($testo)->not->toContain('#abcdef');
    expect($testo)->not->toContain('PNG-DELLA-CLINICA-AURORA-9922');
    expect($allegati)->not->toContain('PNG-DELLA-CLINICA-AURORA-9922');
    expect($allegati)->not->toContain('Clinica Aurora');

    // E la contro-prova dall'altra parte: B ha davvero il proprio marchio,
    // quindi il branding funziona e il test sopra non è verde per spegnimento.
    $suo = emailPer('bruno@aurora.test');
    expect(testoDiTutta($suo))->toContain('Clinica Aurora');
    expect(bytesAllegati($suo))->toContain('PNG-DELLA-CLINICA-AURORA-9922');

    expect($enteA->id)->not->toBe($enteB->id);
});

it('never brands the platform alert with a tenant, by construction and not by convention', function () {
    // 🔴 L'alert dell'error tracker è posta di servizio verso una casella
    // tecnica, e nasce da un errore che può essere capitato dentro QUALSIASI
    // tenant: deve essere impossibile che porti il marchio di un cliente.
    Storage::disk(MarchioEmail::DISCO)->put('marchi/1/a.png', 'PNG-DEL-LABORATORIO-ROSSI-8811');

    [$ente, $admin] = enteConScadenza('Laboratorio Rossi', '#123456', 'marchi/1/a.png', 'anna@rossi.test', 'Agitatore 2358');

    // L'utente del tenant è **autenticato** mentre l'alert parte: è lo scenario
    // in cui un marchio letto da `auth()` invece che dal payload uscirebbe col
    // logo del cliente.
    $this->actingAs($admin);

    Notification::route('mail', 'allerta@easylab.test')
        ->notify(new NuovoErrore(RuntimeException::class, 'app/Guasto.php:12', 3, 77));

    $email = emailPer('allerta@easylab.test');
    $testo = testoDiTutta($email);
    $allegati = bytesAllegati($email);

    expect($testo)->toContain('Easy Lab');
    expect($testo)->toContain(MarchioEmail::COLORE_EASYLAB);
    expect($allegati)->toContain(file_get_contents(public_path(MarchioEmail::LOGO_EASYLAB)));

    expect($testo)->not->toContain('Laboratorio Rossi');
    expect($testo)->not->toContain('#123456');
    expect($allegati)->not->toContain('PNG-DEL-LABORATORIO-ROSSI-8811');

    expect($ente->marchio_colore)->toBe('#123456');
});

it('never leaves an English footer in any of the three emails', function () {
    // Il difetto che ADR-011 elencava: il template del pacchetto chiude con
    // «All rights reserved», «Regards,» e «If you're having trouble clicking».
    // Tre stringhe che nessuno aveva notato, perché il percorso di
    // `->greeting()/->line()` non era mai stato letto.
    [$ente, $admin, $strumento] = enteConScadenza('Laboratorio Rossi', null, null, 'anna@rossi.test', 'Agitatore 2358');

    spedisciDigest($ente, $admin, $strumento);
    $admin->notify(new InvitoUtente($ente->nome, $admin->email, $ente->id));
    Notification::route('mail', 'allerta@easylab.test')
        ->notify(new NuovoErrore(RuntimeException::class, 'app/Guasto.php:12', 3, 77));

    expect(postaDelMarchio())->toHaveCount(3);

    foreach (postaDelMarchio() as $email) {
        $testo = testoDiTutta($email);

        // ⚠️ Un ago per `expect()`, mai due argomenti: `toContain()` è variadico
        // e la spiegazione va nel nome del test o qui in un commento.
        expect($testo)->not->toContain('All rights reserved');
        expect($testo)->not->toContain('Regards');
        expect($testo)->not->toContain('Hello!');
        expect($testo)->not->toContain("If you're having trouble");
        expect($testo)->toContain('Easy Lab');
        // Il piè di pagina italiano, corpo HTML **e** corpo testuale.
        expect((string) $email->getHtmlBody())->toContain('Gestione strumentazione e manutenzione');
        expect((string) $email->getTextBody())->toContain('Gestione strumentazione e manutenzione');
    }
});

it('never lets the footer name the ricambio, which a privacy test elsewhere depends on', function () {
    // 🔴 `DigestScadenzeTest` asserisce `not->toContain('ricambio')` sull'email
    // RESA (ADR-004): dire «garanzia ricambio» rivelerebbe che sulla macchina
    // c'è un pezzo sostituito. Un claim di piè di pagina tipo «strumentazione,
    // interventi e ricambi» renderebbe rosso un test di privacy che non c'entra
    // niente col marchio — e chi lo vedesse fallire cercherebbe il difetto nel
    // posto sbagliato. La regola vale sul LAYOUT, quindi si prova qui.
    $layout = implode("\n", [
        file_get_contents(resource_path('views/vendor/mail/html/footer.blade.php')),
        file_get_contents(resource_path('views/vendor/mail/text/footer.blade.php')),
        file_get_contents(resource_path('views/vendor/mail/html/message.blade.php')),
        file_get_contents(resource_path('views/vendor/mail/text/message.blade.php')),
    ]);

    // I commenti Blade si tolgono prima di guardare: questo file *spiega* la
    // regola nominando la parola, e un meta-test che leggesse il testo grezzo
    // punirebbe chi documenta.
    $layout = preg_replace('/\{\{--.*?--\}\}/s', '', $layout);

    expect($layout)->not->toContain('ricambio');
    expect($layout)->not->toContain('ricambi');
});

it('renders with our own theme, so a wrong mail.markdown.theme cannot pass unnoticed', function () {
    // ⚠️ `config/mail.php` non aveva NESSUNA chiave `markdown`, quindi
    // `MailChannel::markdownRenderer()` ricadeva sul tema del pacchetto. Se il
    // tema `easylab` non si risolvesse, le email tornerebbero grigie e stock e
    // nessun test se ne accorgerebbe: è la trappola di colore di sempre —
    // pagina 200, markup giusto, colore assente. Resa falsificabile qui.
    expect(resource_path('views/vendor/mail/html/themes/easylab.css'))->toBeFile();
    expect(config('mail.markdown.theme'))->toBe('easylab');

    [$ente, $admin, $strumento] = enteConScadenza('Laboratorio Rossi', null, null, 'anna@rossi.test', 'Agitatore 2358');
    spedisciDigest($ente, $admin, $strumento);

    $html = (string) emailPer('anna@rossi.test')->getHtmlBody();

    // Due valori che SOLO `easylab.css` produce, inlinati da `CssToInlineStyles`:
    // il blu del marchio sul pulsante e il fondo pagina del Design System.
    expect($html)->toContain(MarchioEmail::COLORE_EASYLAB);
    expect($html)->toContain('#f6f8fb');
    // E ciò che il tema del pacchetto avrebbe messo al loro posto.
    expect($html)->not->toContain('#18181b');
});

it('falls back to Easy Lab instead of losing the email', function () {
    // (d) ⚠️ Un `<img>` verso un file assente non rompe niente: la pagina — o
    // l'email — esce senza dire nulla. Stessa forma di `MarchioTest`, e qui
    // serve perché quel file NON è coperto da lì: `MarchioTest` estrae i
    // percorsi solo da `brand-logo.blade.php`, che nomina gli SVG.
    expect(public_path(MarchioEmail::LOGO_EASYLAB))->toBeFile();

    // (a) Ente senza branding: nome dell'Ente in testata, blu di Easy Lab.
    [$ente, $admin, $strumento] = enteConScadenza('Laboratorio Rossi', null, null, 'anna@rossi.test', 'Agitatore 2358');
    $marchio = MarchioEmail::perEnte($ente->id);

    expect($marchio->nome)->toBe('Laboratorio Rossi');
    expect($marchio->colore)->toBe(MarchioEmail::COLORE_EASYLAB);
    expect($marchio->logoPath)->toBeNull();

    // (b) Colonna valorizzata, FILE ASSENTE sul disco: l'email parte lo stesso,
    // col logo Easy Lab, e non vola nessuna eccezione. Senza questa ricaduta il
    // job fallirebbe e `failed()` scriverebbe «invito non consegnato» per un
    // logo mancante.
    $ente->fissaMarchioEmail('marchi/'.$ente->id.'/sparito.png', null);

    spedisciDigest($ente, $admin, $strumento);

    $email = emailPer('anna@rossi.test');
    expect(bytesAllegati($email))->toBe(file_get_contents(public_path(MarchioEmail::LOGO_EASYLAB)));
    expect(testoDiTutta($email))->toContain('Laboratorio Rossi');

    // (c) Ente cestinato fra accodamento e consegna: si ricade su piattaforma
    // invece di esplodere. 🔴 È anche la prova che `withoutGlobalScopes()` è
    // stato chiamato con la lista ESPLICITA: nudo toglierebbe pure il soft
    // delete, e questo Ente tornerebbe visibile — il difetto già pagato da
    // `Account::enti()`.
    $ente->delete();

    $dopoCestino = MarchioEmail::perEnte($ente->id);
    expect($dopoCestino->diPiattaforma)->toBeTrue();
    expect($dopoCestino->nome)->not->toBe('Laboratorio Rossi');

    // E un id che non esiste affatto, che è il caso del payload sopravvissuto
    // a una cancellazione definitiva.
    expect(MarchioEmail::perEnte(999999)->diPiattaforma)->toBeTrue();
    expect(MarchioEmail::perEnte(null)->diPiattaforma)->toBeTrue();
});

it('never reads the brand from a node that is not an Ente', function () {
    // Il marchio vive sul nodo `tipo = ente`. Un dipartimento con le colonne
    // valorizzate a mano — o un id di dipartimento finito per errore nel
    // payload — non deve produrre un marchio: ricade su Easy Lab.
    $ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Laboratorio Rossi']);
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => 'Reparto Chimica']);

    expect(MarchioEmail::perEnte($dept->id)->diPiattaforma)->toBeTrue();
    expect(MarchioEmail::perEnte($dept->id)->nome)->not->toBe('Reparto Chimica');
});

it('picks the ink on the button instead of assuming white', function () {
    // ⚠️ Il colore lo sceglie il cliente e può essere giallo canarino: bianco su
    // giallo è illeggibile. «Il contrasto è una relazione fra due colori, non
    // una proprietà di uno» (ADR-034).
    expect(MarchioEmail::inchiostroSu('#06589c'))->toBe('#ffffff');
    expect(MarchioEmail::inchiostroSu('#000000'))->toBe('#ffffff');
    expect(MarchioEmail::inchiostroSu('#ffff00'))->toBe('#0f172a');
    expect(MarchioEmail::inchiostroSu('#ffffff'))->toBe('#0f172a');

    // E il valore calcolato arriva davvero nel corpo dell'email, o il calcolo
    // sarebbe corretto e inutile.
    [$ente, $admin, $strumento] = enteConScadenza('Laboratorio Rossi', '#ffff00', null, 'anna@rossi.test', 'Agitatore 2358');
    spedisciDigest($ente, $admin, $strumento);

    $html = (string) emailPer('anna@rossi.test')->getHtmlBody();

    expect($html)->toContain('#ffff00');
    expect($html)->toContain('#0f172a');
});

it('never leaves raw markdown in the plain text body of the digest', function () {
    // Il secondo difetto che ADR-011 elenca: nella parte testo le tabelle
    // restavano in forma markdown grezza — `|:---------|` e pipe ovunque. Chi
    // legge in solo testo vedeva la sorgente della tabella, non la tabella.
    [$ente, $admin, $strumento] = enteConScadenza('Laboratorio Rossi', null, null, 'anna@rossi.test', 'Agitatore 2358');
    spedisciDigest($ente, $admin, $strumento);

    $testo = (string) emailPer('anna@rossi.test')->getTextBody();

    expect($testo)->not->toContain('|:---');
    expect($testo)->not->toContain(' | ');
    // E ciò che DEVE esserci al loro posto, o «niente pipe» sarebbe soddisfatto
    // da un corpo testuale vuoto.
    expect($testo)->toContain('Agitatore 2358');
    expect($testo)->toContain(today()->addDays(5)->format('d/m/Y'));
});

it('embeds the logo once, not once per rendered body', function () {
    // ⚠️ Nel canale mail delle notifiche `MailChannel::buildView()` restituisce
    // DUE closure — html e testo — e `Mailer::addContent()` le invoca entrambe
    // con lo **stesso** `Illuminate\Mail\Message`. Senza la memoizzazione in
    // `MarchioEmail::cid()` il logo finirebbe nell'email due volte, come due
    // `DataPart` distinti: un allegato in più che nessuno vede finché non apre
    // il messaggio grezzo. (E no: il `TextMessage` che restituisce stringa
    // vuota da `embed()` è usato solo da `Mailable`, non da questo canale.)
    [$ente, $admin, $strumento] = enteConScadenza('Laboratorio Rossi', null, null, 'anna@rossi.test', 'Agitatore 2358');
    spedisciDigest($ente, $admin, $strumento);

    expect(emailPer('anna@rossi.test')->getAttachments())->toHaveCount(1);
});

it('keeps the branding in the body and never in the envelope', function () {
    // ⛔ ADR-011 mette la recapitabilità (SPF/DKIM/DMARC/PTR sul dominio Easy
    // Lab) a nostro carico: un `From` o un `Reply-To` sul dominio del cliente
    // romperebbe l'allineamento DKIM e manderebbe tutto in spam. Il branding sta
    // nel CORPO, mai nella busta.
    Storage::disk(MarchioEmail::DISCO)->put('marchi/1/a.png', 'PNG-DEL-LABORATORIO-ROSSI-8811');
    [$ente, $admin, $strumento] = enteConScadenza('Laboratorio Rossi', '#123456', 'marchi/1/a.png', 'anna@rossi.test', 'Agitatore 2358');
    spedisciDigest($ente, $admin, $strumento);

    $email = emailPer('anna@rossi.test');

    $mittenti = collect($email->getFrom())->map(fn ($a) => $a->getAddress().' '.$a->getName())->implode(' ');

    expect($mittenti)->not->toContain('Laboratorio Rossi');
    expect($email->getReplyTo())->toBe([]);
});
