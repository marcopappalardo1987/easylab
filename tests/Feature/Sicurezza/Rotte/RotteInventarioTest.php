<?php

use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\VerificaFirmaWebhookStripe;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route as Rotta;
use Illuminate\Support\Facades\Route;

/**
 * L'inventario delle rotte come guardrail (security pass S7, T1b — 🔗 ADR-003,
 * ADR-012, ADR-013, ADR-018). L'inventario leggibile sta in
 * `~/Desktop/easylab-s7/T1b-rotte.md`; questo file è la parte che non invecchia.
 *
 * **Ogni rotta passa da `auth`, oppure ha qui una ragione scritta** e il gate che
 * la sostituisce (firma, `guest`, verifica della firma di Stripe). Una rotta
 * nuova senza `auth` fa diventare rosso il primo test finché qualcuno non scrive
 * perché è aperta: è la domanda che conta, non la risposta.
 */

/**
 * Le rotte che non passano da `auth`, con il gate che ne fa le veci.
 * Chiave: nome della rotta, o `METODO uri` per quelle senza nome.
 */
const ROTTE_SENZA_AUTH = [
    // Pagine d'ingresso di Fortify: esistono per chi non è ancora dentro.
    'login' => ['guest', 'form di login'],
    'login.store' => ['guest', 'POST del login, con limiter nominato `login`'],
    'password.request' => ['guest', 'form «password dimenticata»'],
    'password.email' => ['guest', 'invio del link di reset'],
    'password.reset' => ['guest', 'form del reset: il token è nel percorso'],
    'password.update' => ['guest', 'POST del reset: vale solo col token del broker'],
    'two-factor.login' => ['guest', 'secondo passo del login: la sessione porta `login.id`'],
    'two-factor.login.store' => ['guest', 'POST del secondo fattore, con limiter nominato `two-factor`'],

    // Firmate (ADR-012): l'invitato non conosce ancora la propria password.
    'invito.mostra' => ['signed', 'invito: la firma copre id e scadenza'],
    'invito.imposta' => ['signed', 'invito: POST della password, throttle 6/min'],

    // Registrazione pubblica (ADR-012, ADR-032): chi si registra non esiste.
    'registrazione.mostra' => ['guest', 'modulo pubblico di registrazione'],
    'registrazione.avvia' => ['guest', 'POST della registrazione, limiter nominato `registrazione`'],
    'registrazione.controlla-email' => ['guest', 'pagina statica «controlla la posta»'],
    'registrazione.verifica' => ['signed', 'link di verifica della casella'],
    'registrazione.pagamento' => ['signed', 'pagina di pagamento della registrazione'],
    'registrazione.verso-stripe' => ['signed', 'apertura del checkout, throttle 6/min'],
    'registrazione.completata' => ['signed', 'ritorno da Stripe: NON guest, il pagamento è già incassato'],
    'pagamento.ricevuto' => ['pubblica', 'pagina statica di ritorno dai Payment Link, throttle 30/min, non legge parametri'],

    // Webhook (ADR-013): Stripe non ha una sessione, la firma HMAC è il gate.
    'cashier.webhook' => ['webhook', 'firma Stripe verificata da VerificaFirmaWebhookStripe, fail-closed col segreto vuoto'],

    // Framework.
    'GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS /' => ['pubblica', 'redirect statico a /login (Route::redirect registra ogni metodo)'],
    'GET|HEAD up' => ['pubblica', 'health check di Laravel Cloud, nessun dato'],
    'storage.local' => ['signed', 'disco `local` servito dal framework: ServeFile esige la firma'],
    'storage.local.upload' => ['signed', 'upload diretto del disco `local`: ReceiveFile esige la firma'],
    'livewire.preview-file' => ['signed', 'anteprima degli upload Livewire: firma verificata nel controller'],
    'livewire.upload-file' => ['signed', 'upload Livewire: firma verificata nel controller'],
    'default-livewire.update' => ['livewire', 'endpoint Livewire: il componente rieseguirà i middleware persistenti della pagina'],
    'banco' => ['pubblica', 'banco dei componenti: registrato solo in local e testing, nessun dato'],
];

/** Gli asset di Livewire hanno un prefisso con hash che cambia a ogni versione. */
const PREFISSO_ASSET_LIVEWIRE = '#^livewire-[0-9a-f]+/(livewire(\.min|\.csp\.min)?\.js(\.map)?|css/|js/)#';

function chiaveRotta(Rotta $rotta): string
{
    return $rotta->getName() ?? implode('|', $rotta->methods()).' '.$rotta->uri();
}

/** I middleware risolti in classi, senza parametri: `Authenticate:web` → `Authenticate`. */
function middlewareRisolti(Rotta $rotta): array
{
    return array_map(
        fn (string $m) => explode(':', $m, 2)[0],
        app('router')->gatherRouteMiddleware($rotta),
    );
}

function middlewareGrezzi(Rotta $rotta): array
{
    return app('router')->gatherRouteMiddleware($rotta);
}

it('puts auth on every route, or names the gate that replaces it', function () {
    $scoperte = [];

    foreach (Route::getRoutes() as $rotta) {
        if (preg_match(PREFISSO_ASSET_LIVEWIRE, $rotta->uri())) {
            continue;
        }

        if (in_array(Authenticate::class, middlewareRisolti($rotta), true)) {
            continue;
        }

        if (! array_key_exists(chiaveRotta($rotta), ROTTE_SENZA_AUTH)) {
            $scoperte[] = chiaveRotta($rotta);
        }
    }

    expect($scoperte)->toBe([]);
});

it('really applies the gate each exempted route claims', function () {
    $mappa = [
        'guest' => RedirectIfAuthenticated::class,
        'signed' => ValidateSignature::class,
        'webhook' => VerificaFirmaWebhookStripe::class,
    ];
    $firmateNelController = ['storage.local', 'storage.local.upload', 'livewire.preview-file', 'livewire.upload-file'];
    $mancanti = [];

    foreach (Route::getRoutes() as $rotta) {
        [$gate] = ROTTE_SENZA_AUTH[chiaveRotta($rotta)] ?? [null];

        if (! isset($mappa[$gate]) || in_array(chiaveRotta($rotta), $firmateNelController, true)) {
            continue;
        }

        if (! in_array($mappa[$gate], middlewareRisolti($rotta), true)) {
            $mancanti[] = chiaveRotta($rotta)." (manca {$gate})";
        }
    }

    expect($mancanti)->toBe([]);
});

it('keeps no stale exemption for a route that no longer exists', function () {
    $esistenti = collect(Route::getRoutes()->getRoutes())->map(fn (Rotta $r) => chiaveRotta($r))->all();

    expect(array_diff(array_keys(ROTTE_SENZA_AUTH), $esistenti, ['banco']))->toBe([]);
});

it('checks the signature before authentication, binding, guest check, throttle and permission', function () {
    // L'ordine EFFETTIVO, non quello scritto: il router riordina secondo
    // `middlewarePriority`. `bootstrap/app.php` antepone `ValidateSignature` ad
    // `AuthenticatesRequests`, quindi la firma cade prima di tutto ciò che
    // guarda la richiesta: un id manomesso dà 403 (non un 404 che direbbe «questa
    // riga non c'è»), e una firma falsa non arriva nemmeno al login a parcheggiare
    // un `intended` costruito da chi l'ha forgiata.
    //
    // Unica eccezione: `verification.verify` di Fortify, dove `auth` precede
    // `signed` per costruzione del pacchetto (verifica la PROPRIA casella).
    $fuoriOrdine = [];
    $dopoLaFirma = [Authenticate::class, SubstituteBindings::class, RedirectIfAuthenticated::class, ThrottleRequests::class, 'Illuminate\Auth\Middleware\Authorize'];

    foreach (Route::getRoutes() as $rotta) {
        $classi = middlewareRisolti($rotta);
        $posizioneFirma = array_search(ValidateSignature::class, $classi, true);

        if ($posizioneFirma === false || $rotta->getName() === 'verification.verify') {
            continue;
        }

        foreach (array_slice($classi, 0, $posizioneFirma) as $precedente) {
            if (in_array($precedente, $dopoLaFirma, true)) {
                $fuoriOrdine[] = chiaveRotta($rotta)." ({$precedente} prima di signed)";
            }
        }
    }

    expect($fuoriOrdine)->toBe([]);
});

it('keeps the qr, the invite and every registration step behind a signature', function (string $nome) {
    expect(middlewareRisolti(Route::getRoutes()->getByName($nome)))->toContain(ValidateSignature::class);
})->with([
    'qr.strumento', 'invito.mostra', 'invito.imposta',
    'registrazione.verifica', 'registrazione.pagamento', 'registrazione.verso-stripe', 'registrazione.completata',
]);

it('never lets the qr open a scheda without authentication and permission', function () {
    $middleware = middlewareGrezzi(Route::getRoutes()->getByName('qr.strumento'));

    expect($middleware)->toContain(Authenticate::class)
        ->toContain('Illuminate\Auth\Middleware\Authorize:qr.scan');
});

it('throttles every public POST that is not a webhook', function () {
    $senzaLimite = [];

    foreach (Route::getRoutes() as $rotta) {
        $classi = middlewareRisolti($rotta);

        if (! in_array('POST', $rotta->methods(), true)
            || ltrim($rotta->getActionName(), '\\') === RedirectController::class
            || in_array(Authenticate::class, $classi, true)
            || in_array($rotta->getName(), ['cashier.webhook', 'default-livewire.update', 'livewire.upload-file'], true)
            || in_array(ThrottleRequests::class, $classi, true)) {
            continue;
        }

        $senzaLimite[] = chiaveRotta($rotta);
    }

    // `password.email` e `password.update` ricevono il limiter da
    // FortifyServiceProvider: Fortify non ha un'opzione per quelle due.
    expect($senzaLimite)->toBe([]);
});

it('uses the named limiters, never a bare number, on the doors that take a password or an email', function (string $nome, string $limiter) {
    expect(middlewareGrezzi(Route::getRoutes()->getByName($nome)))
        ->toContain(ThrottleRequests::class.':'.$limiter);
})->with([
    ['login.store', 'login'],
    ['two-factor.login.store', 'two-factor'],
    ['registrazione.avvia', 'registrazione'],
    // ⚠️ Queste due sono ROSSE finché R-T1b-9 non separa i limiter del reset
    // (caccia T1bB-3: link e form condividevano il secchio per email).
    ['password.email', 'password-reset-link'],
    ['password.update', 'password-reset'],
]);

it('never counts the reset link and the reset form in the same bucket', function () {
    // T1bB-3: il form di reset non deve portare anche il limiter del link.
    expect(middlewareGrezzi(Route::getRoutes()->getByName('password.update')))
        ->not->toContain(ThrottleRequests::class.':password-reset-link');
});

it('keeps every domain page behind the two factor gate, except the declared escapes', function () {
    // Le uscite dichiarate: il lockout (per pagare bisogna poterci arrivare —
    // e dal 3 Ott 2026 anche per riattivare un piano dopo una disdetta,
    // ADR-045), il QR (redirige su una scheda che il gate ce l'ha), le pagine
    // di Fortify che servono a configurare il 2FA stesso, e l'uscita
    // dall'impersonazione.
    $uscite = [
        'abbonamento.index', 'abbonamento.portale', 'abbonamento.piano', 'bloccato', 'bloccato.passa',
        'qr.strumento', 'logout', 'impersonate.leave',
        'verification.notice', 'verification.verify', 'verification.send',
        'password.confirm', 'password.confirm.store', 'password.confirmation',
        'two-factor.enable', 'two-factor.confirm', 'two-factor.disable',
        'two-factor.qr-code', 'two-factor.secret-key', 'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
    ];
    $senza2fa = [];

    foreach (Route::getRoutes() as $rotta) {
        $classi = middlewareRisolti($rotta);

        if (in_array(Authenticate::class, $classi, true)
            && ! in_array(EnsureTwoFactorIsEnabled::class, $classi, true)
            && ! in_array($rotta->getName(), $uscite, true)) {
            $senza2fa[] = chiaveRotta($rotta);
        }
    }

    // `impersonate` NON è fra le uscite: stava nel solo gruppo `auth` e da lì un
    // Superadmin senza 2FA entrava in qualunque tenant (D-T1b-1).
    expect($senza2fa)->toBe([]);
});
