<?php

namespace App\Console\Commands;

use App\Support\Guide\Manuale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Porta le guide montate (`guide/out/`) sul disco da cui l'applicazione le serve.
 *
 * Vive in console e non in una schermata perché la produzione di una guida è
 * un mestiere da riga di comando: si gira con `guide/bin/gira.sh`, si pubblica
 * di qui. 🔗 `guide/README.md`, `guide/STILE.md`.
 *
 * ⚠️ **Il disco è quello configurato adesso.** In locale (`GUIDE_DISK_DRIVER`
 * assente) scrive in `storage/app/private/guide`; per caricare su staging si
 * lancia con le variabili dell'object store di quell'ambiente. Non c'è un
 * `--ambiente`: sarebbe un modo elegante di caricare sul bucket sbagliato.
 */
class PubblicaGuide extends Command
{
    protected $signature = 'easylab:pubblica-guide
        {--sorgente=guide/out : Cartella prodotta dal montaggio}
        {--solo= : Un solo slug, invece di tutti}';

    protected $description = 'Carica video, copertine e manifest delle guide sul disco `guide`';

    /** Oltre al video: il manifest è obbligatorio, la copertina no. */
    private const PEZZI = ['manifest' => 'manifest.json', 'copertina' => 'copertina.jpg'];

    public function handle(): int
    {
        $sorgente = base_path($this->option('sorgente'));

        if (! File::isDirectory($sorgente)) {
            $this->error("Sorgente assente: {$sorgente}. Lanciare prima guide/bin/gira.sh.");

            return self::FAILURE;
        }

        $disco = Manuale::disco();
        $this->line('Disco: '.config('guide.disco').' · prefisso '.config('guide.prefisso'));

        $caricate = 0;

        foreach (File::directories($sorgente) as $cartella) {
            $slug = basename($cartella);

            if ($this->option('solo') !== null && $this->option('solo') !== $slug) {
                continue;
            }

            $video = "{$cartella}/{$slug}.mp4";

            // Senza mp4 non è una guida: `Manuale` la ignorerebbe comunque, e
            // caricare il solo manifest lascerebbe sul disco un mezzo oggetto.
            if (! File::exists($video) || ! File::exists("{$cartella}/manifest.json")) {
                $this->warn("  {$slug}: salto, manca il video o il manifest.");

                continue;
            }

            // Lo stream evita di tenere in memoria 18 MB per volta.
            $flusso = fopen($video, 'rb');
            $disco->put(Manuale::percorso($slug, 'video'), $flusso);
            fclose($flusso);

            foreach (self::PEZZI as $pezzo => $file) {
                if (File::exists("{$cartella}/{$file}")) {
                    $disco->put(Manuale::percorso($slug, $pezzo), File::get("{$cartella}/{$file}"));
                }
            }

            $this->line(sprintf('  %-18s %s', $slug, $this->leggibile(File::size($video))));
            $caricate++;
        }

        // L'elenco è in cache per un'ora: senza questo, una guida appena
        // caricata non comparirebbe fino alla scadenza.
        Manuale::dimentica();

        $this->info("{$caricate} guide pubblicate.");

        return self::SUCCESS;
    }

    private function leggibile(int $byte): string
    {
        return round($byte / 1_048_576, 1).' MB';
    }
}
