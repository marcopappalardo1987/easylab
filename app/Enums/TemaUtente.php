<?php

namespace App\Enums;

/**
 * La preferenza di tema dell'utente (🔗 ADR-034, Design System §8.3).
 *
 * Tre stati e non due: `Sistema` **non** è «nessuna scelta», è la scelta di
 * seguire il sistema operativo. Senza di esso l'interruttore avrebbe una sola
 * direzione — chi passa a chiaro non potrebbe più tornare a farsi decidere dal
 * dispositivo — ed è la stessa ragione per cui `app.css` porta il
 * `:not([data-theme="light"])` sulla media query.
 *
 * **Il DB è la verità, `localStorage` è la cache che evita il lampo** (ADR-034
 * punto 3): l'autenticato riceve l'attributo dal server, l'ospite lo scrive da
 * uno script inline nel `<head>` leggendo questa stessa preferenza da
 * `localStorage`.
 */
enum TemaUtente: string
{
    case Sistema = 'sistema';
    case Chiaro = 'chiaro';
    case Scuro = 'scuro';

    /**
     * La chiave sotto cui l'ospite ritrova la preferenza.
     *
     * ⚠️ **Vi si salva il valore di dominio** (`sistema|chiaro|scuro`), non
     * quello dell'attributo HTML: `Sistema` deve poter essere *scritto*, perché
     * «ho scelto di seguire l'OS» e «non ho mai scelto» sono due stati diversi
     * — con le sole `light|dark` il ritorno a `sistema` si esprimerebbe
     * cancellando la chiave, cioè con un gesto che non si distingue da una
     * pulizia del browser.
     *
     * ⚠️ Chi scrive il lato JavaScript (F1.3) usa **questa** chiave: i layout la
     * rendono da qui, non a mano.
     */
    public const CHIAVE_LOCALSTORAGE = 'easylab-tema';

    /**
     * Il valore di `data-theme` sull'`<html>`, o `null` per non scriverlo.
     *
     * Due vocabolari, e la traduzione sta qui una volta sola: la colonna è in
     * italiano come ogni valore di dominio del progetto, mentre `light`/`dark`
     * sono il **contratto con il foglio di stile** — sono le stringhe che
     * `resources/css/app.css` cerca nei propri selettori, e cambiarle da un lato
     * solo non darebbe errore: darebbe una pagina del tema sbagliato.
     *
     * ⚠️ **`Sistema` restituisce `null`, e l'attributo non va scritto affatto.**
     * Non esiste un `data-theme="system"`: la regola dell'OS è l'ASSENZA
     * dell'attributo (ADR-034 punto 2). Un `data-theme=""` o un valore terzo
     * inciamperebbero nel `:not([data-theme="light"])` e bloccherebbero la media
     * query — cioè spegnerebbero silenziosamente la preferenza di sistema, che è
     * il caso che si dimentica di provare.
     */
    public function attributoHtml(): ?string
    {
        return match ($this) {
            self::Sistema => null,
            self::Chiaro => 'light',
            self::Scuro => 'dark',
        };
    }

    /**
     * La preferenza di un utente che potrebbe non averne una.
     *
     * `null` in due casi veri e nessuno dei due è un errore: l'ospite (nessun
     * utente autenticato) e il modello costruito in memoria che non ha ancora
     * riletto i default della colonna dal database. In entrambi si segue il
     * sistema operativo, che è il default anche a schema.
     */
    public static function oSistema(?self $tema): self
    {
        return $tema ?? self::Sistema;
    }
}
