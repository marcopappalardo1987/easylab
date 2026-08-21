<?php

namespace App\Support\Piattaforma;

use App\Support\Piani;

/**
 * I numeri della cabina di regia, calcolati una volta (S6 — Wireframe §4).
 *
 * DTO immutabile sul modello di `DiagnosiSemaforo`: chi lo riceve non può
 * ricalcolarne un pezzo per conto proprio, e i quattro numeri restano d'accordo
 * fra loro perché nascono dalla stessa passata.
 *
 * ⚠️ **`mrrCent` è il valore a LISTINO, non l'incassato.** Somma i piani a
 * catalogo degli account non cestinati, EasyLab esclusa. Include gli account in
 * **lockout**: ADR-013 dice che il blocco è una porta chiusa, non una disdetta,
 * quindi il contratto è in essere — e per questo `clientiBloccati` sta accanto,
 * a dire quanta parte di quel numero non si sta incassando. Include anche i
 * `past_due`, che per la stessa ADR non sono bloccati affatto.
 */
final class RiepilogoPiattaforma
{
    /**
     * ⚠️ **Un account in trial conta a listino pieno**, ed è una decisione: la
     * subscription è aperta e il contratto firmato, quindi vale come gli altri.
     * Il caso esiste (`easylab:abbona --trial-giorni`, e lo stato `trialing` fra
     * quelli sani del webhook), e senza questa riga sarebbe un effetto invece di
     * una scelta. Se un giorno si vorrà distinguerlo, serve una colonna in più
     * nel raggruppamento e un «di cui N in prova» — non una sottrazione muta.
     *
     * @param  int  $mrrCent  ricavo mensile ricorrente a listino, in centesimi
     * @param  int  $mrrBloccatoCent  quanta parte di `mrrCent` viene da account in lockout
     * @param  int  $clienti  account non cestinati, EasyLab esclusa
     * @param  int  $clientiBloccati  quanti dei precedenti hanno almeno una sorgente di lockout accesa
     * @param  int  $sedi  nodi di tipo Ente su tutta la piattaforma
     * @param  int  $strumenti  macchine di tutti i clienti
     * @param  array<string,int>  $perPiano  codice del piano → numero di account
     * @param  int  $pianiSconosciuti  account il cui `piano` non è più a catalogo (valgono 0)
     */
    public function __construct(
        public readonly int $mrrCent,
        public readonly int $mrrBloccatoCent,
        public readonly int $clienti,
        public readonly int $clientiBloccati,
        public readonly int $sedi,
        public readonly int $strumenti,
        public readonly array $perPiano,
        public readonly int $pianiSconosciuti,
    ) {}

    /**
     * L'MRR in euro, per la vista.
     *
     * Interi, e l'assunzione è difesa dove viene fatta: un test verifica che
     * ogni `prezzo_mensile_cent` a catalogo sia multiplo di 100. Diventa rosso
     * il giorno in cui qualcuno scrive 4950 — cioè esattamente quando questo
     * metodo comincerebbe a mentire, per difetto e in modo non proporzionale,
     * quindi irriconoscibile a una rilettura.
     */
    public function mrrEuro(): int
    {
        return intdiv($this->mrrCent, 100);
    }

    /** Quanto dell'MRR è fermo per lockout, in euro. */
    public function mrrBloccatoEuro(): int
    {
        return intdiv($this->mrrBloccatoCent, 100);
    }

    /**
     * Il «di cui» della tile Clienti, che **somma al numero sopra**.
     *
     * Vive qui e non nel template per una ragione precisa: il template conosce
     * `perPiano`, che contiene i soli piani a catalogo, e non `pianiSconosciuti`
     * — quindi lì la frase non ha accesso al dato che le serve per essere
     * corretta. Con un cliente SaaS e uno su un piano dismesso mostrava «1 SaaS»
     * sotto un totale di 2, e chi legge un «di cui» la somma la fa.
     *
     * Gli zeri si saltano: la regola «i piani a zero si mostrano» vale per un
     * elenco, dove una riga assente si legge come «quel piano non esiste». In
     * coda a un numero diventa rumore — un'installazione senza clienti Free
     * aprirebbe sempre con «0 Free ·».
     */
    public function dettaglioClienti(): string
    {
        $voci = collect($this->perPiano)
            ->filter()
            ->map(fn (int $n, string $piano) => $n.' '.Piani::etichetta($piano))
            ->values();

        if ($this->pianiSconosciuti > 0) {
            $voci->push($this->pianiSconosciuti.' '.($this->pianiSconosciuti === 1
                ? 'piano sconosciuto'
                : 'piani sconosciuti'));
        }

        return $voci->implode(' · ');
    }
}
