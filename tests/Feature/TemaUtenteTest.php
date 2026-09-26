<?php

use App\Enums\TemaUtente;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * La preferenza di tema, dal database all'`<html>` (🔗 ADR-034 — DS §8.3).
 *
 * Due metà che si somigliano solo di facciata: l'autenticato riceve l'attributo
 * **dal server**, l'ospite se lo scrive da solo leggendo `localStorage`. Il
 * caso che questi test esistono soprattutto per tenere in piedi è il **terzo**,
 * quello che si dimentica: `sistema` non è «chiaro», è **nessun attributo** —
 * e senza attributo decide il sistema operativo. Chi lo trasformasse in
 * `data-theme="light"` non romperebbe nessuna pagina: spegnerebbe in silenzio
 * la preferenza di accessibilità che l'utente ha già dato al proprio
 * dispositivo.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Il tag `<html …>` del documento, aperture escluse.
 *
 * ⚠️ Si asserisce **sul tag** e non sulla pagina intera, per la disciplina già
 * scritta in `tests/Pest.php`: `data-theme` è una stringa che un domani potrà
 * comparire altrove — in uno script di F1.3, in un commento — e un
 * `not->toContain()` sul documento intero diventerebbe rosso per il motivo
 * sbagliato, oppure verrebbe «aggiustato» finché non dice più niente.
 *
 * Vive in questo file e non in `tests/Pest.php` perché la usa una sola suite:
 * là si sale quando una funzione serve a **due**.
 */
function tagHtmlDelDocumento(string $html): string
{
    preg_match('/<html\b[^>]*>/', $html, $tag);

    // Non `?? ''`: un tag introvabile e un tag vuoto vanno distinti, o
    // l'asserzione negativa sarebbe verde proprio quando la pagina è cambiata
    // tanto da non avere più un `<html>` riconoscibile.
    expect($tag)->not->toBeEmpty();

    return $tag[0];
}

/** Il `<head>` del documento, con la stessa cautela del tag qui sopra. */
function headDelDocumento(string $html): string
{
    preg_match('/<head\b.*?<\/head>/s', $html, $blocco);

    expect($blocco)->not->toBeEmpty();

    return $blocco[0];
}

/**
 * Un utente col tema dato, e il suo HTML di dashboard.
 *
 * `forceFill` e non `create([...])`: la colonna sta **fuori** dall'attributo
 * `Fillable` di `User`, quindi passandola alla factory verrebbe scartata in
 * silenzio e ogni test qui sotto misurerebbe il default — cioè sarebbe verde
 * per il motivo sbagliato. È la stessa cicatrice di `issueErrore()`.
 */
function tagHtmlPerTema(TemaUtente $tema): string
{
    $utente = User::factory()->create();
    $utente->assignRole('Tenant');
    $utente->forceFill(['tema' => $tema])->save();

    expect($utente->fresh()->tema)->toBe($tema);

    return tagHtmlDelDocumento(
        test()->actingAs($utente->fresh())->get(route('dashboard'))->assertOk()->getContent()
    );
}

it('renders data-theme="dark" for a user who chose the dark theme', function () {
    expect(tagHtmlPerTema(TemaUtente::Scuro))->toContain('data-theme="dark"');
});

it('renders data-theme="light" for a user who chose the light theme', function () {
    // ⚠️ Non è un doppione del caso scuro: senza un `light` esplicito il
    // sistema operativo sovrascriverebbe la scelta, perché `app.css` fa
    // decidere alla media query tutto ciò che non è `[data-theme="light"]`.
    expect(tagHtmlPerTema(TemaUtente::Chiaro))->toContain('data-theme="light"');
});

it('renders no data-theme at all for a user who follows the system, because the absence IS the rule', function () {
    // ⚠️ Un solo ago, e la spiegazione qui invece che dentro l'asserzione:
    // `toContain()` è **variadico**, quindi un messaggio passato come secondo
    // argomento diventerebbe un secondo ago mai presente e renderebbe questa
    // negazione vera per sempre. È già costato un guardrail vuoto al progetto.
    //
    // Si cerca `data-theme` e non `data-theme="light"`: qualunque valore, anche
    // una stringa vuota, inciamperebbe nel `:not([data-theme="light"])` di
    // `app.css` e spegnerebbe la media query. (`data-tema-utente`, che il tag
    // porta sempre, non contiene questa sottostringa: «tema» non è «theme».)
    expect(tagHtmlPerTema(TemaUtente::Sistema))->not->toContain('data-theme');
});

it('always exposes the stored preference, so the client can tell "follow the system" from "the server said nothing"', function (TemaUtente $tema) {
    // Serve a F1.3: `localStorage` è una cache, e una cache si riallinea solo
    // se si sa cosa dice la verità. Con `sistema` reso come *assenza* di
    // `data-theme`, senza questo secondo attributo il client non potrebbe
    // distinguere «l'utente segue l'OS» da «questa pagina non dice niente del
    // tema», e riscriverebbe l'una per l'altra.
    expect(tagHtmlPerTema($tema))->toContain('data-tema-utente="'.$tema->value.'"');
})->with([
    'sistema' => TemaUtente::Sistema,
    'chiaro' => TemaUtente::Chiaro,
    'scuro' => TemaUtente::Scuro,
]);

it('defaults a brand new user to the system theme', function () {
    // ⚠️ Si rilegge da database (`fresh()`) di proposito: il default vive nello
    // **schema**, e `create()` non rilegge i default della colonna. Un test
    // sull'istanza in memoria misurerebbe la factory, non la migration.
    $utente = User::factory()->create();

    expect($utente->fresh()->tema)->toBe(TemaUtente::Sistema);
});

it('refuses a value outside the three, through the cast, before it reaches the database', function () {
    $utente = User::factory()->create();

    // Il cast copre le scritture che passano da Eloquent — comprese quelle con
    // `forceFill`, che è la via con cui le preferenze si salvano.
    expect(fn () => $utente->forceFill(['tema' => 'viola'])->save())
        ->toThrow(ValueError::class);

    expect($utente->fresh()->tema)->toBe(TemaUtente::Sistema);
});

it('refuses a value outside the three at the schema level too, where an import or a fix-up migration writes', function () {
    // 🔴 È il caso per cui il CHECK esiste: il query builder non incontra né
    // `$fillable` né i cast, quindi senza vincolo di schema un `viola` finirebbe
    // in colonna — e a esplodere sarebbe la **lettura**, cioè il cast dentro
    // l'`<html>` di ogni pagina di quell'utente, login compreso.
    $utente = User::factory()->create();

    // Savepoint: su Postgres l'errore abortirebbe la transazione del test, e la
    // lettura qui sotto esploderebbe con 25P02.
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $utente->id)->update(['tema' => 'viola'])))
        ->toThrow(QueryException::class);

    expect($utente->fresh()->tema)->toBe(TemaUtente::Sistema);
});

it('keeps the preference out of mass assignment, like tenant_id and riceve_email_scadenze', function () {
    $utente = User::factory()->create();

    $utente->fill(['tema' => TemaUtente::Scuro->value])->save();

    expect($utente->fresh()->tema)->toBe(TemaUtente::Sistema);
});

it('carries an inline theme script in the head of a guest page, above the stylesheet', function () {
    $head = headDelDocumento($this->get('/login')->assertOk()->getContent());

    expect($head)->toContain('localStorage.getItem');

    // ⛔ **La posizione è la funzione.** Lo script deve girare prima che il
    // browser abbia un foglio di stile da applicare, o il tema arriva dopo il
    // primo layout — cioè col lampo bianco che esiste per evitare. Si misura
    // contro **tutto** ciò che Vite emette (`<link>` di preload e stylesheet,
    // `<script src>` del bundle o del dev server), perché quale dei due sia il
    // primo dipende dalla modalità di build, e la regola non deve dipenderne.
    $posizioneScript = strpos($head, 'localStorage.getItem');
    $posizioniVite = array_filter([strpos($head, '<link'), strpos($head, 'src=')], fn ($p) => $p !== false);

    // Se Vite non ha emesso niente il confronto sarebbe vacuo, e questo test
    // resterebbe verde anche con lo script spostato in fondo al documento.
    expect($posizioniVite)->not->toBeEmpty();
    expect($posizioneScript)->toBeLessThan(min($posizioniVite));
});

it('renders the guest theme script inline and blocking, never deferred or external', function () {
    $head = headDelDocumento($this->get('/login')->assertOk()->getContent());

    // Il tag che contiene la lettura di `localStorage`, isolato: `defer`,
    // `async` o un `src` su **questo** script riporterebbero il lampo, e
    // nessuno dei tre romperebbe altro. Gli script di Vite, che sono `type
    // ="module"` e quindi differiti per definizione, non c'entrano.
    preg_match('/<script\b[^>]*>(?:(?!<\/script>).)*localStorage\.getItem.*?<\/script>/s', $head, $tag);
    expect($tag)->not->toBeEmpty();

    expect($tag[0])->not->toContain('defer');
    expect($tag[0])->not->toContain('async');
    expect($tag[0])->not->toContain('src=');

    // ⚠️ La chiave si legge dalla costante e non si riscrive a mano: è il solo
    // punto in cui il lato server e il lato browser si danno appuntamento, e
    // due copie libere di divergere darebbero una preferenza che si salva e non
    // si rilegge — senza errori, con l'aria di non essere mai stata salvata.
    expect($tag[0])->toContain(TemaUtente::CHIAVE_LOCALSTORAGE);

    // Il documento dell'ospite non porta `data-theme` dal server: nessuno è
    // autenticato, quindi il server non ha una preferenza da rendere e
    // scriverne una sarebbe una scelta inventata.
    expect(tagHtmlDelDocumento($this->get('/login')->getContent()))->not->toContain('data-theme');
});

it('survives a browser where localStorage throws, because a private window must still show the login', function () {
    $head = headDelDocumento($this->get('/login')->assertOk()->getContent());

    // In navigazione privata `localStorage` **lancia** al primo accesso. In uno
    // script bloccante un'eccezione fermerebbe il parsing di tutto ciò che
    // segue: la pagina di accesso resterebbe senza stili e senza JavaScript per
    // una preferenza estetica.
    expect($head)->toContain('try {');
    expect($head)->toContain('} catch (e) {');
});
