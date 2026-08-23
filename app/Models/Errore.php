<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una **issue** dell'error tracker interno (S6 — 🔗 `docs/Architettura/Error
 * Tracker Interno (piano).md`; ERD: tabella di piattaforma, fuori dagli Enti).
 *
 * Un errore raggruppato per punto d'origine: `impronta` è la chiave del
 * raggruppamento, `occorrenze` quante volte è successo, `contesti` quante prove
 * se ne sono conservate in `occorrenze_errore`. Le due cifre non sono la stessa
 * cosa e non vanno mai mostrate come se lo fossero.
 *
 * **Modello di PIATTAFORMA: niente `BelongsToTenant`**, come `Account`
 * (ADR-032). Un'eccezione PHP non appartiene a un Ente — nasce anche in console
 * e in coda, dove un tenant corrente non esiste — e scoparla al tenant corrente
 * significherebbe che la pagina del Developer non mostra nulla. L'esenzione è
 * dichiarata in `TenantScopeGuardrailTest::NON_TENANT_MODELS` con la sua
 * ragione; la porta d'accesso non è `VistaPiattaforma`, che qui sarebbe un
 * «bypass finto» (non c'è nessuno scope da togliere).
 *
 * **Niente `AuditsDomainWrites`** (🔗 ADR-027): il trait scriverebbe una riga di
 * audit **a ogni incremento del contatore**, cioè l'audit di un log — la stessa
 * ragione per cui ne è esente `AvvisoScadenza`. L'esenzione è dichiarata in
 * `AuditCoverageGuardrailTest::ESENZIONI`. ⚠️ Non è il ramo del trait a coprire
 * questo modello: senza la voce là dentro il meta-test è rosso, ed è giusto che
 * lo sia — la scelta va fatta, non dimenticata. I **tre gesti** (risolvi,
 * ignora, riapri) lasceranno invece una riga esplicita nel registro, e nascono
 * nel loro blocco: qui non c'è ancora niente che una persona possa fare.
 */
class Errore extends Model
{
    /** Plurale italiano: il default di Eloquent direbbe `errores`. */
    protected $table = 'errori';

    /**
     * ⚠️ **`$fillable` e non `$guarded`, e la prima stesura aveva il verso
     * invertito.** Diceva: «nessun `$fillable`, una lista di campi assegnabili
     * in massa sarebbe una porta aperta». È il contrario: il default di Eloquent
     * è `$guarded = ['*']`, cioè **niente** assegnabile in massa; scrivere
     * `['id']` lo **rilassa** — verificato, `isFillable('stato')` tornava `true`.
     * Una whitelist è più stretta del default, non più larga, ed è ciò che fanno
     * tutti e undici gli altri model di questo progetto.
     *
     * L'elenco è **solo ciò che il tracker scrive alla nascita di una issue**:
     * lo stato e i contatori si muovono da metodi espliciti (blocco 6), non da
     * un array che arriva da chissà dove.
     */
    protected $fillable = [
        'impronta',
        'classe',
        'messaggio',
        'file',
        'riga',
        'prima_occorrenza_at',
        'ultima_occorrenza_at',
    ];

    /**
     * I default in memoria, non solo di colonna: il `default()` della migration
     * si applica all'INSERT, quindi un'istanza appena creata leggerebbe `null`
     * finché qualcuno non la rilegge dal database — ed è la lezione già pagata
     * su `Account::$attributes` (`is_locked`, `piano`, `di_piattaforma`). Qui
     * conta il doppio: il codice che decide se campionare un contesto gira
     * **dentro** il gestore delle eccezioni, e un `null` dove aspetta un intero
     * lancerebbe proprio lì.
     */
    protected $attributes = [
        'stato' => 'aperto',
        'occorrenze' => 1,
        'contesti' => 0,
    ];

    protected function casts(): array
    {
        return [
            'riga' => 'integer',
            'occorrenze' => 'integer',
            'contesti' => 'integer',
            'ultimo_contesto_at' => 'datetime',
            'prima_occorrenza_at' => 'datetime',
            'ultima_occorrenza_at' => 'datetime',
            'riaperto_automaticamente_at' => 'datetime',
            'risolto_at' => 'datetime',
            'alert_inviato_at' => 'datetime',
        ];
    }

    /**
     * I contesti conservati, che sono un **campione** e non tutte le occorrenze.
     *
     * ⚠️ **Il nome non può essere `occorrenze()`**, per quanto sarebbe quello
     * naturale: `occorrenze` è già la colonna-contatore, e su una collisione fra
     * attributo e relazione vince l'attributo — `$errore->occorrenze` tornerebbe
     * l'intero e la relazione sarebbe raggiungibile solo chiamandola come
     * metodo, cioè un tranello permanente per chi legge. Il nome ripete quello
     * della tabella per non lasciare dubbi su quale delle due cifre si sta
     * chiedendo.
     *
     * @return HasMany<OccorrenzaErrore, $this>
     */
    public function occorrenzeErrore(): HasMany
    {
        return $this->hasMany(OccorrenzaErrore::class);
    }

    /**
     * Chi ha chiuso la issue. `nullOnDelete` sulla colonna, quindi la relazione
     * può essere vuota su una riga risolta: la storia dei bug sopravvive a chi
     * l'ha scritta.
     *
     * @return BelongsTo<User, $this>
     */
    public function risoltoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'risolto_da');
    }
}
