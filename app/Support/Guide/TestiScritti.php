<?php

namespace App\Support\Guide;

use Illuminate\Support\Str;

/**
 * I testi **scritti** delle guide: quelli che si leggono in pagina, e che non
 * sono le didascalie del video.
 *
 * ## Perché due testi e non uno
 *
 * Fino al 7 Set 2026 la pagina mostrava la didascalia del video, ed era una
 * scelta dichiarata: una sola sorgente non può divergere. Ma i due mezzi
 * chiedono cose diverse. Una didascalia deve stare **sotto un fotogramma per
 * quattro secondi** mentre chi guarda vede già il gesto, quindi è breve per
 * necessità e si appoggia all'immagine. Un passo scritto viene letto **senza
 * l'immagine accanto**, spesso da chi cerca una risposta e non ha voglia di
 * guardare due minuti di video: lì la stessa riga è troppo poco.
 *
 * In pagina convivono: la didascalia fa da **riga breve e cliccabile** — il
 * titolo del passo, che porta al suo istante nel video — e il testo scritto sta
 * sotto, come approfondimento. Chi scorre l'indice legge solo le righe brevi;
 * chi si ferma su un passo trova il resto.
 *
 * Resta in comune **la sequenza**: stessi passi, stessa numerazione, stessi
 * secondi.
 *
 * ## Perché in un file a parte e non nel copione
 *
 * Perché il `manifest.json` lo produce **Playwright girando il browser**: un
 * campo in più nel copione vorrebbe dire rigirare la guida e rimontare il video
 * per cambiare una parola di prosa, e rigirare le cinque già fatte solo per
 * aggiungere del testo. Qui la prosa si riscrive e si ridispiega da sola.
 *
 * ⚠️ Il prezzo di quella separazione è che **i due file possono sfasarsi**, ed
 * è ciò che sorveglia `TestiScrittiGuardrailTest`: legge i copioni, conta i
 * passi, e pretende che ogni numero abbia il suo testo e che nessun testo parli
 * di un passo che non esiste.
 *
 * ⚠️ **Un testo mancante non rompe la pagina**: resta la sola riga breve, e il
 * paragrafo non viene stampato affatto. È una degradazione voluta, e proprio
 * perché è silenziosa il guardrail gira in CI.
 */
final class TestiScritti
{
    /**
     * ⚠️ `base_path()` e non il disco delle guide: video e manifest vivono sul
     * bucket, questi testi nel repository. È voluto — correggere un refuso è un
     * deploy, non una ripubblicazione dei filmati.
     *
     * @return array{premessa: string|null, capitoli: array<int, string>, passi: array<int, string>}
     */
    public static function per(string $slug): array
    {
        $file = base_path("guide/testi/{$slug}.md");

        return self::analizza(is_file($file) ? (string) file_get_contents($file) : '');
    }

    /**
     * Tre tipi di blocco, e tutti e tre esistono **solo** nella guida scritta:
     *
     * - `## premessa` — l'apertura della guida: a chi serve e che cosa si porta
     *   a casa. Il video non ce l'ha, perché lì la stessa cosa la fa la testata.
     * - `## capitolo 2` — l'apertura di un capitolo. Nel video il cartello dice
     *   solo il titolo, e per due secondi e mezzo: qui c'è lo spazio per dire
     *   perché quella parte esiste.
     * - `## 7` — l'approfondimento del passo 7.
     *
     * Tutto quel che segue una di quelle righe, fino al `##` successivo, è la
     * sua prosa. Le righe **prima** del primo `##` sono il preambolo del file (a
     * cosa serve, come si scrive) e vengono buttate: è la spiegazione per chi
     * apre il file, e non deve finire in pagina.
     *
     * @return array{premessa: string|null, capitoli: array<int, string>, passi: array<int, string>}
     */
    public static function analizza(string $sorgente): array
    {
        $blocchi = [];
        $corrente = null;

        foreach (preg_split('/\R/', $sorgente) ?: [] as $riga) {
            if (preg_match('/^##\s+(premessa|capitolo\s+(\d+)|(\d+))\s*$/', $riga, $pezzi) === 1) {
                $corrente = match (true) {
                    $pezzi[1] === 'premessa' => 'premessa',
                    ($pezzi[2] ?? '') !== '' => 'capitolo:'.(int) $pezzi[2],
                    default => 'passo:'.(int) $pezzi[3],
                };
                $blocchi[$corrente] ??= '';

                continue;
            }

            if ($corrente !== null) {
                $blocchi[$corrente] .= $riga.' ';
            }
        }

        $blocchi = array_filter(
            array_map(fn (string $testo): string => Str::squish($testo), $blocchi),
            fn (string $testo): bool => $testo !== '',
        );

        $per = function (string $prefisso) use ($blocchi): array {
            $trovati = [];

            foreach ($blocchi as $chiave => $testo) {
                if (str_starts_with($chiave, $prefisso)) {
                    $trovati[(int) substr($chiave, strlen($prefisso))] = $testo;
                }
            }

            ksort($trovati);

            return $trovati;
        };

        return [
            'premessa' => $blocchi['premessa'] ?? null,
            'capitoli' => $per('capitolo:'),
            'passi' => $per('passo:'),
        ];
    }
}
