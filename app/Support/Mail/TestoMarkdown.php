<?php

namespace App\Support\Mail;

/**
 * Testo scritto da un utente, reso inerte prima di entrare in una vista
 * markdown delle email (🔗 ADR-011 §canali · S7, T1c, difetto D-T1c-1).
 *
 * ## Perché `{{ }}` non basta
 *
 * `{{ }}` fa escape dell'**HTML**, non del **markdown**. Le viste di
 * `resources/views/mail/` passano da `Illuminate\Mail\Markdown::parse()`, che ha
 * `allow_unsafe_links => false`: blocca `javascript:`, non `https:`. Quindi un
 * nome come `Mario [Conferma qui](https://evil.example)` diventava un `<a>`
 * vero, spedito dal dominio di Easy Lab. Il caso peggiore è la registrazione
 * pubblica: un anonimo sceglie nome **e** destinatario.
 *
 * ## Cosa si fa
 *
 * - ogni carattere che apre una struttura inline o di tabella prende un
 *   backslash (CommonMark lo toglie in resa: nell'HTML non si vede);
 * - gli a-capo diventano spazi: un a-capo nel valore aprirebbe un blocco nuovo
 *   (un titolo, una riga di tabella) dentro l'email.
 *
 * ⚠️ `<` e `>` NON si toccano: `{{ }}` li ha già resi `&lt;`/`&gt;`, che
 * CommonMark legge come entità (testo, mai un autolink né un tag). Un backslash
 * davanti farebbe dell'`&` un carattere letterale, e il lettore vedrebbe
 * «&lt;» (T1cA-1/T1cB-3).
 *
 * ⚠️ L'apostrofo e le lettere accentate NON si toccano: non sono sintassi, e
 * un backslash in più lì si vedrebbe. La parte `text/plain` toglie i backslash
 * in `TestoEmail::senzaMarkdown()`, l'unico punto da cui passa.
 *
 * ⚠️ Un URL nudo resta testo: CommonMark qui non ha l'estensione autolink, ma
 * alcuni client di posta rendono cliccabili gli URL da soli. Si toglie il
 * link *costruito da noi*, non la possibilità di scrivere un indirizzo.
 */
final class TestoMarkdown
{
    /** I caratteri che in CommonMark + tabelle GFM aprono qualcosa. */
    public const SPECIALI = '\\`*_[]()#|!~';

    public static function sicuro(?string $testo): string
    {
        $testo = (string) preg_replace('/\R+/u', ' ', (string) $testo);

        return addcslashes($testo, self::SPECIALI);
    }
}
