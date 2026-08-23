<?php

namespace App\Support\Audit;

use App\Support\ChiaviSensibili;
use Spatie\Activitylog\Models\Activity;

/**
 * Il contenuto della riga espansa del registro di audit (S6 — ERD §9, ADR-027).
 *
 * **Due sorgenti, e vanno tenute distinte perché dicono cose diverse.**
 * `activity_log` ha *due* colonne JSON, e il progetto le usa in modo disgiunto:
 *
 * - `attribute_changes` la scrive **solo** il trait `AuditsDomainWrites`, via
 *   `withChanges()`, nella forma `['attributes' => [...], 'old' => [...]]`: è il
 *   diff di una scrittura di dominio, e si legge come tabellina
 *   *Campo · Prima · Dopo*;
 * - `properties` la scrivono **solo** le diciotto `activity()` esplicite, via
 *   `withProperties()`: `ip`, `guard`, `email`, `motivo`, `da`/`a`,
 *   `cross_tenant`, `destinatario`, `errore`, `tentativi`… sono fatti a sé, non
 *   un prima/dopo, e si leggono come elenco chiave/valore.
 *
 * Fonderle in un elenco solo darebbe righe come «attributes → {…}», cioè JSON
 * grezzo al posto dell'unica informazione che l'espansione esiste per dare.
 *
 * ⚠️ **L'insieme dei campi del diff è l'unione `attributes ∪ old`, non uno dei
 * due.** Non è prudenza teorica: `LogsActivity::buildChanges()` sull'evento
 * `deleted` fa `unset($properties['attributes'])` e lascia solo `old`, e
 * sull'evento `created` non c'è `old` affatto. Iterare un lato solo renderebbe
 * **vuota** ogni riga di eliminazione — cioè proprio quelle per cui si apre un
 * registro.
 *
 * ⚠️ **Le due colonne arrivano in due forme diverse, e `blank()` le copre
 * entrambe.** Il logger inizializza sempre con `withProperties([])`, quindi una
 * riga scritta da lì porta una Collection **vuota**; una riga inserita fuori da
 * lì — una migration di correzione, un fix a mano su una tabella append-only —
 * ha la colonna a NULL, e `collection` è fra i `primitiveCastTypes` di Eloquent,
 * quindi il cast restituisce `null` e non una Collection. `blank()` è vero in
 * entrambi i casi.
 *
 * *Detto per intero perché la prima stesura dichiarava qui una protezione che
 * non esiste.* `blank()` **non** è la guardia contro la scatola vuota: lo è
 * `vuoto()`, che confronta gli array già costruiti. Sostituire `blank()` con
 * `!== null` non rende rosso nessun test, ed è giusto così — i due rami
 * collassano sullo stesso array vuoto. Resta perché dice l'intenzione e perché
 * regge se un domani qualcuno leggesse le due colonne senza passare da qui, non
 * perché protegga qualcosa oggi.
 *
 * ⚠️ **Limite noto, dichiarato e non chiuso.** `Login fallito` scrive
 * `properties.email` con *ciò che è stato digitato nel campo email*: se qualcuno
 * batte la password nel campo sbagliato, la password finisce lì in chiaro e la
 * denylist (🔗 `App\Support\ChiaviSensibili`) non la vede, perché **guarda la
 * chiave, mai il valore**, e la chiave si chiama `email`. Oscurare `email` **non** è il rimedio: svuoterebbe la riga più
 * utile del registro di sicurezza — «chi ha provato a entrare come chi» è
 * l'informazione per cui quella riga si scrive. Si dichiara e si sceglie, non si
 * nasconde.
 */
final class DettaglioAttivita
{
    /**
     * Le chiavi che la **colonna «Chi» dice già, e dice meglio**.
     *
     * `impersonato_da` è il timbro del blocco 0: sta su *ogni* riga scritta
     * durante un'impersonazione, e la tabella lo rende come «per conto di
     * <nome>» spendendo una query apposta per non mostrare un id nudo. Ripeterlo
     * qui darebbe due volte la stessa informazione, la seconda in forma
     * peggiore — e, sulle righe esplicite che non portano altre `properties`,
     * sarebbe l'**unico** contenuto della scatola: l'espansione si offrirebbe
     * per aprirsi su un numero opaco, cioè il caso che `vuoto()` esiste per
     * impedire, rientrato da un'altra porta.
     *
     * Non è un dato nascosto: è un dato che ha già il suo posto, dove si legge.
     */
    private const GIA_IN_TABELLA = ['impersonato_da'];

    /**
     * @param  list<array{chiave: string, valore: string}>  $proprieta
     * @param  list<array{campo: string, prima: string, dopo: string}>  $cambi
     */
    private function __construct(
        public readonly array $proprieta,
        public readonly array $cambi,
    ) {}

    public static function di(Activity $attivita): self
    {
        // `blank()` copre sia la Collection vuota delle righe del logger sia il
        // `null` di quelle inserite a mano (vedi il docblock: non è la guardia
        // contro la scatola vuota, quella è `vuoto()`).
        $proprieta = blank($attivita->properties) ? [] : $attivita->properties->all();
        $cambi = blank($attivita->attribute_changes) ? [] : $attivita->attribute_changes->all();

        return new self(self::elenco($proprieta), self::diff($cambi));
    }

    /** Non c'è niente da mostrare: la riga non deve nemmeno offrire l'espansione. */
    public function vuoto(): bool
    {
        return $this->proprieta === [] && $this->cambi === [];
    }

    /**
     * Le `properties` come coppie chiave/valore, senza quelle oscurate.
     *
     * @param  array<string, mixed>  $grezze
     * @return list<array{chiave: string, valore: string}>
     */
    private static function elenco(array $grezze): array
    {
        $righe = [];

        foreach ($grezze as $chiave => $valore) {
            if (self::daOscurare((string) $chiave) || in_array($chiave, self::GIA_IN_TABELLA, true)) {
                continue;
            }

            $righe[] = ['chiave' => (string) $chiave, 'valore' => self::valore($valore)];
        }

        return $righe;
    }

    /**
     * Il diff come terne *Campo · Prima · Dopo*.
     *
     * @param  array<string, mixed>  $grezzi
     * @return list<array{campo: string, prima: string, dopo: string}>
     */
    private static function diff(array $grezzi): array
    {
        $dopo = is_array($grezzi['attributes'] ?? null) ? $grezzi['attributes'] : [];
        $prima = is_array($grezzi['old'] ?? null) ? $grezzi['old'] : [];

        // L'**unione**, con l'ordine di `attributes` davanti: un campo che sta
        // su un lato solo (creazione, eliminazione, un attributo derivato che
        // prima non esisteva) deve comparire lo stesso, o il diff mente per
        // omissione. `array_unique` sui nomi e non `array_merge` sui valori: qui
        // interessano le **chiavi**, e il merge dei valori perderebbe il lato
        // opposto.
        $campi = array_values(array_unique([...array_keys($dopo), ...array_keys($prima)]));

        $righe = [];

        foreach ($campi as $campo) {
            if (self::daOscurare((string) $campo)) {
                continue;
            }

            $righe[] = [
                'campo' => (string) $campo,
                // `array_key_exists` e non `??`: un campo **assente** e un campo
                // valorizzato a `null` si rendono uguali («—»), ma passare per
                // `??` significherebbe non sapere più che erano due casi.
                'prima' => array_key_exists($campo, $prima) ? self::valore($prima[$campo]) : self::VUOTO,
                'dopo' => array_key_exists($campo, $dopo) ? self::valore($dopo[$campo]) : self::VUOTO,
            ];
        }

        return $righe;
    }

    /** Come si scrive «qui non c'è niente», in tabella. */
    private const VUOTO = '—';

    /**
     * La chiave nomina un segreto?
     *
     * ⚠️ **La voce si toglie del tutto, chiave compresa**, e non si sostituisce
     * con un «(oscurato)». Due ragioni: il nome della chiave è già un indizio su
     * cosa quella riga custodisce, e soprattutto una riga i cui unici dati sono
     * oscurati dev'essere **vuota** — altrimenti l'espansione si offre e si apre
     * su una scatola che dice solo «c'era qualcosa che non ti mostro», che è il
     * caso che `vuoto()` esiste per impedire.
     *
     * L'elenco e il modo di applicarlo vivono in `App\Support\ChiaviSensibili`
     * dal blocco 4 dell'error tracker (S6): da lì la stessa denylist filtra
     * anche `$request->all()` prima che finisca in `occorrenze_errore.input`, e
     * due copie dello stesso elenco divergono alla prima aggiunta. La lista è
     * anche **cresciuta** in quell'occasione — `code`, `recovery_code`,
     * `signature`, `expires` — per ragioni che stanno scritte là.
     */
    private static function daOscurare(string $chiave): bool
    {
        return ChiaviSensibili::nomina($chiave);
    }

    /**
     * Un valore JSON come lo legge una persona.
     *
     * ⚠️ Gli array **si ripuliscono ricorsivamente** prima di essere serializzati:
     * la denylist filtra le chiavi di primo livello, ma un `properties.payload`
     * annidato con dentro un `token` finirebbe altrimenti nel DOM dentro il JSON.
     * Il valore di un segreto non deve uscire dal database per **nessuna** strada
     * — né in una cella, né in un attributo, né in un commento.
     */
    private static function valore(mixed $valore): string
    {
        if ($valore === null) {
            return self::VUOTO;
        }

        if (is_bool($valore)) {
            // `cross_tenant` è il caso che conta: «1» e «» sono illeggibili, e
            // «false» reso come stringa vuota si confonde con «assente».
            return $valore ? 'sì' : 'no';
        }

        if (is_scalar($valore)) {
            return (string) $valore;
        }

        // Oggetti e array: JSON leggibile, senza escape di slash e accenti. Il
        // `?:` copre il ritorno `false` di `json_encode` su un valore non
        // serializzabile — una riga malformata non deve far cadere la pagina.
        return json_encode(
            self::ripulisci($valore),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: self::VUOTO;
    }

    /** Toglie le chiavi oscurate a ogni livello di annidamento (🔗 `ChiaviSensibili`). */
    private static function ripulisci(mixed $valore): mixed
    {
        return ChiaviSensibili::ripulisci($valore);
    }
}
