<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

use function Livewire\before;

/**
 * Toglie il byte NUL da ogni stringa in ingresso (S7, T1c · difetto T1cB-4 ·
 * ERD: tutte le colonne di testo · ADR-018 per il modulo pubblico di S6).
 *
 * Postgres rifiuta `\0` in `text`/`varchar` («invalid byte sequence»): un NUL
 * in un campo qualunque è un 500 all'INSERT, e SQLite lo salva senza fiatare,
 * quindi la suite non lo vede. Nessun testo legittimo contiene un NUL: lo si
 * toglie invece di rifiutare la richiesta, come `TrimStrings` fa con gli spazi.
 *
 * Due ingressi, perché sono due strade diverse:
 *  - la richiesta HTTP (middleware globale): copre form, query string e anche il
 *    corpo JSON di `/livewire/update`, che questo middleware — a differenza di
 *    `TrimStrings`, che Livewire esclude — attraversa;
 *  - l'aggiornamento di una proprietà Livewire (`ancheSuLivewire()`), che non
 *    passa da nessun middleware quando il componente è montato da un test o da
 *    un altro punto del codice. Rete in più, non unica difesa.
 */
class RimuoviByteNul extends TransformsRequest
{
    protected function transform($key, $value)
    {
        return self::ripulisci($value);
    }

    public static function ripulisci(mixed $valore): mixed
    {
        if (is_string($valore)) {
            return str_contains($valore, "\0") ? str_replace("\0", '', $valore) : $valore;
        }

        if (is_array($valore)) {
            return array_map(self::ripulisci(...), $valore);
        }

        return $valore;
    }

    private static function contieneNul(mixed $valore): bool
    {
        return match (true) {
            is_string($valore) => str_contains($valore, "\0"),
            is_array($valore) => array_any($valore, self::contieneNul(...)),
            default => false,
        };
    }

    /**
     * Da `AppServiceProvider::boot()`. Il valore aggiornato si riscrive nel
     * finisher, cioè dopo che Livewire l'ha assegnato; `before` fa girare questo
     * finisher prima degli `updated*()` del componente, che vedono già il valore pulito.
     */
    public static function ancheSuLivewire(): void
    {
        before('update', function ($componente, string $percorso, mixed $valore) {
            if (! self::contieneNul($valore)) {
                return null;
            }

            return function () use ($componente, $percorso, $valore) {
                data_set($componente, $percorso, self::ripulisci($valore));
            };
        });
    }
}
