<?php

namespace App\Support\Piattaforma;

use InvalidArgumentException;

/**
 * Una serie di valori mensili, già allineata alle sue etichette (S6 — grafici
 * della cabina di regia; 🔗 `docs/Design/Design System Base.md` §2.5/§8.2,
 * ADR-034).
 *
 * DTO immutabile sul modello di `RiepilogoPiattaforma`: chi lo riceve non può
 * ricalcolarne un pezzo per conto proprio, e le tre liste restano d'accordo fra
 * loro perché nascono dalla stessa passata.
 *
 * **Perché tre liste parallele e non dodici oggetti.** I consumatori sono due e
 * vogliono cose diverse: `GeometriaGrafico` vuole i soli **valori** (matematica
 * pura, nessuna dipendenza da questo namespace), la tabella accessibile del
 * grafico a barre vuole **mese + etichetta + valore** riga per riga. Un array di
 * record costringerebbe il primo a rimappare a ogni chiamata; tre liste con un
 * invariante di lunghezza dichiarato costano una `throw_if` e nient'altro.
 *
 * ⚠️ **L'invariante si difende in costruttore**, come fa `x-ui.stat-tile` con la
 * label: tre liste disallineate non danno un errore, danno un grafico in cui
 * l'etichetta di ottobre sta sotto il valore di settembre — cioè un difetto che
 * resta bello da guardare.
 */
final class SerieMensile
{
    /**
     * @param  list<string>  $mesi  le chiavi `YYYY-MM`, per i `<title>` e la tabella
     * @param  list<string>  $etichette  le abbreviazioni italiane a tre lettere (`Set`, `Ott`, …)
     * @param  list<int>  $valori  il valore di ciascun mese
     * @param  int|null  $base  quanto c'era **subito prima** del primo punto, quando
     *                          quel numero esiste; `null` quando non esiste
     */
    public function __construct(
        public readonly array $mesi,
        public readonly array $etichette,
        public readonly array $valori,
        public readonly ?int $base = null,
    ) {
        throw_if(
            count($mesi) !== count($etichette) || count($mesi) !== count($valori),
            InvalidArgumentException::class,
            'SerieMensile vuole tre liste della stessa lunghezza: '
            .count($mesi).' mesi, '.count($etichette).' etichette, '.count($valori).' valori.'
        );
    }

    public function massimo(): int
    {
        return $this->valori === [] ? 0 : max($this->valori);
    }

    public function minimo(): int
    {
        return $this->valori === [] ? 0 : min($this->valori);
    }

    public function primo(): int
    {
        return $this->valori[0] ?? 0;
    }

    public function ultimo(): int
    {
        return $this->valori === [] ? 0 : $this->valori[count($this->valori) - 1];
    }

    /**
     * Quanto è cambiata la serie **nella finestra**, base compresa.
     *
     * È il numero che va **scritto a parole** accanto alla sparkline: l'SVG è
     * decorativo e `aria-hidden` (ADR-034), quindi l'informazione deve esistere
     * anche per chi non lo vede — e per Sedi e Strumenti quella riga di testo è
     * l'**unica** informazione che esiste, perché accanto non c'è nemmeno un
     * grafico a barre a smentirla.
     *
     * ⛔ **Su una serie cumulata `ultimo() - primo()` è sbagliato di un mese**, e
     * lo è in silenzio. `primo()` è il cumulato alla **fine** del primo bucket
     * mostrato — cioè `c1`, non `c0` — quindi la differenza copre undici
     * intervalli su dodici e perde tutto ciò che è entrato nel primo mese della
     * finestra. Il sintomo è la cifra *plausibile e diversa* che
     * `PerimetroClienti` esiste per impedire, spostata di due centimetri più in
     * basso: una piattaforma che ha preso tre clienti a settembre e nessuno dopo
     * mostrerebbe «Clienti 3» nella tile, una barra da 3 su Set nel grafico
     * accanto e «+0 in 12 mesi» qui sotto. Per questo la variazione si misura
     * dalla **base**, che è `c0`, e la finestra torna a essere di dodici mesi.
     *
     * Senza base dichiarata resta la distanza fra il primo e l'ultimo punto
     * visibile: è la lettura giusta per una serie che cumulata non è (i nuovi
     * clienti per mese partono da zero a ogni bucket, e la loro «crescita nella
     * finestra» non è un numero che voglia dire qualcosa).
     */
    public function variazione(): int
    {
        if ($this->valori === []) {
            return 0;
        }

        return $this->ultimo() - ($this->base ?? $this->primo());
    }

    /** La variazione col segno davanti, per la riga di testo sotto il numero. */
    public function variazioneConSegno(): string
    {
        $v = $this->variazione();

        return ($v >= 0 ? '+' : '−').abs($v);
    }

    public function totale(): int
    {
        return (int) array_sum($this->valori);
    }

    /**
     * Vera quando **non c'è niente da raccontare**: nessun punto, o tutti a zero.
     *
     * Serve alle viste per dire «nessun dato» invece di disegnare una linea
     * piatta sul fondo, che si legge come un dato ed è invece un'assenza.
     */
    public function eVuota(): bool
    {
        return $this->valori === [] || $this->massimo() === 0 && $this->minimo() === 0;
    }

    /**
     * Le righe della tabella accessibile: `[mese, etichetta, valore]`.
     *
     * @return list<array{mese: string, etichetta: string, valore: int}>
     */
    public function righe(): array
    {
        $righe = [];

        foreach ($this->valori as $i => $valore) {
            $righe[] = [
                'mese' => $this->mesi[$i],
                'etichetta' => $this->etichette[$i],
                'valore' => $valore,
            ];
        }

        return $righe;
    }
}
