<?php

namespace App\Actions\Documenti;

use App\Enums\TipoDocumento;
use App\Models\Documento;
use App\Models\Intervento;
use App\Models\Strumento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Carica un documento e ne registra la riga (ADR-009/025/026).
 *
 * In `app/Actions` come `RegistraRicambiIntervento`: è una regola di
 * persistenza, deve essere esercitabile senza montare una schermata, e la vista
 * campo del tecnico (blocco 10) dovrà caricarne senza riscriverla.
 *
 * ⚠️ **L'ordine è file-poi-riga, e non è indifferente.** Se la riga nascesse
 * prima, un upload fallito la lascerebbe a puntare a un oggetto inesistente —
 * e il download darebbe un errore su un documento che l'elenco mostra. Al
 * contrario, un file orfano nel bucket è materiale del job di retention: costa
 * spazio, non correttezza. Il disco ha `throw => true` proprio perché quel
 * fallimento sia un'eccezione e non un `false` silenzioso.
 */
class CaricaDocumento
{
    public function esegui(Model $soggetto, UploadedFile $file, TipoDocumento $tipo): Documento
    {
        $strumento = $this->strumentoDi($soggetto);

        // `tenant_id` nel prefisso (ERD §8.1): non è sicurezza — quella è la
        // Policy — ma rende il bucket leggibile e un ripristino circoscrivibile.
        $path = $file->store("tenant-{$strumento->tenant_id}/strumento-{$strumento->id}", Documento::DISCO);

        return DB::transaction(fn () => Documento::create([
            'tenant_id' => $strumento->tenant_id,
            'documentabile_type' => $soggetto::class,
            'documentabile_id' => $soggetto->getKey(),
            'strumento_id' => $strumento->id,
            'tipo' => $tipo,
            'nome' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'caricato_da' => auth()->id(),
        ]));
    }

    /**
     * Lo strumento a cui il documento appartiene, comunque lo si guardi.
     *
     * È il valore della colonna denormalizzata su cui poggia il livello 2:
     * calcolarlo qui, in un posto solo, è ciò che impedisce a un chiamante
     * futuro di scriverla sbagliata — una riga con `strumento_id` incoerente
     * sarebbe invisibile al Responsabile giusto e visibile a quello sbagliato.
     */
    private function strumentoDi(Model $soggetto): Strumento
    {
        return match (true) {
            $soggetto instanceof Strumento => $soggetto,
            $soggetto instanceof Intervento => $soggetto->strumento,
            default => throw new InvalidArgumentException(
                'Documento: si allega a uno Strumento o a un Intervento (ERD §8.1).'
            ),
        };
    }
}
