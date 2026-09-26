<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Niente indirizzi web nei campi liberi del modulo PUBBLICO di registrazione
 * (🔗 ADR-011 per le email brandizzate, ADR-039 per l'ingresso pubblico).
 *
 * Il testo di `nome_ente` e `nome_referente` viene riportato nell'email di
 * verifica, che parte dal dominio di Easy Lab verso un indirizzo scelto da chi
 * compila, senza alcun login. `App\Support\Mail\TestoMarkdown::sicuro()` toglie
 * già il markdown, quindi un `[clicca](https://evil.example)` resta testo e la
 * destinazione non si può più nascondere dietro una parola.
 *
 * ⚠️ Ma il client di posta fa un passo in più che dalla vista non si governa:
 * verificato su Gmail il 26 Set 2026, un URL NUDO nel testo viene reso
 * cliccabile da Gmail stesso. Restava quindi la possibilità di spedire dal
 * nostro dominio un'email con un link verso un sito qualunque. Qui si chiude
 * all'ingresso: un nome di laboratorio o di persona non contiene un indirizzo
 * web, e chi ne ha davvero bisogno lo scrive dopo, da dentro l'applicazione,
 * dove c'è un account che risponde.
 *
 * Solo il modulo pubblico: i campi omonimi dell'area autenticata non passano
 * di qui, perché lì dietro c'è un utente identificato.
 */
final class SenzaIndirizziWeb implements ValidationRule
{
    /**
     * `://` copre http, https e qualunque altro schema; `www.` copre la forma
     * senza schema, che i client rendono cliccabile lo stesso.
     */
    private const INDIZI = ['://', 'www.'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $testo = mb_strtolower((string) $value);

        foreach (self::INDIZI as $indizio) {
            if (str_contains($testo, $indizio)) {
                $fail('Questo campo non può contenere un indirizzo web.');

                return;
            }
        }
    }
}
