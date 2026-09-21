<?php

namespace App\Support\Mail;

/**
 * Il **corpo testuale** di un'email: ciò che resta quando si toglie il markdown
 * che nessuno renderà (🔗 ADR-011 §canali; ADR-034 le email non hanno tema).
 *
 * ## Perché serve una funzione, e perché sta qui
 *
 * ⛔ **`Illuminate\Mail\Markdown::renderText()` non fa alcun parsing markdown.**
 * Rende il Blade con le componenti `mail.text` e applica `strip_tags` (dentro
 * `text/layout.blade.php`) più `html_entity_decode`: nient'altro. Le viste di
 * `resources/views/mail/` sono scritte in markdown perché la resa HTML passa da
 * `Markdown::parse()` — quindi tutto ciò che è sintassi (`#`, `**`,
 * `[etichetta](url)`) arriva **grezzo** a chi legge il `text/plain`.
 *
 * Il difetto si era già visto sulle tabelle del digest ed era stato corretto
 * dentro `text/table.blade.php` — cioè **solo** per le righe della tabella. Il
 * corpo attorno restava intatto: «Puoi disattivare le email dalle [preferenze
 * notifiche](http://…/settings/notifiche)», con le parentesi quadre e l'URL in
 * chiaro. La correzione vive quindi nel **layout**, che è l'unico punto da cui
 * passa tutto: testata, corpo, sottotesto e piè di pagina.
 *
 * ⚠️ **È idempotente, e deve restarlo**: `text/table.blade.php` normalizza le
 * proprie righe *prima*, e il layout ripassa sullo stesso testo. Un secondo giro
 * su un testo già ripulito non trova più nulla da sostituire. (Unica eccezione:
 * un backslash scritto dall'utente, che il passo 4 toglie a ogni giro; il
 * layout ci passa una volta sola per pezzo.)
 *
 * ⚠️ **Non è un renderer markdown**, e non deve diventarlo: qui entrano solo le
 * tre forme che le nostre viste usano davvero. Un parser completo su un corpo
 * già passato per `strip_tags` sarebbe una seconda implementazione della resa,
 * cioè un secondo posto in cui l'email può divergere da sé stessa.
 */
final class TestoEmail
{
    /**
     * Toglie dal testo il markdown che il destinatario vedrebbe in chiaro.
     *
     * 1. `[etichetta](url)` → «etichetta — url», o il solo url quando i due
     *    coincidono (il sottotesto «copia e incolla questo indirizzo» scrive
     *    l'URL in entrambe le posizioni, e ripeterlo due volte sarebbe una riga
     *    illeggibile invece di una corretta);
     * 2. i titoli `#`/`##` perdono i cancelletti e restano righe di testo;
     * 3. `**grassetto**` e `*corsivo*` perdono gli asterischi;
     * 4. i backslash di `TestoMarkdown::sicuro()` se ne vanno: nell'HTML li
     *    toglie CommonMark, qui nessuno. Le tre regole sopra saltano i
     *    caratteri preceduti da un backslash, o un `\*` scritto da un utente
     *    diventerebbe enfasi solo nella parte testuale.
     */
    public static function senzaMarkdown(string $testo): string
    {
        // 1. i link.
        $testo = preg_replace_callback(
            '/(?<!\\\\)\[((?:\\\\.|[^\]\\\\])*)\]\(([^)\s]*)\)/',
            function (array $trovato): string {
                $etichetta = trim($trovato[1]);
                $url = trim($trovato[2]);

                return $etichetta === '' || $etichetta === $url
                    ? $url
                    : $etichetta.' — '.$url;
            },
            $testo,
        );

        // 2. i titoli, solo a inizio riga: un `#` in mezzo a una frase è un
        //    cancelletto, non un titolo.
        $testo = preg_replace('/^[ \t]*#{1,6}[ \t]+/m', '', (string) $testo);

        // 3. l'enfasi. Il grassetto prima del corsivo, o `**x**` diventerebbe
        //    `*x*`.
        $testo = preg_replace('/(?<!\\\\)\*\*([^*]+?)(?<!\\\\)\*\*/', '$1', (string) $testo);
        $testo = preg_replace('/(?<![*\\\\])\*([^*\n]+?)(?<!\\\\)\*(?!\*)/', '$1', (string) $testo);

        // 4. gli escape di `TestoMarkdown::sicuro()`.
        return (string) preg_replace('/\\\\([\\\\`*_\[\]()#|!~])/', '$1', (string) $testo);
    }
}
