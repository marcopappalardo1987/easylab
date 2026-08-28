<?php

use App\Support\Piattaforma\CsvSicuro;

/**
 * 🔴 La tavola dei caratteri della **CSV injection**.
 *
 * `CsvSicuro::cella()` è pubblica proprio per questo: la difesa è una riga sola,
 * e una guardia raggiungibile solo di rimbalzo — attraverso un file intero e un
 * componente Livewire — è una guardia che nessuno prova sui casi limite. Qui la
 * si colpisce da sola, un carattere per volta.
 *
 * ⚠️ **Vive in `tests/Feature/Piattaforma/` e non in `tests/Unit/`** benché non
 * tocchi il database: è il confine di file assegnato a questo blocco (un altro
 * agente lavora nello stesso albero). La collocazione naturale sarebbe
 * `tests/Unit/Esportazioni/CsvSicuroTest.php`, ed è dichiarata al revisore.
 */

// --- Negativi: ciò che NON deve arrivare intatto al foglio di calcolo ---

it('neutralises every character Excel would read as the start of a formula', function (string $innesco) {
    // Un ago per volta e un dataset invece di una catena: `toContain()` è
    // variadico, quindi due aghi nella stessa chiamata si leggono come «uno
    // qualunque dei due» e nascondono il carattere che passa.
    expect(CsvSicuro::cella($innesco.'qualcosa'))->toBe("'".$innesco.'qualcosa');
})->with([
    'uguale' => '=',
    'più' => '+',
    'meno' => '-',
    'chiocciola' => '@',
    // TAB e CR ci sono perché Excel li **salta** prima di leggere il primo
    // carattere significativo: senza, "\t=cmd|…" passerebbe la guardia e la
    // formula verrebbe eseguita lo stesso.
    'tabulazione' => "\t",
    'ritorno a capo' => "\r",
]);

it('does not touch a legitimate value', function (string $legittimo) {
    // L'altra metà dell'invariante: una difesa che prefissasse tutto sarebbe
    // «sicura» e inutilizzabile — ogni P.IVA arriverebbe in Excel come testo
    // con un apostrofo davanti, e il file smetterebbe di essere ri-importabile.
    expect(CsvSicuro::cella($legittimo))->toBe($legittimo);
})->with([
    'ragione sociale' => 'Gruppo Rossi',
    'partita iva' => '01234567890',
    'numero' => '12',
    'data' => '01/02/2026',
    'sì/no' => 'sì',
    // ⚠️ Il carattere pericoloso **non in testa** resta dov'è: la formula la fa
    // il primo carattere, e neutralizzare anche il resto corromperebbe le
    // ragioni sociali che contengono un trattino.
    'trattino interno' => 'Rossi - Bianchi',
]);

it('escapes the formula even when the value is a number the sheet would compute', function () {
    // `-5` è un numero legittimo per una persona e una formula per Excel. La
    // difesa è **uniforme su ogni cella**, anche su quelle che generiamo noi:
    // la regola «solo le colonne di testo» richiede un giudizio colonna per
    // colonna, ed è la forma che si sbaglia al primo campo aggiunto.
    expect(CsvSicuro::cella('-5'))->toBe("'-5");
});

// --- Positivi: la grammatica del file ---

it('opens the file with the UTF-8 BOM', function () {
    // Senza BOM l'Excel italiano apre «Società» corrotta, e questo file contiene
    // ragioni sociali accentate.
    expect(CsvSicuro::stringa([['ragione_sociale']]))->toStartWith("\xEF\xBB\xBF");
});

it('separates the columns with a semicolon', function () {
    // Stessa scelta di `ImportStrumenti::scaricaTemplate()`: con la virgola
    // l'Excel italiano mette tutte le colonne in una.
    expect(CsvSicuro::stringa([['ragione_sociale', 'partita_iva']]))
        ->toContain("ragione_sociale;partita_iva\n");
});

it('quotes a cell that carries the delimiter, a quote or a newline', function () {
    $csv = CsvSicuro::stringa([['Rossi; Bianchi', 'a"b', "prima\nseconda"]]);

    expect($csv)->toContain('"Rossi; Bianchi"')
        // La virgoletta interna si raddoppia (RFC-4180), non si fa precedere da
        // un backslash: `fputcsv()` è chiamato con escape **vuoto** apposta.
        ->and($csv)->toContain('"a""b"')
        ->and($csv)->toContain("\"prima\nseconda\"");
});

it('survives a round trip on a cell that ends with a backslash', function () {
    // ⛔ È la ragione dell'escape **vuoto** invece di `"\\"`. In scrittura i due
    // producono la stessa riga — `"Rossi; Bianchi\";dopo` — quindi un'asserzione
    // sul testo prodotto non distinguerebbe nulla e sarebbe un test finto. La
    // differenza è in **lettura**: con l'escape a backslash la virgoletta di
    // chiusura si legge come scappata, e la cella si mangia il resto della riga.
    // Si prova quindi il giro completo, con la stessa convenzione con cui
    // scriviamo — che è ciò che fa chiunque riapra il file.
    $riga = trim(str_replace(CsvSicuro::BOM, '', CsvSicuro::stringa([['Rossi; Bianchi\\', 'dopo']])));

    expect(str_getcsv($riga, CsvSicuro::DELIMITATORE, '"', ''))->toBe(['Rossi; Bianchi\\', 'dopo']);
});

it('does not emit a deprecation on PHP 8.4', function () {
    // ⛔ `fputcsv($out, $riga, ';')` emette `Deprecated: the $escape parameter
    // must be provided`. In produzione finirebbe **dentro il file**, prima del
    // BOM, e Excel aprirebbe una colonna sola con dentro il messaggio di PHP.
    // `ImportStrumenti::scaricaTemplate()` ha ancora la forma a tre argomenti:
    // non è il modello da copiare.
    $errori = [];
    set_error_handler(function (int $livello, string $messaggio) use (&$errori) {
        $errori[] = $messaggio;

        return true;
    }, E_DEPRECATED);

    try {
        $csv = CsvSicuro::stringa([['ragione_sociale'], ['Gruppo Rossi']]);
    } finally {
        restore_error_handler();
    }

    expect($errori)->toBe([])
        ->and($csv)->toStartWith("\xEF\xBB\xBF");
});

it('writes the header first and then the rows, in order', function () {
    $csv = CsvSicuro::stringa([['a', 'b'], ['1', '2'], ['3', '4']]);

    expect($csv)->toBe("\xEF\xBB\xBFa;b\n1;2\n3;4\n");
});
