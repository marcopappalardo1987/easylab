<?php

use App\Models\Registrazione;
use App\Support\Retention;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * 🔴 La contropartita dell'esenzione di `Registrazione` da `TenantScope`
 * (🔗 ADR-012, ADR-018, ADR-032).
 *
 * `TenantScopeGuardrailTest::NON_TENANT_MODELS` esenta questo modello, e
 * l'esenzione **toglie una rete**: chi scrive la riga non è autenticato, quindi
 * non c'è nessun `tenant_id` da timbrare, e il ritorno da Stripe — l'unica rotta
 * del percorso che non è `guest` — arriverebbe con lo scope acceso sul tenant di
 * chi passava di lì, facendo fallire il completamento di un pagamento **già
 * incassato**. Fail-closed nel punto in cui il fail-closed è il danno.
 *
 * Ciò che prende il posto della rete tolta sono **due promesse**, e questo file
 * esiste per renderle meccaniche invece che scritte in un commento:
 *
 * 1. `registrazioni` **non ha nessuna superficie di lettura**: nessun componente
 *    Livewire, nessuna vista, nessuna schermata la elenca;
 * 2. l'unico accesso a una singola riga passa da un **URL firmato**, che copre
 *    id e scadenza — cioè non si enumera.
 *
 * ⚠️ **`\bRegistrazione\b` e non `str_contains`**: senza i confini di parola
 * l'elenco raccoglierebbe anche `BenvenutoRegistrazione` e
 * `VerificaEmailRegistrazione`, cioè si allungherebbe di file che il **tipo** non
 * lo toccano — e un elenco che cresce da sé smette di essere una domanda posta
 * a qualcuno.
 *
 * ⚠️ **Il giorno in cui qualcuno costruisse la schermata «registrazioni in
 * corso»** — una tabella con email, nome e ragione sociale di persone che non
 * sono clienti — questo file diventa rosso e obbliga a rispondere alla domanda
 * che l'esenzione ha rimandato: chi la può vedere, e con quale scope. Senza,
 * quella tabella nascerebbe senza `TenantScope`, senza Policy e senza `can:`, e
 * nessun test se ne accorgerebbe.
 */

/**
 * I file che possono nominare una `Registrazione`, e per quale mestiere.
 *
 * ⚠️ **È un elenco chiuso, ed è tutto il valore del guardrail.** Un elenco
 * aperto («questi non devono») direbbe soltanto ciò che si è già pensato di
 * vietare; questo dice ciò che è **stato considerato**, e ogni file nuovo va
 * aggiunto a mano da chi ha risposto alla domanda dell'esenzione.
 */
const CUSTODI_DELLE_REGISTRAZIONI = [
    'app/Http/Controllers/RegistrazionePubblica.php' => 'il percorso pubblico: modulo, verifica, checkout, ritorno da Stripe',
    'app/Http/Controllers/StripeWebhookController.php' => 'la rete di «ha pagato e ha chiuso la scheda»',
    'app/Models/Registrazione.php' => 'il modello stesso',
    'app/Notifications/VerificaEmailRegistrazione.php' => 'il link firmato di verifica',
    'app/Providers/AppServiceProvider.php' => 'il limiter nominato del modulo pubblico',
    'app/Support/Audit/SoggettiAudit.php' => 'l\'etichetta del soggetto nel registro di audit',
    'app/Support/Listino/LinkDiPagamento.php' => 'il link `/registrati?piano=` della cabina: sa QUALI piani il modulo vende, mai una riga',
    'app/Support/Registrazione/CompletaRegistrazione.php' => 'la nascita dell\'account, e il rifiuto dopo l\'incasso',
    'app/Support/Registrazione/EsitoCheckout.php' => 'il valore letto da Stripe',
    'app/Support/Registrazione/PianiRegistrabili.php' => 'i piani che il modulo pubblico può vendere',
    'app/Support/Registrazione/PortaleCheckout.php' => 'la porta verso Stripe',
    'app/Support/Registrazione/PortaleCheckoutStripe.php' => 'la porta vera',
    'app/Support/Registrazione/RegistrazioneRifiutata.php' => 'il rifiuto, col suo codice',
    'app/Support/Registrazione/SessioneCheckout.php' => 'id e URL della sessione appena aperta',
    'app/Support/Retention.php' => 'la potatura a trenta giorni, che è metà della ragione dell\'esenzione',
];

it('never lets a Livewire component read the waiting room', function () {
    // 🔴 La metà che conta: i componenti Livewire sono le schermate
    // dell'applicazione, e sono **autenticate**. Una di esse che legga
    // `registrazioni` è precisamente la tabella senza scope che l'esenzione ha
    // reso possibile.
    $componenti = collect(File::allFiles(app_path('Livewire')))
        ->filter(fn ($f) => str_contains(File::get($f->getPathname()), 'Registrazione'))
        ->map(fn ($f) => str_replace(base_path().'/', '', $f->getPathname()))
        ->values()->all();

    expect($componenti)->toBe([]);
});

it('never lets a view of the application list the waiting room', function () {
    // Le viste del percorso pubblico (`resources/views/auth/registr*`) sono il
    // percorso stesso e mostrano **la propria** riga, raggiunta da un URL
    // firmato. Tutto il resto no.
    $viste = collect(File::allFiles(resource_path('views')))
        ->reject(fn ($f) => str_starts_with($f->getFilename(), 'registr'))
        ->reject(fn ($f) => str_contains($f->getPathname(), 'views/mail/'))
        ->filter(fn ($f) => str_contains(File::get($f->getPathname()), 'Registrazione::'))
        ->map(fn ($f) => str_replace(base_path().'/', '', $f->getPathname()))
        ->values()->all();

    expect($viste)->toBe([]);
});

it('keeps the list of files that may touch a registration closed', function () {
    // Un file nuovo qui dentro è una **domanda**, non un errore: «questa
    // superficie chi la può vedere, e con quale scope?». Il guardrail la fa
    // porre, che è tutto ciò che può fare un test.
    $trovati = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => preg_match('/\bRegistrazione\b/', File::get($f->getPathname())) === 1)
        ->map(fn ($f) => str_replace(base_path().'/', '', $f->getPathname()))
        ->sort()->values()->all();

    expect($trovati)->toBe(array_keys(CUSTODI_DELLE_REGISTRAZIONI));
});

it('keeps every step that names a registration behind a signature', function () {
    // 🔴 La seconda promessa: l'unico accesso a una singola riga è firmato. La
    // firma copre id e scadenza e cade **prima** del route-model binding, quindi
    // un id manomesso dà 403 e non un 404 che direbbe «questa riga non c'è, prova
    // la prossima».
    $senzaFirma = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), '{registrazione}'))
        ->reject(fn ($r) => in_array('signed', $r->gatherMiddleware(), true))
        ->map(fn ($r) => $r->methods()[0].' '.$r->uri())
        ->values()->all();

    expect($senzaFirma)->toBe([]);
});

it('never puts a registration route behind auth, where a paid customer would be lost', function () {
    // ⚠️ Il verso opposto, e non è una ripetizione: mettere queste rotte nel
    // gruppo `auth` sembrerebbe «più sicuro» e sarebbe il danno — chi torna da
    // Stripe non è autenticato, e chi ci arriva loggato con un altro account
    // verrebbe portato altrove **perdendo il completamento** di un pagamento già
    // incassato.
    $autenticate = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), '{registrazione}') || str_starts_with($r->uri(), 'registrati'))
        ->filter(fn ($r) => (bool) array_intersect(['auth', 'account.lockout', 'two-factor.enforce'], $r->gatherMiddleware()))
        ->map(fn ($r) => $r->methods()[0].' '.$r->uri())
        ->values()->all();

    expect($autenticate)->toBe([]);
});

it('registers no global scope on the model, and says so where the exemption lives', function () {
    // Le due metà del patto, lette insieme: nessuno scope sul modello, e
    // l'esenzione dichiarata per nome nel guardrail della tenancy — che a sua
    // volta nomina **questo** file. Un commento non diventa rosso; questa riga sì.
    expect(array_keys((new Registrazione)->getGlobalScopes()))->toBe([]);

    $guardrail = File::get(base_path('tests/Feature/TenantScopeGuardrailTest.php'));

    expect($guardrail)->toContain('Registrazione::class')
        ->and($guardrail)->toContain('tests/Feature/Registrazione/AccessoRegistrazioniGuardrailTest.php');
});

it('lets the waiting room prune itself, which is the other half of the exemption', function () {
    // Una tabella senza scope che porta email e hash di password di persone che
    // non sono clienti è accettabile **perché non resta**: trenta giorni, e le
    // pendenti muoiono da sole.
    expect(Retention::MODELLI)->toContain(Registrazione::class);
});
