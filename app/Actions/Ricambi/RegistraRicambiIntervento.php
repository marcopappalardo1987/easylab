<?php

namespace App\Actions\Ricambi;

use App\Enums\SoggettoGaranzia;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registra i pezzi montati durante un intervento (ADR-022, wireframe §2.1):
 * per ogni riga, in UNA transazione, voce di catalogo (collega-o-crea) +
 * `ricambio_utilizzo` + `garanzia` con `soggetto = ricambio`.
 *
 * Vive qui e non dentro `SchedaStrumento` perché il form intervento non è il
 * suo unico chiamante: il tab Ricambi (S4 blocco 5) e la vista mobile del
 * tecnico (blocco 10) devono ottenere lo stesso risultato senza riscriverne la
 * sequenza. `app/Support` ospita motori di lettura (`Semaforo`, `Rbac`,
 * `Tenancy`), non scritture: da qui la cartella `Actions`.
 *
 * **L'ordine catalogo → utilizzo → garanzia non è convenzionale, è imposto.**
 * Le guardie di `RicambioUtilizzo` leggono `ricambi.tenant_id` col query
 * builder, quindi la voce deve essere già in tabella (stessa transazione,
 * stessa connessione: la vede); e `Garanzia::verificaSoggetto()` esige un
 * `ricambio_utilizzo_id` non nullo, che esiste solo dopo il salvataggio della
 * riga. Invertire gli ultimi due passi non produce un ordine diverso: produce
 * un'eccezione.
 *
 * **Il servizio non calcola delta**, ed è la traduzione letterale della nota
 * del wireframe: «deselezionare la checkbox non cancella righe già salvate, la
 * rimozione è esplicita riga per riga». Un delta fra payload e database
 * renderebbe un array vuoto ambiguo fra "niente da fare" e "cancella tutto" —
 * e la checkbox deselezionata produce esattamente un array vuoto. Le righe da
 * rimuovere arrivano quindi come **id espliciti**, e le righe esistenti non
 * toccate restano dove sono.
 */
class RegistraRicambiIntervento
{
    /**
     * @param  list<array{nome:string,scadenza_garanzia:string,fornitore_id?:?int}>  $nuove
     * @param  list<int>  $rimosse  id di `ricambio_utilizzo`, risolti SOLO dentro $intervento
     * @return array{creati: Collection<int, RicambioUtilizzo>, rimossi: int}
     */
    public function esegui(Intervento $intervento, array $nuove, array $rimosse = []): array
    {
        // Transazione propria anche se il chiamante ne ha già una (annidata →
        // savepoint): così l'atomicità delle righe è una proprietà DEL SERVIZIO
        // e resta vera per i chiamanti futuri, non una gentilezza di questo.
        return DB::transaction(function () use ($intervento, $nuove, $rimosse) {
            // Data di MONTAGGIO: la data di esecuzione dell'intervento, oppure
            // **NULL** se l'intervento è ancora pianificato — perché in quel
            // caso il pezzo non è stato montato, e una data qualsiasi sarebbe
            // un'affermazione falsa. `Intervento::segnaFatto()` la riempie alla
            // chiusura.
            //
            // ⚠️ Qui c'era `?? today()` come segnaposto, e non bastava
            // correggerlo alla chiusura: fino ad allora la scheda diceva
            // «montato il <oggi>» per un pezzo che nessuno aveva toccato, e la
            // riga sarebbe comparsa in qualunque ricerca per periodo.
            // Correggere a valle un valore inventato a monte non lo rende vero.
            $montaggio = $intervento->data_esecuzione;

            // La garanzia invece una data d'inizio la vuole sempre (colonna NOT
            // NULL, e `normalizzaScadenza()` la esige): su un intervento
            // pianificato si usa oggi come inizio provvisorio, riallineato
            // anch'esso alla chiusura. L'asimmetria è voluta — «non montato» è
            // rappresentabile, «garanzia senza inizio» no — e non tocca il
            // semaforo, perché la scadenza è dichiarata e non derivata.
            $inizioGaranzia = $intervento->data_esecuzione ?? today();

            foreach ($rimosse as $id) {
                $this->rimuovi($intervento, (int) $id);
            }

            $creati = collect($nuove)->map(
                fn (array $riga) => $this->registra($intervento, $riga, $montaggio, $inizioGaranzia)
            );

            return ['creati' => $creati, 'rimossi' => count($rimosse)];
        });
    }

    private function registra(Intervento $intervento, array $riga, mixed $montaggio, mixed $inizioGaranzia): RicambioUtilizzo
    {
        $ricambio = Ricambio::collegaOCrea($riga['nome'], $intervento->tenant_id);

        $utilizzo = RicambioUtilizzo::create([
            'tenant_id' => $intervento->tenant_id,
            'strumento_id' => $intervento->strumento_id,
            'ricambio_id' => $ricambio->id,
            'intervento_id' => $intervento->id,
            // Da chi è stato comprato il pezzo (ADR-051), se chi registra lo
            // dice. La coerenza col tenant la impone il model.
            'fornitore_id' => $riga['fornitore_id'] ?? null,
            // Il form non chiede la quantità (wireframe §2.1): due pezzi uguali
            // sullo stesso intervento sono un refuso, e il caso vero
            // ("due guarnizioni") si registra dal tab Ricambi.
            'quantita' => 1,
            'data' => $montaggio, // NULL finché l'intervento non è chiuso
        ]);

        $garanzia = new Garanzia([
            'tenant_id' => $intervento->tenant_id,
            'soggetto' => SoggettoGaranzia::Ricambio,
            'ricambio_utilizzo_id' => $utilizzo->id,
            'data_inizio' => $inizioGaranzia,
        ]);

        // ADR-022: la garanzia è obbligatoria per riga, ed è una DATA dichiarata.
        $garanzia->fissaScadenzaDichiarata($riga['scadenza_garanzia'])->save();

        return $utilizzo;
    }

    private function rimuovi(Intervento $intervento, int $id): void
    {
        // Dalla relazione e non `RicambioUtilizzo::findOrFail()`: riapplica
        // TenantScope e il livello 2, e vincola `intervento_id` — un solo
        // idioma copre altro tenant, fuori sotto-albero e id di un altro
        // intervento. È lo stesso della scheda strumento.
        $utilizzo = $intervento->ricambiUtilizzi()->findOrFail($id);

        // Il gesto di cancellare vive nel model (`cestinaConGaranzia`), qui
        // resta la RISOLUZIONE della riga, che è ciò che vincola
        // `intervento_id`. Dal 15 Ago 2026 i posti che cancellano un pezzo sono
        // tre — questo, il tab Ricambi e la cancellazione di un intervento
        // intero — e la sequenza garanzia-poi-utilizzo, col suo bypass del
        // privacy scope, non può stare in tre copie: la prima dimenticata
        // lascerebbe una garanzia viva e orfana, cioè una scadenza che pesa sul
        // semaforo di un pezzo che non c'è più.
        $utilizzo->cestinaConGaranzia();
    }
}
