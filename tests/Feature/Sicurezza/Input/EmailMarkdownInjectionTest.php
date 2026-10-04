<?php

use App\Enums\StatoIntervento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\User;
use App\Support\Mail\MarchioEmail;
use App\Support\Mail\TestoEmail;
use App\Support\Mail\TestoMarkdown;
use App\Support\Notifiche\RigaAvviso;
use App\Support\Notifiche\RigaObsolescenza;
use Illuminate\Mail\Markdown;

/**
 * 🔴 Testo utente nelle email markdown (S7, T1c · D-T1c-1 · ADR-011).
 *
 * `{{ }}` fa escape dell'HTML, non del markdown: un nome con
 * `[testo](https://…)` diventava un link vero, spedito dal dominio di Easy Lab
 * (dalla registrazione pubblica, a un indirizzo scelto da un anonimo). Qui ogni
 * vista di `resources/views/mail/` con testo scritto da utenti viene resa con
 * un valore malevolo in OGNI campo utente, e con un valore normale come
 * controprova: il rimedio non deve lasciare backslash in vista.
 */
const MALEVOLO = "Rossi [Conferma qui](https://evil.example/login) | x | y\n# Titolo *enfasi* _sottolineato_ ![img](https://evil.example/p.png) <https://evil.example/auto>";

const NORMALE = "Unità d'Igiene — Sant'Anna & C. (sede 2) < 4 °C > soglia";

/** HTML e testo, resi come li rende il mailer. */
function resaEmail(string $vista, array $dati): array
{
    $markdown = app(Markdown::class);
    $dati = ['marchio' => MarchioEmail::piattaforma()] + $dati;

    return [(string) $markdown->render($vista, $dati), (string) $markdown->renderText($vista, $dati)];
}

/** @return array<string, array> dati di ogni vista, con `$t` in ogni campo scritto da un utente */
function datiEmail(string $vista, string $t): array
{
    $utente = User::factory()->make(['name' => $t]);

    return match ($vista) {
        'mail.account-eliminato' => ['ragioneSociale' => $t],
        'mail.benvenuto-registrazione' => ['ente' => $t, 'email' => $t, 'url' => 'https://easylab.test/login'],
        'mail.invito-utente' => ['destinatario' => $utente, 'ente' => $t, 'url' => 'https://easylab.test/invito', 'giorni' => 7],
        'mail.verifica-registrazione' => ['nome' => $t, 'ente' => $t, 'url' => 'https://easylab.test/verifica', 'ore' => 24],
        'mail.proposta-piano' => ['ragioneSociale' => $t, 'piano' => $t, 'prezzo' => '49,00', 'url' => 'https://easylab.test/abbonamento'],
        'mail.avviso-obsolescenza' => [
            'ente' => $t, 'soglia' => 10, 'destinatario' => $utente,
            'righe' => [RigaObsolescenza::daStrumento(Strumento::factory()->make([
                'id' => 7, 'nome' => $t, 'data_installazione' => now()->subYears(12),
            ]))],
        ],
        'mail.digest-scadenze' => (function () use ($t, $utente) {
            $riga = fn (string $quando) => RigaAvviso::daIntervento(
                Intervento::factory()->make([
                    'id' => 3, 'strumento_id' => 7, 'descrizione' => $t,
                    'stato' => StatoIntervento::NonFatto, 'data_scadenza' => now()->modify($quando),
                ]),
                $t,
                null,
            );

            return ['ente' => $t, 'destinatario' => $utente, 'scadute' => [$riga('-3 days')], 'imminenti' => [$riga('+3 days')]];
        })(),
    };
}

dataset('viste email', [
    'mail.account-eliminato',
    'mail.benvenuto-registrazione',
    'mail.invito-utente',
    'mail.verifica-registrazione',
    'mail.proposta-piano',
    'mail.avviso-obsolescenza',
    'mail.digest-scadenze',
]);

it('builds no link, image or heading out of user text', function (string $vista) {
    [$html] = resaEmail($vista, datiEmail($vista, MALEVOLO));

    expect($html)->not->toContain('href="https://evil.example')
        ->and($html)->not->toContain('<img src="https://evil.example')
        ->and($html)->not->toContain('<em>enfasi</em>')
        ->and($html)->not->toContain('>Titolo *enfasi*')
        // Positivo: il testo c'è, inerte, con le sue parentesi.
        ->and($html)->toContain('[Conferma qui](https://evil.example/login)');
})->with('viste email');

it('keeps every table row at three cells whatever the user wrote', function (string $vista) {
    [$html] = resaEmail($vista, datiEmail($vista, MALEVOLO));

    preg_match_all('#<tbody>(.*?)</tbody>#s', $html, $corpi);
    $righe = [];
    foreach ($corpi[1] as $corpo) {
        preg_match_all('#<tr>(.*?)</tr>#s', $corpo, $trovate);
        $righe = [...$righe, ...$trovate[1]];
    }

    expect($righe)->not->toBeEmpty();
    foreach ($righe as $riga) {
        // ⚠️ Contare le celle non basta: GFM IGNORA le celle in eccesso, quindi
        // un `|` iniettato non ne aggiunge, tronca il testo e fa scivolare i
        // valori. Si guarda che il testo dell'utente arrivi intero in una cella.
        $testo = html_entity_decode(strip_tags($riga), ENT_QUOTES | ENT_HTML5);

        expect(substr_count($riga, '<td'))->toBe(3)
            ->and($testo)->toContain('| x | y # Titolo *enfasi*')
            ->and($testo)->toMatch('#\d{2}/\d{2}/\d{4}#');
    }
})->with(['mail.avviso-obsolescenza', 'mail.digest-scadenze']);

it('leaves an ordinary name with accents and apostrophes readable, without backslashes', function (string $vista) {
    [$html, $testo] = resaEmail($vista, datiEmail($vista, NORMALE));

    $leggibile = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

    expect($leggibile)->toContain(NORMALE)
        ->and($html)->not->toContain('\\')
        ->and($testo)->toContain(NORMALE)
        ->and($testo)->not->toContain('\\');
})->with('viste email');

it('shows the malicious text in the plain-text part without escape backslashes', function (string $vista) {
    [, $testo] = resaEmail($vista, datiEmail($vista, MALEVOLO));

    expect($testo)->toContain('[Conferma qui](https://evil.example/login)')
        ->and($testo)->not->toContain('\\[');
})->with(['mail.verifica-registrazione', 'mail.invito-utente', 'mail.account-eliminato']);

// --- L'helper ---

it('escapes every character that opens markdown, and flattens line breaks', function () {
    expect(TestoMarkdown::sicuro("a[b](c)\n#d|e*f_g`h<i>!j~k\\"))
        ->toBe('a\\[b\\]\\(c\\) \\#d\\|e\\*f\\_g\\`h<i>\\!j\\~k\\\\');
});

it('leaves apostrophes, accents and dashes alone', function () {
    expect(TestoMarkdown::sicuro("Unità d'Igiene — Sant'Anna & C. < 4 > 2"))->toBe("Unità d'Igiene — Sant'Anna & C. < 4 > 2")
        ->and(TestoMarkdown::sicuro(null))->toBe('');
});

it('lets the plain-text cleaner undo exactly the escapes and nothing else', function () {
    expect(TestoEmail::senzaMarkdown(TestoMarkdown::sicuro('*non enfasi* e [non link](x)')))
        ->toBe('*non enfasi* e [non link](x)')
        // Il markdown DELLE NOSTRE viste continua a essere ripulito.
        ->and(TestoEmail::senzaMarkdown('**Ente** [apri](https://easylab.test)'))
        ->toBe('Ente apri — https://easylab.test');
});
