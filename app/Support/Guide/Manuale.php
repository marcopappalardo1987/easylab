<?php

namespace App\Support\Guide;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Il manuale in applicazione: legge le guide prodotte dallo studio (`guide/`).
 *
 * 🔗 `config/guide.php` per il raggruppamento, `guide/CATALOGO.md` per la
 * roadmap, `guide/STILE.md` per come si produce una guida.
 *
 * **Una sola sorgente per la sequenza, due testi per due mezzi.** Il
 * `manifest.json` che Remotion usa per montare il video detta i passi, la loro
 * numerazione e i loro secondi, e dà la **riga breve** (`testo`) su cui si
 * clicca per saltare nel video. L'**approfondimento** (`dettaglio`) no: quello
 * si legge da `guide/testi/{slug}.md` (🔗 `TestiScritti`), perché una didascalia
 * che sta sotto un fotogramma per quattro secondi e un passo che si legge senza
 * l'immagine accanto non sono la stessa cosa. Manca? Resta la sola riga breve,
 * e il guardrail lo segnala.
 *
 * Il manifest porta già `inizio` per ogni passo (glielo scrive il montaggio, che
 * è l'unico a conoscere la durata della testata): è ciò che permette di saltare
 * nel punto giusto del video cliccando un passo scritto.
 *
 * ⚠️ **Una guida esiste solo se il suo mp4 è stato pubblicato** sul disco
 * (`easylab:pubblica-guide`). Una voce in `config/guide.php` senza i file viene
 * ignorata in silenzio: meglio un indice più corto che una riga che porta a un
 * lettore vuoto.
 *
 * ⚠️ I file NON stanno in `public/`: staging e produzione girano su Laravel
 * Cloud, che costruisce l'immagine da git, e gli mp4 in git non ci vanno.
 * Vedi `config/guide.php` per il disco.
 */
final class Manuale
{
    /**
     * ⚠️ **La versione nella chiave si alza a ogni cambio di FORMA del dato**,
     * non solo del tipo: senza, le voci già scritte restano a far cadere la
     * pagina fino alla scadenza, che è un'ora.
     *
     * - **v2** — v1 metteva in cache una `Collection`, che torna
     *   `__PHP_Incomplete_Class` (vedi sotto).
     * - **v3** — i passi hanno guadagnato `dettaglio`, le guide e i capitoli
     *   `premessa` (🔗 ADR-043). 🔴 **La v3 è arrivata dopo il 500**: il deploy
     *   del 7 Set 2026 è uscito con la chiave ferma a v2, quindi staging ha
     *   riletto le voci della forma vecchia e la vista è morta su
     *   `Undefined array key "premessa"` — con la suite verde, perché in
     *   `testing` questo ramo non gira mai. Alzarla è **metà** del rimedio: la
     *   vista ora legge le tre chiavi nuove col `??`, così il prossimo cambio
     *   di forma costa un paragrafo mancante per un'ora e non il manuale
     *   intero.
     */
    private const CHIAVE = 'guide.manuale.v3';

    /** @return Collection<int, array<string, mixed>> */
    public static function tutte(): Collection
    {
        // In locale niente cache: si gira una guida e si ricarica la pagina.
        // Nei test nemmeno, e non è una comodità: un test che sposta
        // `config('guide.guide')` leggerebbe l'elenco messo in cache dal test
        // precedente, cioè misurerebbe la cache invece della regola.
        //
        // ⛔ **In cache va un array puro, mai la Collection.**
        // `config/cache.php` porta `'serializable_classes' => false`, cioè il
        // framework si RIFIUTA di deserializzare qualunque classe letta dalla
        // cache — è la difesa dai gadget chain se `APP_KEY` trapela. Un oggetto
        // messo lì dentro torna come `__PHP_Incomplete_Class`, e il tipo di
        // ritorno di questo metodo esplode in un TypeError.
        //
        // Non è un'ipotesi: la prima versione metteva in cache la Collection e
        // staging è andato in 500 appena deployato, mentre in locale e nei test
        // tutto era verde — perché lì il ramo con la cache non viene mai
        // eseguito. `SerializzabilitaGuidaTest` ora percorre quel ramo davvero.
        $voci = app()->environment(['local', 'testing'])
            ? self::leggi()->all()
            : Cache::remember(self::CHIAVE, now()->addHour(), fn () => self::leggi()->all());

        return collect($voci);
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

    /** Il disco su cui vivono i file delle guide. */
    public static function disco(): Filesystem
    {
        return Storage::disk(config('guide.disco'));
    }

    /**
     * La chiave di un pezzo di guida sul disco.
     *
     * `video`, `copertina` e `manifest` invece dei nomi dei file: chi serve i
     * byte non deve sapere come si chiamano, e il giorno in cui il montaggio
     * cambia estensione si tocca solo qui.
     */
    public static function percorso(string $slug, string $pezzo): string
    {
        $nome = match ($pezzo) {
            'video' => "{$slug}.mp4",
            'copertina' => 'copertina.jpg',
            'manifest' => 'manifest.json',
        };

        return trim(config('guide.prefisso'), '/')."/{$slug}/{$nome}";
    }

    /** @return array<string, mixed>|null */
    private static function manifest(string $slug): ?array
    {
        $disco = self::disco();

        // Il video è la condizione: un manifest da solo darebbe un lettore vuoto.
        if (! $disco->exists(self::percorso($slug, 'video'))
            || ! $disco->exists(self::percorso($slug, 'manifest'))) {
            return null;
        }

        $letto = json_decode((string) $disco->get(self::percorso($slug, 'manifest')), true);

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
        $testi = TestiScritti::per($voce['slug']);

        // ⚠️ Contatore suo e non `count($capitoli)`: la sezione senza titolo
        // creata più sotto occuperebbe il numero 1, e `## capitolo 1` nei testi
        // finirebbe sul primo capitolo VERO scalato di uno.
        $nCapitolo = 0;

        foreach ($manifest['passi'] ?? [] as $passo) {
            if (($passo['chiusura'] ?? null) !== null) {
                $chiusura = $passo['chiusura'] + ['inizio' => $passo['inizio'] ?? 0];

                continue;
            }

            if (($passo['capitolo'] ?? null) !== null) {
                $capitoli[] = [
                    'occhiello' => $passo['capitolo']['occhiello'],
                    'titolo' => $passo['capitolo']['titolo'],
                    // Apertura del capitolo: esiste solo nella guida scritta,
                    // e si numera nell'ordine in cui i cartelli compaiono.
                    'premessa' => $testi['capitoli'][++$nCapitolo] ?? null,
                    'inizio' => $passo['inizio'] ?? 0,
                    'passi' => [],
                ];

                continue;
            }

            // Uno scatto prima di qualunque cartello finisce in una sezione
            // senza titolo: il manifest non obbliga a un capitolo iniziale.
            if ($capitoli === []) {
                $capitoli[] = ['occhiello' => null, 'titolo' => null, 'premessa' => null, 'inizio' => 0, 'passi' => []];
            }

            $capitoli[array_key_last($capitoli)]['passi'][] = [
                'n' => $passo['n'],
                // La riga breve resta la didascalia del video: è il titolo del
                // passo, ed è ciò su cui si clicca per saltare al suo istante.
                'testo' => $passo['didascalia'],
                // L'approfondimento è ciò che la guida scritta ha in più, e può
                // mancare: la pagina in quel caso mostra la sola riga breve.
                'dettaglio' => $testi['passi'][$passo['n']] ?? null,
                'inizio' => $passo['inizio'] ?? 0,
            ];
        }

        $guida = [
            'slug' => $voce['slug'],
            'titolo' => $manifest['titolo'] ?? $voce['slug'],
            'sottotitolo' => $manifest['sottotitolo'] ?? '',
            'premessa' => $testi['premessa'],
            'argomento' => $voce['argomento'],
            'argomentoTitolo' => $argomento['titolo'] ?? $voce['argomento'],
            'durata' => (int) round($manifest['durataTotale'] ?? 0),
            // I byte passano dall'applicazione, non da una URL del bucket:
            // ADR-026 rifiuta le URL pre-firmate, che sono di fatto un bearer
            // token con la Policy fuori dal giro.
            'video' => route('guida.file', ['slug' => $voce['slug'], 'pezzo' => 'video']),
            // La copertina è facoltativa: senza, il lettore mostra il fotogramma
            // zero. Un `poster` che punta a un file assente sarebbe peggio —
            // alcuni browser lasciano il riquadro bianco invece di ripiegare.
            'copertina' => self::disco()->exists(self::percorso($voce['slug'], 'copertina'))
                ? route('guida.file', ['slug' => $voce['slug'], 'pezzo' => 'copertina'])
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
            (string) $testi['premessa'],
            collect($capitoli)->map(fn (array $c) => implode(' ', [
                (string) $c['titolo'],
                (string) $c['premessa'],
                collect($c['passi'])->pluck('testo')->implode(' '),
                // ⚠️ Anche l'approfondimento, o cercare una parola che sta SOLO
                // nel testo scritto non troverebbe la guida che la spiega.
                collect($c['passi'])->pluck('dettaglio')->implode(' '),
            ]))->implode(' '),
            implode(' ', $chiusura['punti'] ?? []),
        ]));

        return $guida;
    }

    private static function normalizza(string $testo): string
    {
        return Str::squish(Str::lower(Str::ascii($testo)));
    }
}
