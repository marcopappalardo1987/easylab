<?php

namespace App\Support\Strumenti;

use App\Enums\TipoMotivoSemaforo;
use App\Models\Garanzia;
use App\Support\DiagnosiSemaforo;
use App\Support\MotivoSemaforo;
use Illuminate\Support\Facades\Gate;

/**
 * Dove sta, nella scheda di una macchina, la riga che causa ciascun motivo del
 * semaforo — e se chi guarda può vederla (🔗 ADR-024 la Panoramica, ADR-029 le
 * garanzie dei pezzi montati, ADR-055 i contatori sui tab).
 *
 * Fino al 10 Ott 2026 queste due risposte stavano scritte dentro la Panoramica.
 * Da quando anche la barra dei tab ne ha bisogno — il numerino accanto a
 * «Interventi» conta gli stessi motivi che la Panoramica elenca — stanno qui,
 * una volta: due copie di una regola di privacy sono due regole, e la seconda
 * a divergere sarebbe quella che mostra un «1» sul tab Ricambi a chi non deve
 * sapere che su quella macchina un pezzo è stato sostituito.
 */
final class MotiviDellaScheda
{
    /**
     * Chi guarda ha titolo a vedere la riga che causa questo motivo?
     *
     * Ogni motivo porta con sé la propria area: chi non ne ha il permesso lo
     * vede in forma neutra — la scadenza sì, il dettaglio e il collegamento no.
     *
     * 🔴 Per la garanzia di un pezzo montato si chiede l'**ability della
     * Policy**, non il permesso nudo: spatie concede appena il permesso esiste
     * sul ruolo, cioè prima che l'impostazione dell'Ente sia letta (ADR-029).
     */
    public static function areaVisibile(MotivoSemaforo $motivo): bool
    {
        return match ($motivo->tipo) {
            TipoMotivoSemaforo::Intervento => Gate::allows('interventi.view'),
            TipoMotivoSemaforo::GaranziaMacchina => Gate::allows('garanzie.macchina.view'),
            TipoMotivoSemaforo::GaranziaRicambio => Gate::allows('view', Garanzia::class),
        };
    }

    /**
     * Il tab in cui sta la riga, o `null` se da qui non ci si arriva.
     *
     * ⚠️ Il permesso da chiedere è quello del **tab di destinazione**, che non
     * è quello che rende visibile il motivo: la garanzia di un pezzo si vede
     * con l'ability di ADR-029, ma il tab Ricambi è gatato su
     * `ricambio_utilizzo.view`. Una porta si mostra solo se dietro c'è una
     * stanza. La garanzia di un pezzo non manda al tab Garanzie: quello mostra
     * le sole righe della macchina, e chi ci arrivasse non troverebbe la riga
     * cercata.
     */
    public static function tab(MotivoSemaforo $motivo): ?string
    {
        return match ($motivo->tipo) {
            TipoMotivoSemaforo::Intervento => 'interventi',
            TipoMotivoSemaforo::GaranziaMacchina => 'garanzie',
            TipoMotivoSemaforo::GaranziaRicambio => Gate::allows('ricambio_utilizzo.view') ? 'ricambi' : null,
        };
    }

    /**
     * Quante scadenze da gestire ci sono dietro ciascun tab: è il numerino che
     * la scheda mette accanto al nome del tab (🔗 ADR-055).
     *
     * Conta gli stessi motivi che la Panoramica elenca, e **solo quelli di cui
     * chi guarda vedrebbe anche la riga**: un motivo reso in forma neutra non
     * accende nessun numero, o il numero direbbe ciò che il testo tace. La
     * somma può quindi essere più bassa di «Motivi (N)», mai più alta.
     *
     * Nessuna query: la diagnosi è già stata calcolata per il semaforo.
     *
     * @return array<string, int> solo i tab che hanno qualcosa
     */
    public static function contaPerTab(DiagnosiSemaforo $diagnosi): array
    {
        $conteggi = [];

        foreach ($diagnosi->motivi as $motivo) {
            $tab = self::areaVisibile($motivo) ? self::tab($motivo) : null;

            if ($tab !== null) {
                $conteggi[$tab] = ($conteggi[$tab] ?? 0) + 1;
            }
        }

        return $conteggi;
    }
}
