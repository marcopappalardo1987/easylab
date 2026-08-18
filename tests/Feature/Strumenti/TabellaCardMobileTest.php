<?php

use App\Enums\TipoDocumento;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Documento;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Tabella su desktop, card su mobile — con UN SOLO markup (S4 blocco 10,
 * Wireframe §1 «Mobile: tabella → lista di card»).
 *
 * La strada ovvia sarebbe stata una `<table class="hidden md:table">` più un
 * blocco di card `md:hidden`, e avrebbe raddoppiato il markup di quattro tab:
 * ogni colonna aggiunta domani andrebbe scritta due volte, e la seconda è quella
 * che si dimentica. Qui la riga resta una sola e su mobile diventa una card per
 * CSS (`tabella-a-card`), con ogni cella che espone la propria intestazione da
 * `data-etichetta`.
 *
 * ⚠️ **Questo test è il prezzo di quella scelta.** L'etichetta è l'unica cosa
 * scritta due volte — nel `<th>` e nell'attributo — quindi le due possono
 * divergere: basta inserire una colonna a metà tabella e non spostare gli
 * attributi perché su un telefono ogni valore compaia sotto il nome di quello
 * accanto. Su desktop non si vedrebbe **nulla**, perché lì gli attributi non
 * sono usati: sarebbe un difetto invisibile a chiunque non apra un telefono.
 * Verificarlo qui trasforma la duplicazione in un invariante controllato.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    // Ogni tab deve avere ALMENO UNA RIGA, o il test passerebbe per assenza di
    // celle da controllare — la forma di falso verde più facile da scrivere.
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)
        ->forRicambio(Ricambio::factory()->forTenant($this->ente)->create())
        ->create();

    Garanzia::factory()->forStrumento($this->strumento)->create();
    Garanzia::factory()->forRicambio($utilizzo)->create();

    Documento::factory()->perStrumento($this->strumento)->create(['tipo' => TipoDocumento::Manuale]);
    Documento::factory()->perIntervento($intervento)->create(['tipo' => TipoDocumento::CertificatoTaratura]);

    $this->admin = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Admin');
});

it('labels every cell with the heading of its own column, in every tab', function () {
    $html = Livewire::actingAs($this->admin)
        ->test(SchedaStrumento::class, ['strumento' => $this->strumento])
        ->html();

    $dom = new DOMDocument;
    // La scheda contiene commenti condizionali di Livewire e HTML non stretto:
    // gli avvisi del parser non sono difetti del markup e vanno silenziati.
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
    $xpath = new DOMXPath($dom);

    $tabelle = $xpath->query("//table[contains(@class, 'tabella-a-card')]");
    // Quattro tab con tabella: interventi, ricambi, documenti, garanzie. Se
    // domani ne nasce una quinta senza l'utility, questo conteggio lo dice.
    expect($tabelle->length)->toBe(4, 'Ogni tabella della scheda deve usare `tabella-a-card`');

    $celleControllate = 0;

    foreach ($tabelle as $indiceTabella => $tabella) {
        $intestazioni = [];
        foreach ($xpath->query('.//thead//th', $tabella) as $th) {
            $intestazioni[] = trim($th->textContent);
        }

        foreach ($xpath->query('.//tbody/tr', $tabella) as $riga) {
            $celle = $xpath->query('./td', $riga);

            // Riga di stato vuoto («Nessuna attività registrata»): una cella
            // sola in `colspan`, che non corrisponde ad alcuna colonna.
            if ($celle->length !== count($intestazioni)) {
                expect($celle->length)->toBe(1, "Tabella #{$indiceTabella}: riga con un numero di celle diverso dalle intestazioni");

                continue;
            }

            foreach ($celle as $i => $cella) {
                $attesa = $intestazioni[$i];

                if ($cella->hasAttribute('data-azioni')) {
                    // La colonna azioni porta l'intestazione come `sr-only`, e su
                    // mobile diventa un blocco a tutta larghezza SENZA etichetta:
                    // ripeterla sopra i bottoni sarebbe rumore.
                    expect($attesa)->toBe('Azioni', "Tabella #{$indiceTabella}: `data-azioni` su una colonna che non è quella delle azioni");
                    $celleControllate++;

                    continue;
                }

                expect($cella->getAttribute('data-etichetta'))->toBe(
                    $attesa,
                    "Tabella #{$indiceTabella}, colonna ".($i + 1).": l'etichetta della cella non è l'intestazione «{$attesa}»"
                );
                $celleControllate++;
            }
        }
    }

    // Senza questo, una scheda che per qualunque motivo non rendesse righe
    // passerebbe il test a vuoto.
    expect($celleControllate)->toBeGreaterThan(20);
});
