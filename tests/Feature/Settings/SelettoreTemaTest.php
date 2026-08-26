<?php

use App\Enums\TemaUtente;
use App\Livewire\Settings\PreferenzeNotifiche;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Il selettore di tema della pagina Preferenze (🔗 ADR-034 — DS §8.3, §5.1).
 *
 * Qui si misura la **sola metà server** del gesto, che è anche l'unica che
 * sopravvive a un ricaricamento: il click scrive `users.tema`, e il render
 * successivo rimette `aria-pressed` su uno solo dei tre bottoni leggendolo
 * dalla colonna. È deliberato che sia questa la metà testabile — `aria-pressed`
 * viene dal server proprio perché lo stato del client non può aggiungere nulla
 * a ciò che il server ha deciso di mostrare (la regola imparata dal combobox).
 *
 * ⚠️ **Cosa questi test NON coprono, e perché.** I test Livewire non eseguono
 * Alpine: tutto ciò che vive in `resources/js/app.js` resta fuori. In
 * particolare NON sono verificati qui:
 *
 *   1. la scrittura di `data-theme` sull'`<html>` al click — cioè il cambio di
 *      colore immediato, che nessun morph di Livewire farebbe (il morph
 *      ridisegna il componente, non l'`<html>`);
 *   2. la rimozione dell'attributo quando si sceglie «sistema», che è il caso
 *      che si dimentica: `sistema` non è `light`, è **nessun attributo**;
 *   3. la scrittura del valore di **dominio** in `localStorage`
 *      (`sistema|chiaro|scuro`, mai `light|dark`);
 *   4. il riallineamento di `localStorage` al valore del database all'avvio
 *      (`riallinea()`), quando i due si sono separati su un altro dispositivo;
 *   5. l'anticipo di `aria-pressed` prima che il server risponda, e il suo
 *      ritorno indietro se il server rifiutasse il valore;
 *   6. i 44×44px resi davvero a schermo, e il fuoco da tastiera sui tre bottoni;
 *   7. la sopravvivenza al morph di Livewire (il sintomo, se si rompe, è
 *      l'evidenziazione che torna sul tema di prima dopo il click);
 *   8. il contrasto dei tre bottoni **nei due temi**: nessun test qui guarda un
 *      colore, e `SuperficiTokenizzateGuardrailTest` guarda i nomi delle
 *      classi, non i valori.
 *
 * I punti sopra si verificano **a mano**, e sono elencati per nome invece che
 * riassunti in «il resto è manuale»: un debito con un elenco si paga, uno senza
 * si dimentica. La copertura vera sarebbe un browser test — è dichiarata come
 * mancante, non come non necessaria.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->utente = User::factory()->create(['tenant_id' => $ente->id]);
    $this->utente->assignRole('Tenant');
});

/**
 * I valori di `data-tema` dei bottoni con `aria-pressed="true"`.
 *
 * ⚠️ Restituisce una **lista**, non il primo trovato, e serve a una cosa sola:
 * «due bottoni premuti» dev'essere un esito distinguibile da «uno solo», o
 * l'asserzione più importante di questo file diventerebbe muta. Un selettore
 * con tre stati accesi non è rotto in modo visibile — è rotto per chi ascolta.
 *
 * @return list<string>
 */
function temiPremuti(string $html): array
{
    // ⚠️ Si guardano i soli bottoni che portano `data-tema`, e non tutti i
    // `<button>` della pagina: nelle Preferenze ce ne sono altri due —
    // l'interruttore del digest e il suo «Salva» — e contarli renderebbe
    // questo aiutante rosso ogni volta che quella sezione cambia forma, cioè
    // per il motivo sbagliato.
    preg_match_all('/<button\b[^>]*\bdata-tema="[^"]*"[^>]*>/', $html, $bottoni);

    // I tre stati sono tre: se un giorno ne restassero due, «uno solo premuto»
    // sarebbe ancora verde — e l'interruttore avrebbe perso una direzione
    // senza che nessuna asserzione se ne accorgesse.
    expect($bottoni[0])->toHaveCount(3);

    $premuti = [];

    foreach ($bottoni[0] as $tag) {
        if (! str_contains($tag, 'aria-pressed="true"')) {
            continue;
        }

        preg_match('/data-tema="([^"]+)"/', $tag, $tema);
        $premuti[] = $tema[1] ?? '(bottone senza data-tema)';
    }

    return $premuti;
}

/** Il componente montato dall'utente della fixture, autenticato. */
function preferenze(User $utente): Testable
{
    test()->actingAs($utente);

    return Livewire::test(PreferenzeNotifiche::class);
}

it('writes the chosen theme to the column', function () {
    preferenze($this->utente)->call('scegliTema', 'scuro');

    expect($this->utente->fresh()->tema)->toBe(TemaUtente::Scuro);
});

it('keeps the choice across a fresh page load', function () {
    // La metà che conta davvero: il click non lascia traccia solo nel DOM
    // corrente. Un montaggio nuovo — cioè un ricaricamento, o un altro
    // dispositivo — rilegge la colonna e rimette il bottone giusto in evidenza.
    preferenze($this->utente)->call('scegliTema', 'scuro');

    expect(temiPremuti(preferenze($this->utente->fresh())->html()))->toBe(['scuro']);
});

it('renders data-theme="dark" on the next request, without any JavaScript', function () {
    // Il giro completo: la colonna scritta da qui è la stessa che il layout
    // legge nel `<html>` (F1.2). Se le due metà si separassero, il tema
    // «salverebbe» senza applicarsi mai — e il sintomo sarebbe una pagina del
    // colore di prima, che nessuna asserzione sul componente vede.
    preferenze($this->utente)->call('scegliTema', 'scuro');

    $html = $this->actingAs($this->utente->fresh())->get(route('settings.notifiche'))->assertOk()->getContent();

    preg_match('/<html\b[^>]*>/', $html, $tag);

    expect($tag)->not->toBeEmpty()
        ->and($tag[0])->toContain('data-theme="dark"');
});

it('lets the user go back to following the operating system', function () {
    // ⚠️ Il caso che si dimentica, ed è la ragione per cui gli stati sono tre:
    // senza `sistema` l'interruttore avrebbe una sola direzione. E `sistema`
    // non è `chiaro`: sull'`<html>` diventa **nessun attributo**.
    $this->utente->forceFill(['tema' => TemaUtente::Scuro])->save();

    preferenze($this->utente->fresh())->call('scegliTema', 'sistema');

    expect($this->utente->fresh()->tema)->toBe(TemaUtente::Sistema);

    $html = $this->actingAs($this->utente->fresh())->get(route('settings.notifiche'))->assertOk()->getContent();

    preg_match('/<html\b[^>]*>/', $html, $tag);

    expect($tag)->not->toBeEmpty()
        ->and($tag[0])->not->toContain('data-theme');
});

it('refuses a theme that is not one of the three, and leaves the column alone', function () {
    // Un componente Livewire è un endpoint: il parametro arriva dal client e
    // non dai tre bottoni resi dal server. `tryFrom` lo ferma prima della
    // colonna — `from()` sarebbe un 500, un cast cieco arriverebbe al CHECK.
    preferenze($this->utente)
        ->call('scegliTema', 'mezzanotte')
        ->assertHasErrors('tema');

    expect($this->utente->fresh()->tema)->toBe(TemaUtente::Sistema);
});

it('marks exactly one of the three buttons as pressed', function (TemaUtente $tema) {
    // 🔴 L'asserzione che tiene in piedi l'accessibilità del gruppo: `aria-pressed`
    // è ciò che dice a uno screen reader quale tema è attivo, e tre bottoni
    // premuti — o zero — non si vedono guardando lo schermo.
    $this->utente->forceFill(['tema' => $tema])->save();

    expect(temiPremuti(preferenze($this->utente->fresh())->html()))->toBe([$tema->value]);
})->with([
    'chiaro' => TemaUtente::Chiaro,
    'scuro' => TemaUtente::Scuro,
    'sistema' => TemaUtente::Sistema,
]);

it('gives the group a role and a name, and every button a word beside its glyph', function () {
    // ⛔ Forma + parola, non la sola forma (DS §4): una luna e un monitor sono
    // due segni che si imparano, non che si sanno. La parola sta in `sr-only`,
    // quindi il nome accessibile del bottone non è mai vuoto — e l'icona porta
    // `aria-hidden`, o lo screen reader leggerebbe due volte lo stesso stato.
    $html = preferenze($this->utente)->html();

    expect($html)->toContain('role="group"')
        ->and($html)->toContain('aria-label="Tema"')
        ->and($html)->toContain('Tema chiaro')
        ->and($html)->toContain('Tema scuro')
        ->and($html)->toContain('Tema di sistema');
});

it('never lets one user change another user\'s theme', function () {
    // ⛔ L'identità viene da `auth()`, mai da un parametro: `scegliTema` non ha
    // una firma in cui si possa **nominare** un altro utente. Non è un
    // controllo che si può dimenticare, è una richiesta inesprimibile.
    $altro = User::factory()->create(['tenant_id' => $this->utente->tenant_id]);
    $altro->assignRole('Tenant');

    preferenze($this->utente)->call('scegliTema', 'scuro');

    expect($this->utente->fresh()->tema)->toBe(TemaUtente::Scuro)
        ->and($altro->fresh()->tema)->toBe(TemaUtente::Sistema);

    // E la firma, perché l'assenza di un parametro è la garanzia: il giorno in
    // cui qualcuno aggiungesse un `$utente` per comodità, il test sopra
    // resterebbe verde — chiamerebbe ancora il metodo con un argomento solo.
    expect((new ReflectionMethod(PreferenzeNotifiche::class, 'scegliTema'))->getNumberOfParameters())->toBe(1);
});

it('never lets the theme be forged by mass assignment', function () {
    // La colonna sta fuori dall'attributo Fillable, come `tenant_id` e
    // `riceve_email_scadenze`: senza quell'esclusione un form dell'anagrafica
    // potrebbe cambiare il tema di un'altra persona per sbaglio.
    // ⚠️ `fresh()` e non il modello della fixture: quello è stato costruito in
    // memoria e non ha ancora riletto il default dello schema — porta `null`,
    // e l'asserzione sarebbe verde per il motivo sbagliato (`null` non è
    // `scuro`, ma non è nemmeno la prova che il fill sia stato scartato).
    $utente = $this->utente->fresh();

    expect($utente->tema)->toBe(TemaUtente::Sistema);

    $utente->fill(['tema' => 'scuro']);

    expect($utente->tema)->toBe(TemaUtente::Sistema);
});
