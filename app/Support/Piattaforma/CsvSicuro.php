<?php

namespace App\Support\Piattaforma;

/**
 * 🔴 Un CSV che Excel apre come **dati**, non come programma.
 *
 * Il difetto che questa classe esiste per chiudere si chiama *CSV injection*, e
 * non è un difetto del CSV: è un difetto del foglio di calcolo che lo apre. Una
 * cella che comincia con `=`, `+`, `-`, `@` — e anche con un TAB o un CR, che
 * Excel salta prima di decidere — viene **eseguita come formula** all'apertura.
 * Con `=cmd|' /C calc'!A0` la formula lancia un processo. La ragione sociale di
 * un cliente è testo che noi non scriviamo: chiunque possa farsi provisionare un
 * account sceglie il contenuto di quella cella, e questo file esce
 * dall'applicazione e finisce sul portatile di chi amministra.
 *
 * ⛔ **Il rimedio è il prefisso `'`, NON la quotatura.** Excel esegue la formula
 * anche quando la cella è fra virgolette: le virgolette sono grammatica del CSV
 * e spariscono in lettura, mentre l'apostrofo iniziale entra nel valore e dice
 * al foglio «questo è testo». Costa un carattere visibile nella cella, ed è il
 * prezzo giusto: una cella che *sembra* strana è meglio di una cella che *fa*
 * qualcosa.
 *
 * ⚠️ **La difesa è uniforme su OGNI cella**, anche su quelle numeriche e sulle
 * date. Non è pigrizia: la regola «solo le colonne di testo» richiede un
 * giudizio colonna per colonna, ed è la forma che si sbaglia al primo campo
 * aggiunto — cioè fra sei mesi, da chi non ha letto questo docblock. I valori
 * che generiamo noi (interi, `sì`/`no`, `d/m/Y`) non cominciano mai con quei
 * caratteri, quindi l'uniformità non produce falsi positivi.
 *
 * ⛔ **`fputcsv()` vuole tutti e cinque gli argomenti, con escape VUOTO.** Su
 * PHP 8.4 la forma a tre argomenti emette `Deprecated: the $escape parameter
 * must be provided` (verificato eseguendo). E l'escape dev'essere `''` e non
 * `'\\'`: l'escape a backslash non è RFC-4180 e corrompe le celle che finiscono
 * con un backslash. *`ImportStrumenti::scaricaTemplate()` ha ancora la forma a
 * tre argomenti: non è il modello da copiare.*
 *
 * **Il file si costruisce in memoria** e non si scrive su uno stream vero,
 * perché il suo unico consumatore è un'azione Livewire: là la risposta viene
 * comunque base64-encodata dentro il payload JSON dell'update (vedi
 * `SupportFileDownloads`), quindi lo «streaming» è nominale e fingere il
 * contrario nasconderebbe il tetto invece di dichiararlo.
 *
 * Il **BOM** e il delimitatore `;` sono la coppia che l'Excel italiano si
 * aspetta: senza BOM le ragioni sociali accentate arrivano corrotte, e con la
 * virgola le colonne finiscono tutte in una. L'import del progetto rimuove il
 * BOM in lettura, quindi il giro di andata e ritorno resta possibile.
 */
final class CsvSicuro
{
    /** Il separatore che l'Excel italiano si aspetta (stessa scelta di `ImportStrumenti`). */
    public const DELIMITATORE = ';';

    /** Il BOM UTF-8: senza, «Società» arriva corrotta in Excel. */
    public const BOM = "\xEF\xBB\xBF";

    /**
     * I caratteri che, in testa a una cella, fanno di quella cella una formula.
     *
     * TAB (0x09) e CR (0x0D) ci sono perché Excel li **salta** prima di leggere
     * il primo carattere significativo: senza, `"\t=cmd..."` passerebbe.
     */
    private const INNESCHI = "/^[=+\-@\t\r]/";

    /**
     * L'intera matrice come stringa CSV, BOM compreso.
     *
     * @param  list<list<scalar|null>>  $matrice  la prima riga è l'intestazione
     */
    public static function stringa(array $matrice): string
    {
        $out = fopen('php://temp', 'r+');

        fwrite($out, self::BOM);

        foreach ($matrice as $riga) {
            // ⛔ Cinque argomenti, escape vuoto: vedi il docblock di classe.
            fputcsv($out, array_map(self::cella(...), $riga), self::DELIMITATORE, '"', '');
        }

        rewind($out);
        $contenuto = stream_get_contents($out);
        fclose($out);

        return $contenuto;
    }

    /**
     * Una cella resa inerte per il foglio di calcolo.
     *
     * ⚠️ È **pubblica di proposito**: è la riga che porta tutta la difesa, e un
     * unit test deve poterla colpire da sola invece che attraverso un file
     * intero. Una guardia raggiungibile solo di rimbalzo è una guardia che
     * nessuno prova sui casi limite.
     */
    public static function cella(mixed $valore): string
    {
        $testo = (string) $valore;

        return preg_match(self::INNESCHI, $testo) === 1 ? "'".$testo : $testo;
    }
}
