<?php

namespace App\Support\Testo;

use Illuminate\Support\Str;

/**
 * Il confronto dei filtri di testo che lavorano su un elenco già caricato
 * (🔗 ADR-060/061): il nome contiene **ogni parola** cercata, in qualunque
 * ordine, senza badare a maiuscole e accenti.
 *
 * Non è una ricerca sul database: lì le regole sono del motore (`like` è
 * case-sensitive su Postgres), e chi cerca su una tabella non passa di qui.
 */
final class Ricerca
{
    public static function contiene(string $testo, string $cercato): bool
    {
        $parole = self::parole($cercato);

        return $parole === [] || Str::containsAll(self::piega($testo), $parole);
    }

    /** @return list<string> */
    private static function parole(string $cercato): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim(self::piega($cercato))) ?: [], fn (string $p) => $p !== ''));
    }

    private static function piega(string $testo): string
    {
        return Str::lower(Str::ascii($testo));
    }
}
