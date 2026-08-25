<?php

namespace App\Support\Parco;

/**
 * Lo stato del parco macchine di chi guarda, calcolato una volta (S6 — dashboard
 * Admin/Tenant, Wireframe §1).
 *
 * DTO immutabile sul modello di `RiepilogoPiattaforma`, che a sua volta segue
 * `DiagnosiSemaforo`: chi lo riceve non può ricalcolarne un pezzo per conto
 * proprio, e i numeri restano d'accordo fra loro perché nascono dalla stessa
 * passata.
 *
 * ⚠️ **Tre di questi numeri sono una PARTIZIONE, il quarto no.** Verde,
 * arancione e rosso coprono tutto il parco e non si sovrappongono (🔗 ADR-005:
 * il forzato vince, e i tre rami di `Strumento::scopeConStato()` sono disgiunti
 * ed esaustivi). `obsoleti` è **ortogonale**: 🔗 ADR-014 dice che l'obsolescenza
 * «non tocca il semaforo», quindi una macchina obsoleta è già contata in una
 * delle tre caselle. Quattro riquadri in fila si leggono come quattro fette di
 * una torta, e non lo sono — per questo la pagina lo dice in chiaro invece di
 * lasciarlo dedurre da chi prova a sommare.
 *
 * ⛔ **Non ci sarà mai un `dettaglio` che scompone l'arancione per causa.**
 * 🔗 ADR-020 legittima il **pallino** come aggregato dovuto a tutti — il Tenant
 * non vede le righe garanzia-ricambio ma vede l'arancione che ne deriva — e non
 * la sua scomposizione. Un «di cui N da garanzie ricambio» direbbe a un Ente su
 * `visibilita_garanzie_ricambio = nascosta` quanti pezzi sostituiti ha sulle
 * proprie macchine, e lo direbbe **senza passare da nessuno scope**, perché un
 * numero non è una riga. Un test lo congela.
 */
final class RiepilogoParco
{
    /**
     * @param  int  $verdi  macchine in regola
     * @param  int  $arancioni  macchine con azione richiesta
     * @param  int  $rossi  macchine non idonee (solo per forzatura, ADR-005)
     * @param  int  $obsoleti  oltre la soglia di età del proprio Ente (ADR-014)
     * @param  list<int>  $soglie  le soglie di obsolescenza distinte fra gli Enti visibili
     */
    public function __construct(
        public readonly int $verdi,
        public readonly int $arancioni,
        public readonly int $rossi,
        public readonly int $obsoleti,
        public readonly array $soglie,
    ) {}

    /**
     * Il parco intero.
     *
     * **Derivato dalla somma e non contato a parte**, di proposito: se i tre
     * stati smettessero di partizionare, un totale letto con una quarta query
     * lo nasconderebbe: i numeri tornerebbero singolarmente e non fra loro. Così
     * invece la divergenza diventa visibile — e un test la asserisce contro
     * `Strumento::count()`.
     */
    public function totale(): int
    {
        return $this->verdi + $this->arancioni + $this->rossi;
    }

    public function parcoVuoto(): bool
    {
        return $this->totale() === 0;
    }

    /**
     * La riga sotto il numero degli obsoleti.
     *
     * Vive qui e non nel template per la ragione già scritta in
     * `RiepilogoPiattaforma::dettaglioClienti()`: la frase ha bisogno di un
     * dato — quante soglie diverse ci sono — che il template non ha.
     *
     * ⚠️ **La soglia è per Ente** (ADR-014), quindi «oltre 10 anni» è vero solo
     * finché di soglie ne esiste una. Chi vede più sedi con soglie diverse — un
     * Tecnico col portafoglio, un Admin che passa da una sede all'altra — non
     * deve leggere un numero solo spacciato per la regola di tutti.
     */
    public function dettaglioObsoleti(): string
    {
        return match (count($this->soglie)) {
            0 => '',
            1 => 'oltre '.$this->soglie[0].' anni',
            default => 'oltre la soglia di ciascuna sede',
        };
    }
}
