<?php

namespace App\Support\Guide;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Il manuale in applicazione: legge le guide prodotte dallo studio (`guide/`).
 *
 * 🔗 `config/guide.php` per il raggruppamento, `guide/CATALOGO.md` per la
 * roadmap, `guide/STILE.md` per come si produce una guida.
 *
 * **Una sola sorgente per due prodotti.** Il `manifest.json` che Remotion usa
 * per montare il video è lo stesso che qui diventa la guida scritta: le
 * didascalie *sono* i passi. Redigere il testo a parte lo farebbe divergere dal
 * video al primo ritocco, e nessuno se ne accorgerebbe.
 *
 * Il manifest porta già `inizio` per ogni passo (glielo scrive il montaggio, che
 * è l'unico a conoscere la durata della testata): è ciò che permette di saltare
 * nel punto giusto del video cliccando un passo scritto.
 *
 * ⚠️ **Una guida esiste solo se il suo mp4 è stato pubblicato.** Una voce in
 * `config/guide.php` senza `public/guide/<slug>/` viene ignorata in silenzio:
 * meglio un indice più corto che una riga che porta a un lettore vuoto.
 */
final class Manuale
{
    private const CHIAVE = 'guide.manuale';

    /** @return Collection<int, array<string, mixed>> */
    public static function tutte(): Collection
    {
        // In locale niente cache: si gira una guida e si ricarica la pagina.
        // Nei test nemmeno, e non è una comodità: un test che sposta
        // `config('guide.guide')` leggerebbe l'elenco messo in cache dal test
        // precedente, cioè misurerebbe la cache invece della regola.
        $carica = fn () => self::leggi();

        return app()->environment(['local', 'testing'])
            ? $carica()
            : Cache::remember(self::CHIAVE, now()->addHour(), $carica);
    }

    public static function dimentica(): void
    {
        Cache::forget(self::CHIAVE);
    }

    /** @return array<string, mixed>|null */
    public static function trova(?string $slug): ?array
    {
        return self::tutte()->firstWhere('slug', $slug);
    }

    /**
     * Ricerca testuale su titolo, sottotitolo, argomento, parole chiave e
     * **testo dei passi**: chi cerca «assegnatario» deve trovare la guida che
     * lo nomina in una didascalia, non solo quelle che lo hanno nel titolo.
     *
     * Confronto senza accenti né maiuscole: «però» e «pero» devono pescare lo
     * stesso, o il filtro sembra rotto a chi scrive senza accento.
     */
    public static function cerca(string $testo): Collection
    {
        $ago = self::normalizza($testo);

        if ($ago === '') {
            return self::tutte();
        }

        return self::tutte()->filter(fn (array $g) => str_contains($g['indice'], $ago))->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function leggi(): Collection
    {
        $argomenti = config('guide.argomenti', []);

        return collect(config('guide.guide', []))
            ->map(function (array $voce) use ($argomenti): ?array {
                $manifest = self::manifest($voce['slug']);

                if ($manifest === null) {
                    return null;
                }

                $argomento = $argomenti[$voce['argomento']] ?? null;

                return self::componi($voce, $manifest, $argomento);
            })
            ->filter()
            ->values();
    }

    /** @return array<string, mixed>|null */
    private static function manifest(string $slug): ?array
    {
        $percorso = public_path("guide/{$slug}/manifest.json");

        if (! File::exists($percorso) || ! File::exists(public_path("guide/{$slug}/{$slug}.mp4"))) {
            return null;
        }

        $letto = json_decode(File::get($percorso), true);

        return is_array($letto) ? $letto : null;
    }

    /**
     * Ripiega la lista piatta dei passi nella struttura del racconto: i cartelli
     * di capitolo aprono una sezione, gli scatti la riempiono, la chiusura sta
     * a parte. È la stessa forma che ha il video, ed è ciò che rende l'indice
     * di una guida uguale ai suoi capitoli.
     *
     * @param  array<string, mixed>  $voce
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>|null  $argomento
     * @return array<string, mixed>
     */
    private static function componi(array $voce, array $manifest, ?array $argomento): array
    {
        $capitoli = [];
        $chiusura = null;

        foreach ($manifest['passi'] ?? [] as $passo) {
            if (($passo['chiusura'] ?? null) !== null) {
                $chiusura = $passo['chiusura'] + ['inizio' => $passo['inizio'] ?? 0];

                continue;
            }

            if (($passo['capitolo'] ?? null) !== null) {
                $capitoli[] = [
                    'occhiello' => $passo['capitolo']['occhiello'],
                    'titolo' => $passo['capitolo']['titolo'],
                    'inizio' => $passo['inizio'] ?? 0,
                    'passi' => [],
                ];

                continue;
            }

            // Uno scatto prima di qualunque cartello finisce in una sezione
            // senza titolo: il manifest non obbliga a un capitolo iniziale.
            if ($capitoli === []) {
                $capitoli[] = ['occhiello' => null, 'titolo' => null, 'inizio' => 0, 'passi' => []];
            }

            $capitoli[array_key_last($capitoli)]['passi'][] = [
                'n' => $passo['n'],
                'testo' => $passo['didascalia'],
                'inizio' => $passo['inizio'] ?? 0,
            ];
        }

        $guida = [
            'slug' => $voce['slug'],
            'titolo' => $manifest['titolo'] ?? $voce['slug'],
            'sottotitolo' => $manifest['sottotitolo'] ?? '',
            'argomento' => $voce['argomento'],
            'argomentoTitolo' => $argomento['titolo'] ?? $voce['argomento'],
            'durata' => (int) round($manifest['durataTotale'] ?? 0),
            'video' => "/guide/{$voce['slug']}/{$voce['slug']}.mp4",
            // La copertina è facoltativa: senza, il lettore mostra il fotogramma
            // zero. Un `poster` che punta a un file assente sarebbe peggio —
            // alcuni browser lasciano il riquadro bianco invece di ripiegare.
            'copertina' => File::exists(public_path("guide/{$voce['slug']}/copertina.jpg"))
                ? "/guide/{$voce['slug']}/copertina.jpg"
                : null,
            'capitoli' => $capitoli,
            'chiusura' => $chiusura,
            'passi' => collect($capitoli)->flatMap(fn (array $c) => $c['passi'])->count(),
        ];

        $guida['indice'] = self::normalizza(implode(' ', [
            $guida['titolo'],
            $guida['sottotitolo'],
            $guida['argomentoTitolo'],
            implode(' ', $voce['chiavi'] ?? []),
            collect($capitoli)->map(fn (array $c) => $c['titolo'].' '.collect($c['passi'])->pluck('testo')->implode(' '))->implode(' '),
            implode(' ', $chiusura['punti'] ?? []),
        ]));

        return $guida;
    }

    private static function normalizza(string $testo): string
    {
        return Str::squish(Str::lower(Str::ascii($testo)));
    }
}
