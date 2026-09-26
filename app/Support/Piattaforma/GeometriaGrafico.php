<?php

namespace App\Support\Piattaforma;

/**
 * La geometria dei grafici SVG scritti a mano (S6 — 🔗 `docs/Design/Design
 * System Base.md` §2.5, campione §10 `.el-chart`/`.el-spark`/`.el-meter`,
 * ADR-034).
 *
 * Matematica pura: nessuna dipendenza da Eloquent, dalla richiesta o dai token.
 * Sta qui e non nei template per una ragione precisa — **una divisione per zero
 * in un Blade non dà un `d="M NaN"`, dà un 500**. In PHP 8 `$x / 0` lancia
 * `DivisionByZeroError`, e i casi che ci arrivano sono tutti reali e tutti al
 * primo giorno di vita della piattaforma:
 *
 *  - la serie **vuota** (nessun mese da disegnare);
 *  - la serie di **un punto solo** (un `d` con un solo `L` non è un percorso);
 *  - dodici mesi **tutti a zero** (installazione appena fatta);
 *  - dodici mesi **tutti uguali** (`max === min`, cioè nessuna crescita).
 *
 * Gli ultimi due sono lo stesso `($v - $min) / ($max - $min)` con denominatore
 * zero, e la cabina di regia andrebbe in 500 **sul cliente numero zero** — cioè
 * il giorno in cui nessuno sta guardando i log. Ogni divisione di questo file ha
 * la sua guardia esplicita **prima**, e la serie piatta va a metà altezza.
 *
 * ⚠️ **L'asse Y dell'SVG cresce verso il basso**: il massimo sta in alto solo
 * perché la normalizzazione è sottratta. È l'errore che nessuno vede perché il
 * grafico resta bello, quindi ha un test tutto suo.
 */
final class GeometriaGrafico
{
    /**
     * Il percorso di una sparkline: la linea, l'area sotto e l'ultimo punto.
     *
     * ⚠️ Il viewBox è 120×32 e il componente lo rende con
     * `preserveAspectRatio="none"`, quindi le unità **non sono quadrate**: la
     * linea vuole `vector-effect="non-scaling-stroke"` o lo spessore si deforma
     * in orizzontale. È il motivo per cui questo metodo lavora in unità di
     * viewBox e non in pixel.
     *
     * @param  list<int|float>  $valori
     * @return array{linea: string, area: string, ultimoX: float, ultimoY: float}|null
     *                                                                                 `null` su serie vuota: il componente deve poter **non disegnare nulla**,
     *                                                                                 che è diverso dal disegnare una linea piatta a zero (quella si legge
     *                                                                                 come un dato, e qui il dato non c'è).
     */
    public static function percorsoSparkline(array $valori, float $l = 120, float $h = 32, float $m = 3): ?array
    {
        $n = count($valori);

        if ($n === 0) {
            return null;
        }

        $min = min($valori);
        $max = max($valori);
        $alto = $m;
        $basso = $h - $m;

        // ⛔ Le due guardie che tengono in piedi la pagina il primo giorno.
        // `$n === 1` azzera il passo orizzontale, `$max === $min` il fattore
        // verticale: entrambi sono divisioni per zero, non punti sovrapposti.
        $passo = $n === 1 ? 0.0 : ($l / ($n - 1));
        $piatta = ($max - $min) == 0;

        $punti = [];

        foreach ($valori as $i => $valore) {
            $x = $n === 1 ? $l / 2 : $i * $passo;
            // La sottrazione è ciò che mette il MASSIMO in alto: senza, il
            // grafico resterebbe bello e racconterebbe il contrario.
            $y = $piatta
                ? $h / 2
                : $basso - (($valore - $min) / ($max - $min)) * ($basso - $alto);

            $punti[] = [round($x, 1), round($y, 1)];
        }

        if ($n === 1) {
            // Un punto solo diventa una **linea orizzontale**, non un percorso
            // monco: `M 60 16` da solo non disegna niente e non dà errore.
            $punti = [[0.0, $punti[0][1]], [$l, $punti[0][1]]];
        }

        $linea = 'M '.implode(' L ', array_map(
            fn (array $p) => self::coordinata($p[0]).' '.self::coordinata($p[1]),
            $punti
        ));

        $primo = $punti[0];
        $ultimo = $punti[count($punti) - 1];

        $area = $linea
            .' L '.self::coordinata($ultimo[0]).' '.self::coordinata($h)
            .' L '.self::coordinata($primo[0]).' '.self::coordinata($h).' Z';

        return [
            'linea' => $linea,
            'area' => $area,
            'ultimoX' => $ultimo[0],
            'ultimoY' => $ultimo[1],
        ];
    }

    /**
     * Le barre di un istogramma, in unità del viewBox 440×170 del campione.
     *
     * ⚠️ Il viewBox resta 440×170 perché è vicino alla larghezza reale della
     * card: un SVG con `width:100%` scala **anche il testo**, e un viewBox da
     * 120 unità con dentro etichette da 10px le renderebbe gigantesche su
     * desktop.
     *
     * Su una serie tutta a zero le altezze sono 0 e la vista **non disegna il
     * rettangolo**: una barra di altezza zero è un artefatto di 1px che si legge
     * come un dato minuscolo invece che come un'assenza.
     *
     * @param  list<int|float>  $valori
     * @return list<array{x: float, y: float, larghezza: float, altezza: float, centroX: float, valore: int|float}>
     */
    public static function barre(array $valori, float $l = 440, float $h = 170, float $baseline = 144, float $alto = 22): array
    {
        $n = count($valori);

        if ($n === 0) {
            return [];
        }

        $max = max($valori);
        $banda = $l / $n;
        $larghezza = $banda * 0.55;
        $disponibile = $baseline - $alto;

        $barre = [];

        foreach ($valori as $i => $valore) {
            // ⛔ `$max` è zero su una piattaforma appena installata: senza questa
            // guardia la pagina sarebbe un 500, non un grafico piatto.
            $altezza = $max > 0 ? ($valore / $max) * $disponibile : 0.0;
            $centro = $banda * $i + $banda / 2;

            $barre[] = [
                'x' => round($centro - $larghezza / 2, 1),
                'y' => round($baseline - $altezza, 1),
                'larghezza' => round($larghezza, 1),
                'altezza' => round($altezza, 1),
                'centroX' => round($centro, 1),
                'valore' => $valore,
            ];
        }

        return $barre;
    }

    /**
     * Le percentuali di una barra di composizione, che **sommano a 100 esatti**.
     *
     * ⚠️ Tre clienti su tre piani danno 33,33% tre volte: 99,99, e la barra non
     * chiude — resta una fessura del fondo in coda, che si legge come una quarta
     * categoria senza nome. L'**ultima voce non nulla assorbe il resto**, che è
     * lo stesso patto con cui `RiepilogoPiattaforma::dettaglioClienti()` fa
     * tornare il «di cui» col numero sopra.
     *
     * Su totale 0 tutte le percentuali sono 0 (e non `NaN`, né un 500).
     *
     * @param  list<int>  $valori
     * @return list<float>
     */
    public static function segmenti(array $valori): array
    {
        $totale = array_sum($valori);

        if ($totale <= 0) {
            return array_map(fn () => 0.0, $valori);
        }

        $percentuali = array_map(fn (int $v) => round($v * 100 / $totale, 2), $valori);

        // L'ultima voce **non nulla**: darlo a una voce a zero la farebbe
        // comparire nella barra senza comparire nella legenda.
        $ultima = null;

        foreach ($percentuali as $i => $p) {
            if ($valori[$i] > 0) {
                $ultima = $i;
            }
        }

        if ($ultima !== null) {
            $percentuali[$ultima] = round($percentuali[$ultima] + (100 - array_sum($percentuali)), 2);
        }

        return $percentuali;
    }

    /**
     * Una coordinata con un decimale, come le scrive il campione.
     *
     * `number_format` con la virgola sarebbe un `d` invalido: la locale non
     * entra mai in un attributo SVG.
     */
    private static function coordinata(float $v): string
    {
        return number_format($v, 1, '.', '');
    }
}
