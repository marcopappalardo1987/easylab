<?php

namespace App\Support\Piattaforma;

use App\Support\Piani;

/**
 * Il titolo di una scheda del Parco dice **su chi** si sta guardando (🔗 ADR-037).
 *
 * Una regola sola per le tre schede, e non è pignoleria di stile: un titolo
 * statico «Scadenzario di tutti i clienti» sopra una tabella filtrata su un
 * cliente dice il falso, e lo dice proprio sulle pagine il cui primo requisito
 * è sapere di CHI sono le righe. Il rischio del Parco non è sbagliare macchina:
 * è **impersonare dalla riga del cliente sbagliato**, e ogni testo che allarga
 * il perimetro a parole ci lavora contro.
 *
 * *Difetto trovato il 29 Ago 2026 guardando le pagine vere: gli Strumenti il
 * titolo lo componevano, Scadenzario e Ricambi lo avevano **scritto a mano in
 * Blade** e annunciavano «di tutti i clienti» in tutti e tre i modi — anche con
 * zero preferiti, cioè sopra tre contatori a zero.*
 *
 * ⛔ Sta qui e non in tre `match` copiati per la ragione che il progetto ha già
 * pagato altrove (le whitelist dei filtri riscritte in vista, che divergevano
 * al primo bugfix): un ramo di questo `match` è tutt'altro che ovvio — quello di
 * `SCELTI` — e tre copie significherebbero che due si dimenticano di averlo.
 */
final class TitoloPerimetro
{
    /**
     * «‹Soggetto› di tutti i clienti», «…dei clienti sul piano SaaS», «…di 3
     * clienti preferiti».
     *
     * @param  string  $soggetto  Cosa la scheda elenca: `Strumenti`, `Scadenzario`, `Ricambi`.
     * @param  int  $preferiti  Quanti preferiti **veri**, cioè già intersecati con
     *                          l'insieme legittimo. Dire «2 clienti preferiti» sopra
     *                          una tabella costruita su uno solo sarebbe la stessa
     *                          bugia del titolo statico, detta con un numero.
     */
    public static function componi(string $soggetto, Perimetro $perimetro, int $preferiti): string
    {
        return match (true) {
            $perimetro->modo === Perimetro::TUTTI => "{$soggetto} di tutti i clienti",
            $perimetro->modo === Perimetro::PER_PIANO => "{$soggetto} dei clienti sul piano ".Piani::etichetta($perimetro->piano),
            // 🔴 Il residuo di `SCELTI`, che dopo la ★ ha una sola provenienza:
            // il modo «per piano» finché il piano manca, perché `nessuno()` è
            // `scelti([])`. Senza questo ramo il titolo cadrebbe nel `default` e
            // annuncerebbe i **preferiti** sopra una pagina il cui controllo dice
            // «Per piano» — una bugia nuova al posto di una vecchia.
            $perimetro->modo === Perimetro::SCELTI => "{$soggetto}: nessun piano selezionato",
            $preferiti === 1 => "{$soggetto} di 1 cliente preferito",
            $preferiti > 1 => "{$soggetto} di {$preferiti} clienti preferiti",
            default => "{$soggetto} dei clienti preferiti",
        };
    }
}
