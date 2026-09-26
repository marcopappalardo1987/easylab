<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un **avvenimento** dell'error tracker interno, col suo contesto (S6 — 🔗
 * `docs/Architettura/Error Tracker Interno (piano).md`).
 *
 * Risponde a «con quali dati si rompe»: stack trace, percorso, utente,
 * impersonazione, ip, user agent, input. La domanda «quante volte» la risponde
 * invece il contatore su `Errore`, perché queste righe sono un **campione** —
 * al più N per issue, una ogni M secondi (`config('easylab.errori')`).
 *
 * **Modello di PIATTAFORMA: niente `BelongsToTenant`**, come `Errore` e
 * `Account` (ADR-032). `user_id` dice *chi* c'era quando è successo, non *di
 * chi* è la riga: il contesto di un errore appartiene all'applicazione, e la
 * pagina che lo legge è del solo Developer. Esenzione dichiarata in
 * `TenantScopeGuardrailTest::NON_TENANT_MODELS`.
 *
 * **Niente `AuditsDomainWrites`**: è un log append-only scritto da un gestore di
 * eccezioni, non il gesto di una persona — la ragione di `AvvisoScadenza`, e la
 * sua esenzione è in `AuditCoverageGuardrailTest::ESENZIONI`.
 *
 * ⚠️ **Le righe di questa tabella portano dati personali** (stack trace, ip,
 * user agent, input) e la sanificazione avviene **in scrittura**, a monte: ciò
 * che entra qui in chiaro resta in chiaro. È anche la ragione del
 * `cascadeOnDelete`: cancellare una issue deve portare via le sue prove, non
 * lasciarle orfane e irraggiungibili.
 */
class OccorrenzaErrore extends Model
{
    /**
     * ⚠️ **L'alias serve a compilare, come su `Errore`**: `pruneAll()` arriva da
     * un trait usato da questa stessa classe, quindi definirne una versione
     * propria la sostituisce e `parent::pruneAll()` finirebbe su `Model`, che non
     * ce l'ha — `__call()`, query builder, «Call to undefined method». Con
     * l'alias l'implementazione originale resta raggiungibile per nome.
     */
    use Prunable {
        Prunable::pruneAll as private potaDavvero;
    }

    /**
     * Novanta giorni, ed è **l'orizzonte più corto delle due tabelle**.
     *
     * Qui stanno i dati personali (stack trace con i percorsi, ip, user agent,
     * input della richiesta): la riga T8 del registro dei trattamenti dichiara
     * questa cifra, e la minimizzazione vuole che sia la più corta che risponda
     * ancora alla domanda «con quali dati si rompe». Tre mesi sono il tempo in
     * cui un guasto si guarda; oltre, la si legge dal contatore.
     *
     * Coincide con `Errore::GIORNI_CHIUSE` di proposito — vedi là il perché.
     */
    private const GIORNI = 90;

    /** Il default di Eloquent direbbe `occorrenza_errores`. */
    protected $table = 'occorrenze_errore';

    /**
     * Log append-only: si scrive una volta e non si aggiorna mai — come
     * `AvvisoScadenza`. Qui però non c'è nemmeno `created_at`: il momento è
     * `avvenuta_at`, e una seconda colonna che dice la stessa cosa è una seconda
     * colonna che può dirla diversa.
     */
    public $timestamps = false;

    /**
     * `$fillable` e non `$guarded`: vedi la nota su `Errore`. `$guarded = ['id']`
     * **rilassa** il default di Eloquent invece di stringerlo.
     */
    protected $fillable = [
        'errore_id', 'messaggio', 'stack_trace', 'percorso', 'metodo',
        'codice_http', 'user_id', 'impersonato_da', 'ip', 'user_agent',
        'input', 'contesto', 'avvenuta_at',
    ];

    protected function casts(): array
    {
        return [
            'codice_http' => 'integer',
            // Torna l'array già decodificato: la pagina di dettaglio lo scorre,
            // e chi lo scrive lo passa come array.
            'input' => 'array',
            'avvenuta_at' => 'datetime',
        ];
    }

    /**
     * La potatura, sul `model:prune` già schedulato
     * (`App\Support\Retention::MODELLI`).
     *
     * ⚠️ **Il confine è `avvenuta_at`**, che è l'unico momento che questa riga
     * conosce: non c'è `created_at` (`$timestamps = false`), ed è voluto — una
     * seconda colonna che dice la stessa cosa è una seconda colonna che può
     * dirla diversa. L'indice su `avvenuta_at` da sola esiste **per questa
     * query**: la potatura scandisce per data senza `errore_id`, e su questa
     * tabella non c'è nient'altro che cresca così.
     *
     * 🔴 **Nessuna clausola sullo stato della issue, e non è una dimenticanza.**
     * Un'occorrenza di novanta giorni fa se ne va anche se la sua issue è
     * `ignorato`, cioè l'unico stato che non si pota mai: ciò che non va potato
     * è **la riga di `errori`**, perché è lei a tenere il silenzio (toglierla la
     * farebbe rinascere `aperto` al prossimo `firstOrCreate`). Le sue prove non
     * tengono niente, e sono la parte che porta dati personali: conservarle per
     * sempre perché qualcuno ha zittito la issue sarebbe la conservazione
     * illimitata ottenuta con un click.
     *
     * ⚠️ **Il verso opposto non vale**: le prove non sopravvivono mai alla
     * propria issue, e non per questa query — per il `cascadeOnDelete` dello
     * schema, che porta via anche le occorrenze di ieri quando la issue viene
     * potata.
     *
     * `<` e non `<=` come su `Errore`: i confini si rieseguono su Postgres.
     *
     * @return Builder<OccorrenzaErrore>
     */
    public function prunable(): Builder
    {
        return static::query()->where('avvenuta_at', '<', now()->subDays(self::GIORNI));
    }

    /**
     * 🔴 **La potatura, e il BUDGET DEI CONTESTI che si rimette in pari.**
     *
     * ## Il difetto che questo metodo esiste per chiudere (25 Ago 2026)
     *
     * Stava nell'**incrocio** fra il campionamento e la potatura, e nessuno dei
     * due lo copriva perché ciascuno era corretto per conto proprio.
     *
     * `errori.contesti` è un **budget**: `CatturaErrori::daCampionare()` smette
     * di conservare prove quando arriva a `contesti_per_errore`, leggendo quel
     * contatore dalla riga già caricata — zero query sul percorso caldo, che è
     * la ragione per cui la colonna esiste. Ma le due tabelle hanno **orizzonti
     * diversi**: le prove se ne vanno a 90 giorni, le issue aperte a 180. Nel
     * mezzo, una issue `aperto` con `contesti = 20` e ultima occorrenza a 100
     * giorni si ritrovava con **zero prove e il contatore ancora a venti** —
     * cioè `daCampionare()` falso **per sempre**, nemmeno se l'errore
     * ricominciava a succedere mille volte al giorno.
     *
     * ⚠️ **Ed era il caso frequente, non quello raro.** `ultima_occorrenza_at`
     * si rinfresca a ogni avvenimento, quindi una issue che *continua a
     * ripetersi* non viene mai potata: resta viva, dice «venti contesti», e non
     * ne mostra nessuno. È la stessa forma del difetto che la riapertura
     * automatica aveva già risolto azzerando il budget («l'ho corretto, perché
     * succede ancora?»), spostata da «dopo un tentativo di correzione» a «dopo
     * novanta giorni».
     *
     * ## Perché QUI e non in `daCampionare()`
     *
     * L'alternativa era derivare il budget da `occorrenzeErrore()->count()`
     * invece che dal contatore: il contatore non potrebbe più mentire perché non
     * servirebbe più. ⚠️ Ma quella `SELECT count(*)` girerebbe **dentro il
     * gestore delle eccezioni**, su ogni eccezione riportata — comprese le
     * moltissime che la finestra scarta un istante dopo — cioè sul percorso più
     * caldo che il progetto abbia, e che `CatturaErroriTest::costs two queries
     * on the hot path` congela apposta. Qui costa **due query una volta al
     * giorno**, dentro un comando di manutenzione che sta già scandendo queste
     * righe.
     *
     * E soprattutto: non introduce una regola nuova, **ripristina quella già
     * dichiarata**. Il docblock di `Errore` dice che `contesti` è «quante prove
     * se ne sono **conservate**»; era vero solo finché nessuno potava.
     *
     * ⚠️ **Prima la potatura, poi il riallineamento** — l'ordine opposto a
     * quello di `Errore::pruneAll()`, e per la ragione opposta: là
     * l'oscuramento è una misura di privacy che deve avvenire **anche** nella
     * giornata in cui la potatura fallisce; qui il riallineamento non ha senso
     * prima, perché è il conteggio di ciò che **resta**. Se la potatura si
     * rompe a metà, i contatori restano alti — cioè si sbaglia tenendo un budget
     * più stretto del dovuto, che è il verso in cui sbagliare non perde prove.
     */
    public function pruneAll(int $chunkSize = 1000)
    {
        // ⚠️ **Gli id si prendono PRIMA**, o dopo non c'è più niente da cui
        // ricavarli: le righe che dicevano a quali issue appartenevano sono
        // esattamente quelle che stanno per sparire. `distinct()` perché una
        // issue con venti prove scadute è una issue sola.
        $toccate = $this->prunable()->distinct()->pluck('errore_id')->all();

        $potate = $this->potaDavvero($chunkSize);

        // ⚠️ **La scrittura su `errori` sta in `Errore`, e non è stile: è stato
        // `ScrittureErroriGuardrailTest` a bocciare la prima stesura**, che
        // aggiornava i contatori da qui. Aveva ragione — quel guardrail è
        // l'unica rete di queste due tabelle, perché non passano da
        // `VistaPiattaforma`, e un terzo file che scrive su `errori` è
        // esattamente ciò che esiste per fermare. Qui resta il *quando* (chi ha
        // perso prove lo sa solo chi le stava potando), là il *come*.
        Errore::riallineaContesti($toccate);

        return $potate;
    }

    /**
     * La issue di cui questa riga è un campione.
     *
     * @return BelongsTo<Errore, $this>
     */
    public function errore(): BelongsTo
    {
        return $this->belongsTo(Errore::class);
    }
}
