<?php

namespace App\Support;

use App\Models\AvvisoScadenza;
use App\Models\Errore;
use App\Models\OccorrenzaErrore;
use App\Models\Registrazione;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 L'elenco dei modelli che vengono **davvero** potati (S6 — 🔗
 * `docs/Architettura/Error Tracker Interno (piano).md`, blocco 7; Privacy §3,
 * righe T4 e T8).
 *
 * ## Perché una costante e non una lista scritta nello scheduler
 *
 * Perché il difetto da evitare ha già un nome in questo progetto: **T6**.
 * `config/activitylog.php` dichiara `clean_after_days => 365`, ma
 * `activitylog:clean` **non è schedulato**: quella retention è **inerte**, cioè
 * scritta e mai avvenuta, e il registro dei trattamenti ha dovuto dichiararlo.
 * Il piano S1 dell'error tracker rifaceva la stessa forma — un comando
 * `errors:prune` nuovo, «da schedulare al deploy» — e sarebbe stato lo stesso
 * difetto due volte.
 *
 * Con la costante il legame si può **verificare**, ed è ciò che fanno i due
 * meta-test di `tests/Feature/RetentionTest.php`:
 *
 * 1. ogni modello di `app/Models` che usa `Prunable` è **qui dentro** — una
 *    retention dichiarata su un model e dimenticata nello scheduler è rossa;
 * 2. lo scheduler **nomina davvero** questi modelli, letto da
 *    `app(Schedule::class)->events()` — senza il secondo, il primo proverebbe
 *    soltanto che una lista è coerente con sé stessa, che è T6 in persona.
 *
 * ⚠️ **Nessun comando nuovo.** La potatura passa dal `model:prune` di Laravel,
 * che è **già schedulato** in `routes/console.php` per `AvvisoScadenza`: i due
 * modelli dell'error tracker si aggiungono a quella riga invece di aprirne una
 * seconda da ricordarsi.
 *
 * ## L'ordine dell'elenco conta poco, ma non è casuale
 *
 * `Errore` prima di `OccorrenzaErrore`: potando prima le issue, il
 * `cascadeOnDelete` si porta via le loro occorrenze e la passata successiva ne
 * trova meno. È un risparmio, non una correttezza — a parti invertite il
 * risultato finale è identico.
 */
final class Retention
{
    /**
     * I modelli passati a `model:prune`, in `routes/console.php`.
     *
     * @var list<class-string<Model>>
     */
    public const MODELLI = [
        AvvisoScadenza::class,
        Errore::class,
        OccorrenzaErrore::class,
        // 🔴 Le registrazioni pubbliche **mai completate** (ADR-012, il
        // self-signup). È la prima riga di questo elenco che contiene un
        // **segreto**: `registrazioni.password_hash` è la password scelta al
        // modulo, parcheggiata fra il form e il ritorno da Stripe. Chi non
        // arriva mai al pagamento non è un cliente, e non deve diventare uno
        // storico: la potatura è ciò che rende vera la riga del registro dei
        // trattamenti. `Registrazione::prunable()` nomina la condizione che
        // deve valere per cancellare — pendente **e** vecchia — e le completate
        // restano, senza segreto (l'hash è azzerato nella transazione di
        // completamento) e con l'unica risposta a «da dove è entrato questo
        // contratto?».
        Registrazione::class,
    ];

    /**
     * ⚠️ **Ciò che una retention ce l'ha scritta ma NON applicata**, con la sua
     * ragione. È il contrario di un elenco di esenzioni: non dice «qui non
     * serve», dice «qui manca, e si sa».
     *
     * `Activity` (registro di audit, `activity_log`) porta
     * `clean_after_days => 365` in `config/activitylog.php`, ma
     * `activitylog:clean` non è schedulato: **T6 è aperta col legale** e la
     * decisione su quanto si conserva un registro di audit non è una scelta
     * tecnica da prendere di passaggio. Finché non è presa, la tabella cresce, e
     * la Privacy §3 lo dichiara.
     *
     * 🔴 **Questa riga è per chi legge, non per il meta-test: non è
     * falsificabile da lui.** Il primo meta-test globba `app/Models/*.php`, e
     * `Activity` vive in `vendor/spatie` — quel glob non la vede e non la
     * vedrebbe nemmeno se domani la si rendesse `Prunable` senza schedularla.
     * L'unica cosa che un test può ancora dire di questa costante è che non si
     * contraddica con `MODELLI`, ed è ciò che fa: se un giorno `Activity`
     * entrasse davvero nella potatura, questa riga andrebbe tolta — e il test
     * lo pretende.
     *
     * @var list<class-string<Model>>
     */
    public const RETENTION_NON_APPLICATA = [
        Activity::class,
    ];
}
