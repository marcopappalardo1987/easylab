<?php

namespace App\Support\Documenti;

use Illuminate\Support\Str;

/**
 * Il nome con cui un documento caricato viene salvato e riproposto al download
 * (🔗 ERD §8.1 `documenti.nome` · ADR-009/026 · S7, T1c).
 *
 * Il nome originale è input del client, e arriva in due posti che non lo
 * perdonano:
 *  - l'header `Content-Disposition` del download: un CR/LF lì dentro fa
 *    rifiutare l'header a PHP, e il documento non si scarica più (500);
 *  - la colonna `nome`, `varchar(255)`: su Postgres un nome più lungo è un
 *    errore all'INSERT, mentre SQLite lo accetta — verde in locale, rotto in CI.
 *
 * I caratteri di formato Unicode (`\p{Cf}`) vanno via insieme ai controlli:
 * l'override destra-sinistra (U+202E) fa leggere `fattura‮fdp.exe` come
 * `fatturaexe.pdf` nell'elenco dei documenti.
 *
 * Il nome NON decide dove finisce il file: il percorso sul disco è l'hash di
 * `store()`. Qui si protegge solo ciò che l'utente legge e ciò che l'header
 * trasporta.
 */
final class NomeFileSicuro
{
    public const MASSIMO = 200;

    public const RIPIEGO = 'documento';

    public static function da(string $originale): string
    {
        $nome = mb_scrub($originale, 'UTF-8');
        $nome = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $nome);
        $nome = str_replace(['/', '\\', '"'], '_', $nome);
        $nome = (string) preg_replace('/\s+/u', ' ', $nome);
        $nome = trim($nome, " .\u{00A0}");

        if ($nome === '') {
            return self::RIPIEGO;
        }

        if (mb_strlen($nome) <= self::MASSIMO) {
            return $nome;
        }

        // Si accorcia la base e non l'estensione: `.pdf` è ciò che il sistema
        // operativo di chi scarica usa per aprire il file.
        $estensione = pathinfo($nome, PATHINFO_EXTENSION);
        $coda = $estensione !== '' && mb_strlen($estensione) <= 10 ? '.'.$estensione : '';

        return rtrim(Str::substr($nome, 0, self::MASSIMO - mb_strlen($coda)), ' .').$coda;
    }

    /**
     * Il nome con l'estensione che i BYTE hanno dimostrato (T1cA-2).
     *
     * `mimes:` valida il contenuto, non il nome: un poliglotta PDF+HTA chiamato
     * `manuale.hta` passa la validazione come PDF e, senza questo passo, si
     * scaricherebbe col suo `.hta` — che Windows esegue con un doppio clic.
     * L'estensione dichiarata resta solo se è un sinonimo di quella vera
     * (`.jpeg` per un JPEG); altrimenti si sostituisce, o si aggiunge.
     */
    public static function conEstensione(string $nome, string $estensione): string
    {
        $estensione = strtolower($estensione);
        $dichiarata = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        $sinonimi = in_array($estensione, ['jpg', 'jpeg'], true) ? ['jpg', 'jpeg'] : [$estensione];

        if (in_array($dichiarata, $sinonimi, true)) {
            return $nome;
        }

        $base = $dichiarata === '' ? $nome : mb_substr($nome, 0, -(mb_strlen($dichiarata) + 1));
        $base = rtrim($base, ' .');

        return ($base === '' ? self::RIPIEGO : $base).'.'.$estensione;
    }
}
