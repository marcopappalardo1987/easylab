<?php

namespace App\Rules;

use App\Models\Ricambio;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * "Nome ricambio valido" ha già UNA definizione: Ricambio::ripulisciNome().
 * Questa regola la chiama invece di riscriverla con un `regex:/\S/`, che non
 * intercetterebbe lo spazio unificatore U+00A0 — esattamente il caso per cui
 * `ripulisciNome()` usa `[\p{Z}\s]+` e non `\s+`. Due definizioni di "vuoto"
 * sotto lo stesso campo divergerebbero al primo copia-incolla da PDF.
 *
 * Serve perché `required` PASSA su una stringa di soli spazi (non è vuota, e le
 * property Livewire non attraversano TrimStrings): senza questa regola il nome
 * arriverebbe al model, che lancia InvalidArgumentException — cioè un 500 al
 * posto di un messaggio di campo.
 */
final class NomeRicambio implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            Ricambio::ripulisciNome((string) $value);
        } catch (InvalidArgumentException) {
            $fail('Il nome del ricambio non può essere vuoto.');
        }
    }
}
