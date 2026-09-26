<?php

namespace App\Http\Controllers;

use App\Support\Guide\Manuale;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * I byte di una guida: il video e la sua copertina.
 *
 * ⚠️ **Mediati dall'applicazione, non serviti con una URL pre-firmata.**
 * ADR-026 ha già deciso questo per i documenti — una URL firmata è di fatto un
 * bearer token: chi ce l'ha legge fino alla scadenza, con l'autorizzazione
 * fuori dal giro, e lega il prodotto al provider. Le guide non contengono dati
 * di nessun cliente e la tentazione di fare un'eccezione era forte; non si fa,
 * perché l'eccezione varrebbe come precedente e la prossima volta il file
 * sarebbe un documento.
 *
 * 🔴 **Il supporto Range non è un lusso, è la funzione della pagina.** Cliccare
 * un passo scritto sposta il video a quell'istante: senza `206 Partial
 * Content` il browser non può cercare, e o riscarica tutto o si rifiuta di
 * muoversi. È la ragione per cui questo controller esiste invece di un
 * `Storage::response()` in due righe.
 *
 * Il prezzo è che i byte passano dalla compute dell'applicazione. Con un
 * manuale interno va bene; se un giorno le guide si aprissero a tutti i
 * clienti, la strada è una CDN davanti a questa rotta, non l'URL firmata.
 */
class ServeFileGuida extends Controller
{
    private const TIPI = ['video' => 'video/mp4', 'copertina' => 'image/jpeg'];

    public function __invoke(Request $request, string $slug, string $pezzo): StreamedResponse
    {
        abort_unless(isset(self::TIPI[$pezzo]), 404);
        abort_if(Manuale::trova($slug) === null, 404);

        $disco = Manuale::disco();
        $percorso = Manuale::percorso($slug, $pezzo);

        abort_unless($disco->exists($percorso), 404);

        $dimensione = (int) $disco->size($percorso);
        [$inizio, $fine] = $this->intervallo($request->header('Range'), $dimensione);

        $intestazioni = [
            'Content-Type' => self::TIPI[$pezzo],
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) ($fine - $inizio + 1),
            // `private`: la risposta dipende da chi la chiede, e un proxy
            // condiviso non deve poterla riusare per un altro.
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($inizio > 0 || $fine < $dimensione - 1) {
            $intestazioni['Content-Range'] = "bytes {$inizio}-{$fine}/{$dimensione}";
        }

        return response()->stream(
            fn () => $this->riversa($disco, $percorso, $inizio, $fine),
            ($inizio > 0 || $fine < $dimensione - 1) ? 206 : 200,
            $intestazioni,
        );
    }

    /**
     * L'intervallo richiesto, ridotto ai limiti del file.
     *
     * Si accetta la sola forma `bytes=inizio-fine` con un intervallo: le forme
     * multiple (`bytes=0-99,200-299`) sono legali ma nessun lettore video le
     * usa, e rispondere l'intero file è una risposta corretta a un Range che
     * non si sa soddisfare.
     *
     * @return array{0: int, 1: int}
     */
    private function intervallo(?string $range, int $dimensione): array
    {
        if ($range === null || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $pezzi)) {
            return [0, max(0, $dimensione - 1)];
        }

        // `bytes=-500` sono gli ULTIMI 500 byte, non i primi: invertirlo
        // manderebbe il lettore a leggere l'inizio credendo di avere la coda.
        if ($pezzi[1] === '') {
            $lunghezza = min((int) $pezzi[2], $dimensione);

            return [max(0, $dimensione - $lunghezza), $dimensione - 1];
        }

        $inizio = min((int) $pezzi[1], max(0, $dimensione - 1));
        $fine = $pezzi[2] === '' ? $dimensione - 1 : min((int) $pezzi[2], $dimensione - 1);

        return [$inizio, max($inizio, $fine)];
    }

    /**
     * Riversa l'intervallo, a blocchi.
     *
     * ⚠️ `fseek` e non una lettura da capo: su un file da 18 MB, saltare al
     * minuto giusto leggendo e buttando via tutto quel che precede farebbe
     * pagare al server ogni spostamento del cursore. Sui dischi remoti lo
     * stream di Flysystem è ricercabile perché Guzzle lo bufferizza; dove non
     * lo fosse, `fread` in avanti resta l'unica via e il ramo lo dice.
     */
    private function riversa(mixed $disco, string $percorso, int $inizio, int $fine): void
    {
        $flusso = $disco->readStream($percorso);

        if ($inizio > 0 && @fseek($flusso, $inizio) !== 0) {
            $daSaltare = $inizio;
            while ($daSaltare > 0 && ! feof($flusso)) {
                $daSaltare -= strlen((string) fread($flusso, min(1_048_576, $daSaltare)));
            }
        }

        $restanti = $fine - $inizio + 1;

        while ($restanti > 0 && ! feof($flusso)) {
            $blocco = (string) fread($flusso, min(1_048_576, $restanti));

            if ($blocco === '') {
                break;
            }

            echo $blocco;
            flush();
            $restanti -= strlen($blocco);
        }

        fclose($flusso);
    }
}
