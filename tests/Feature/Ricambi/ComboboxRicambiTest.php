<?php

use App\Models\Ricambio;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * L'autocomplete del catalogo nel form intervento (ADR-008/022, DS §5.6).
 *
 * ⚠️ **Cosa questi test NON coprono, e perché.** I test Livewire non eseguono
 * Alpine: tutto ciò che vive in `resources/js/app.js` resta fuori. In
 * particolare NON sono verificati qui:
 *
 *   1. la navigazione da tastiera (↑ ↓ Invio Esc);
 *   2. la chiusura del dropdown al click fuori;
 *   3. l'aggiornamento di `aria-expanded` e `aria-activedescendant`;
 *   4. i target da 44px effettivamente resi a schermo;
 *   5. la sopravvivenza dello stato Alpine al patch DOM di Livewire mentre si
 *      digita (il sintomo, se si rompe, è il dropdown che si richiude a ogni tasto);
 *   6. l'annuncio dello screen reader sul messaggio «verrà creato»/«collegato».
 *
 * Il componente è progettato perché ciò che conta resti testabile: **Alpine non
 * scrive mai il valore**, la selezione passa sempre dal `wire:click` reso dal
 * server, e Invio non fa altro che cliccare quel bottone. Quindi il test di
 * `scegliRicambio` esercita lo stesso percorso della tastiera.
 *
 * I sei punti sopra si verificano **a mano** (checklist in roadmap). La
 * copertura reale sarebbe un browser test: è un debito dichiarato, non un
 * silenzio.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($dept)->create();

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');

    foreach (['Guarnizione O-Ring', 'Guarnizione portello', 'Filtro HEPA'] as $nome) {
        Ricambio::factory()->forTenant($this->ente)->create(['nome' => $nome]);
    }

    $this->digita = fn (string $testo) => scheda($this->admin, $this->strumento)
        ->call('openNuovoIntervento')
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')
        ->set('ricambiNuovi.0.nome', $testo);
});

it('suggests catalogue entries whose normalised name starts with what is typed', function () {
    ($this->digita)('Guarn')
        ->assertSet('suggerimenti', [['nome' => 'Guarnizione O-Ring'], ['nome' => 'Guarnizione portello']]);
});

it('matches by prefix and not by substring', function () {
    // `%...%` non userebbe l'indice unique parziale: la ricerca è per prefisso,
    // e va congelato perché la differenza è invisibile su un catalogo piccolo.
    ($this->digita)('portello')->assertSet('suggerimenti', []);
});

it('normalises what the operator types before searching', function () {
    ($this->digita)('  GUARNIZIONE   o-ring ')
        ->assertSet('suggerimenti', [['nome' => 'Guarnizione O-Ring']]);
});

it('stays silent below two characters and on whitespace-only input', function () {
    // "Sto ancora digitando" non è un errore: senza il guard, un input di soli
    // spazi finirebbe in normalizzaNome(), che lancia.
    ($this->digita)('G')->assertSet('suggerimenti', []);
    ($this->digita)("  \u{00A0} ")->assertSet('suggerimenti', []);
});

it('never suggests another tenant catalogue', function () {
    // Area rossa: il nome di un pezzo di un altro Ente non deve comparire mai,
    // nemmeno come suggerimento.
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    Ricambio::factory()->forTenant($altroEnte)->create(['nome' => 'Guarnizione riservata']);

    ($this->digita)('Guarnizione riserv')->assertSet('suggerimenti', []);
});

it('excludes soft-deleted catalogue entries', function () {
    Ricambio::where('nome', 'Filtro HEPA')->firstOrFail()->delete();

    ($this->digita)('Filtro')->assertSet('suggerimenti', []);
});

it('writes the chosen name into the active row', function () {
    // È il percorso che anche Invio esegue: Alpine clicca questo bottone.
    ($this->digita)('Guarn')
        ->call('scegliRicambio', 0, 'Guarnizione portello')
        ->assertSet('ricambiNuovi.0.nome', 'Guarnizione portello')
        ->assertSet('suggerimenti', []);
});

it('renders the combobox aria contract', function () {
    // Contratto statico del markup: i primi aria-* del progetto. Non verifica
    // che si aggiornino (serve Alpine), solo che ci siano.
    ($this->digita)('Guarn')
        ->assertSeeHtml('role="combobox"')
        ->assertSeeHtml('role="listbox"')
        ->assertSeeHtml('aria-controls="ricambiNuovi.0.nome-lista"');
});

it('renders the matching options, so the dropdown has something to show', function () {
    // ⚠️ Rientro dall'uso reale (9 Ago 2026): i suggerimenti c'erano ma il
    // dropdown non compariva mai. La visibilità dipendeva da uno stato Alpine
    // `aperto`, e a ogni render di Livewire dopo il debounce il nodo veniva
    // rimpiazzato e `x-data` si reinizializzava → il flag tornava false. Ora la
    // lista esiste **se e solo se** il server ha dei suggerimenti, e Alpine può
    // solo nasconderla (Esc, click fuori).
    //
    // Questo test copre ciò che è verificabile senza browser: che le opzioni
    // siano davvero nel markup, cliccabili, con il `wire:click` giusto. Che poi
    // siano VISIBILI resta CSS, e sta nella checklist manuale.
    ($this->digita)('Guarn')
        ->assertSee('Guarnizione O-Ring')
        ->assertSee('Guarnizione portello')
        ->assertSeeHtml('scegliRicambio(0');
});

it('renders no list at all when nothing matches', function () {
    ($this->digita)('Pezzo mai visto')->assertDontSeeHtml('role="listbox"');
});

it('says only what the list cannot say: that the part will be created', function () {
    // Il messaggio «Collegato a una voce già a catalogo» è stato TOLTO: col
    // dropdown visibile ripeteva ciò che le opzioni mostrano già, e finché il
    // dropdown non si vedeva era pure l'unico segno di vita — quindi
    // fuorviante. Resta il caso che la lista non può comunicare.
    ($this->digita)('Pezzo mai visto')->assertSee('Nuovo ricambio: verrà creato a catalogo');
    ($this->digita)('Guarn')->assertDontSee('Nuovo ricambio: verrà creato a catalogo');
});
