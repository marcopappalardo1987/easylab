<?php

namespace App\Support\Interventi;

use App\Models\Intervento;
use App\Models\Strumento;
use App\Support\Semaforo;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * I quattro riquadri in cima allo Scadenzario, quello dell'Ente
 * (`App\Livewire\Interventi\Scadenzario`) e quello del parco
 * (`App\Livewire\Piattaforma\ParcoScadenzario`): **una regola sola per le due
 * pagine** (ERD §5.2 — 🔗 ADR-005/011/037/052).
 *
 * ## Tre finestre di tempo e lo stato della macchina
 *
 * - «Scaduti» e «In scadenza» sono le stesse due domande del digest (ADR-011):
 *   scaduto-non-fatto ed entro la soglia «imminente» di ADR-005.
 * - «Idonei» è la terza finestra di prima, «Oltre» (oltre la soglia), **meno**
 *   gli interventi delle macchine non idonee. Chiesto da Marco il 23 Nov 2026:
 *   «Oltre diventa idonei e aggiungere tab non idonei».
 * - «Non idonei» sono gli interventi aperti delle macchine il cui semaforo è
 *   **rosso**, a qualunque distanza di data. Il rosso nasce solo da una
 *   forzatura manuale (ADR-005 punto 5, `semaforo.force`): il motore calcolato
 *   non lo produce mai, quindi il filtro guarda la sola colonna
 *   `forced_state` e non interroga nessuna fonte dell'arancione.
 *
 * Le tre finestre di tempo restano **disgiunte** e coprono tutti gli aperti;
 * «Non idonei» **si sovrappone** a «Scaduti» e «In scadenza», dove una macchina
 * non idonea continua a comparire come prima: lì cambia solo il riquadro che
 * era «Oltre». Quindi la somma dei quattro numeri non è il totale, ed è
 * voluto: un intervento scaduto su una macchina ferma è un problema in due
 * modi, e nasconderlo da uno dei due riquadri vorrebbe dire scegliere per chi
 * guarda quale dei due conta. Nessuna soglia nuova: la quarta voce è lo stato
 * della macchina, non una quarta finestra di tempo.
 *
 * ## Il nome in query string
 *
 * `oltre` è diventato `idonei`, ma un link salvato con `?stato=oltre` deve
 * continuare ad aprire la stessa vista: per questo `SINONIMI` e un solo
 * `normalizza()`, che entrambe le pagine usano in `render()`, in `filtra()` e
 * in `haFiltriAttivi()`. Un valore che non è né una partizione né un sinonimo
 * — compreso un array, che arriva da `?stato[]=…` — non filtra e non si
 * annuncia.
 *
 * ## Perché le macchine non idonee arrivano dal chiamante
 *
 * ⛔ La sottoquery delle macchine **deve partire dallo stesso builder** da cui
 * la pagina prende le macchine leggibili: `Strumento::query()` (scopato su chi
 * guarda) per l'Ente, `ParcoClienti::strumenti()` per il parco. Questa classe
 * non sceglie, perché le due scelte sono incompatibili: lo scope di un Ente
 * sul parco darebbe zero macchine non idonee — tutti «idonei», cioè il
 * difetto peggiore, perché si legge come una buona notizia — e
 * `ParcoClienti::strumenti()` qui non si può chiamare senza la sua porta.
 *
 * ⚠️ «Idonei» si scrive come **`NOT IN` le non idonee** e non come «`IN` le
 * idonee», così le due tile sono complementari per costruzione dentro la
 * finestra «oltre» e la regola del rosso resta scritta una volta sola. È sicuro
 * per due ragioni: `strumenti.id` è la chiave primaria, quindi la sottoquery
 * non può contenere un NULL (che renderebbe vuoto ogni `NOT IN`); e la riga è
 * già stretta dalla `base()` del chiamante alle macchine leggibili dello stesso
 * builder, quindi una macchina rossa che chi guarda vede è sempre dentro la
 * sottoquery.
 *
 * ## Costo
 *
 * Nessuno statement per riga: le due sottoquery vivono dentro la query di
 * pagina e dentro i `count()`. Il quarto riquadro costa **un** `count()` in più
 * per pagina, costante.
 */
final class PartizioniScadenzario
{
    public const SCADUTI = 'scaduti';

    public const IN_SCADENZA = 'in_scadenza';

    public const IDONEI = 'idonei';

    public const NON_IDONEI = 'non_idonei';

    /** Le quattro partizioni, nell'ordine dei riquadri. */
    public const STATI = [self::SCADUTI, self::IN_SCADENZA, self::IDONEI, self::NON_IDONEI];

    /**
     * I nomi di prima, ancora accettati in ingresso: un link salvato o un
     * segnalibro non deve cambiare vista perché è cambiato un nome.
     */
    public const SINONIMI = ['oltre' => self::IDONEI];

    /**
     * La partizione davvero applicata, o nessuna.
     *
     * `mixed` perché il valore arriva dalla query string: `?stato[]=x` è un
     * array, e un `in_array()` su un array non deve diventare un filtro.
     */
    public static function normalizza(mixed $stato): ?string
    {
        if (! is_string($stato)) {
            return null;
        }

        $stato = self::SINONIMI[$stato] ?? $stato;

        return in_array($stato, self::STATI, true) ? $stato : null;
    }

    /**
     * Applica una delle quattro partizioni, o nessuna.
     *
     * 🔴 **Nessuna regola è riscritta qui.** «Scaduto-non-fatto» è
     * `Intervento::scopeScadute()`, gemello SQL di `isScaduto()`; il confine
     * «imminente» arriva da `scopeApertiEntroSoglia()`, cioè da
     * `Semaforo::giorniImminente()`, mai da un 30 scritto a mano; il rosso è la
     * sottoquery che passa il chiamante.
     *
     * ⚠️ Le date si calcolano **una volta** e valgono per tutto il metodo:
     * `scadute()` taglia a `< oggi` e «in scadenza» riprende da `>= oggi`,
     * quindi un intervento che scade OGGI sta fra i non scaduti, che è la regola
     * di `isScaduto()`.
     *
     * ⛔ Il confine superiore si esprime SEMPRE come `>= oggi+soglia+1` e mai
     * come `> oggi+soglia`: su SQLite le colonne `date` sono stringhe
     * `'Y-m-d 00:00:00'` e il confronto è lessicografico, quindi
     * `'2026-08-31 00:00:00' > '2026-08-31'` è vero mentre su Postgres è falso.
     * Col giorno successivo i due driver dicono la stessa cosa. Le date si
     * passano come stringa (`->toDateString()`), mai come Carbon.
     *
     * @param  Builder<Intervento>  $query  la `base()` della pagina, già nel perimetro di chi guarda
     * @param  Closure(): Builder<Strumento>  $nonIdonee  gli id (`select('strumenti.id')`) delle macchine non idonee, dallo STESSO builder di macchine della `base()`
     * @return Builder<Intervento>
     */
    public static function applica(Builder $query, mixed $stato, Closure $nonIdonee): Builder
    {
        $oggi = today()->toDateString();
        $primoOltre = today()->addDays(Semaforo::giorniImminente() + 1)->toDateString();

        return match (self::normalizza($stato)) {
            self::SCADUTI => $query->scadute(),
            self::IN_SCADENZA => $query->apertiEntroSoglia()->where('interventi.data_scadenza', '>=', $oggi),
            self::IDONEI => $query->where('interventi.data_scadenza', '>=', $primoOltre)
                ->whereNotIn('interventi.strumento_id', $nonIdonee()),
            self::NON_IDONEI => $query->whereIn('interventi.strumento_id', $nonIdonee()),
            default => $query,
        };
    }

    /**
     * I quattro numeri dei riquadri, uno per partizione.
     *
     * Portano i filtri dell'elenco ma **non** la partizione selezionata:
     * applicandola anche a loro, tre tile su quattro direbbero zero appena se ne
     * clicca una, e non si potrebbe più passare dall'una all'altra leggendo i
     * numeri. Partono dalla **stessa** `base()` delle righe — una nuova a ogni
     * conteggio, perché un builder di Eloquent si modifica sul posto — quindi
     * non possono divergere da ciò che l'elenco mostra.
     *
     * @param  Closure(): Builder<Intervento>  $base
     * @param  Closure(): Builder<Strumento>  $nonIdonee
     * @return array<string, int>
     */
    public static function contatori(Closure $base, Closure $nonIdonee): array
    {
        $contatori = [];

        foreach (self::STATI as $stato) {
            $contatori[$stato] = self::applica($base(), $stato, $nonIdonee)->count();
        }

        return $contatori;
    }
}
