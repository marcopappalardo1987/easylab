<?php

namespace App\Models;

use App\Support\AuditLog;
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
 * lo sia — la scelta va fatta, non dimenticata.
 *
 * ## 🔴 I tre gesti stanno QUI, e non in un service
 *
 * `risolvi()`, `ignora()` e `riapri()` sono metodi **di questo model**, e la
 * ragione è meccanica prima che stilistica: il meta-test dei soggetti di audit
 * (`SoggettiAuditTest::covers every model the codebase can write as a subject`)
 * trova chi scrive audit a mano **leggendo il sorgente dei model**
 * (`str_contains(file_get_contents(app_path('Models/....php')), 'activity(')`).
 * Le stesse tre righe scritte in un `App\Support\Errori\GestisceErrori`
 * sarebbero **invisibili** a quel meta-test: resterebbe verde, `Errore` potrebbe
 * non comparire in `SoggettiAudit::SOGGETTI` e l'etichetta del registro
 * degraderebbe a «Errore · #12» senza che nulla lo dica. È esattamente il buco
 * che `Role` aveva, trovato la settimana scorsa e chiuso a mano.
 *
 * Mettendo la scrittura qui, invece, la rete si arma da sé: togliere `Errore`
 * da `SoggettiAudit::SOGGETTI` rende rosso il meta-test **senza che nessuno
 * debba ricordarsene**.
 *
 * ⚠️ **`forceFill()->save()` e mai `update()` di massa**: `$fillable` elenca
 * solo ciò che il tracker scrive alla *nascita* di una issue, quindi `stato`,
 * `contesti` e i timestamp di chiusura verrebbero **scartati in silenzio** da
 * un'assegnazione di massa — il gesto tornerebbe `void` senza aver cambiato
 * niente, e nessuna eccezione lo direbbe.
 *
 * 🔴 **La riapertura automatica (`CatturaErrori::incrementa()`) non scrive
 * audit**: non è il gesto di una persona, e il registro racconta chi ha fatto
 * cosa. Solo i tre gesti umani lasciano una riga.
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
     * lo stato e i contatori si muovono da metodi espliciti — `risolvi()`,
     * `ignora()`, `riapri()` qui sotto, e `CatturaErrori::incrementa()` per il
     * contatore — non da un array che arriva da chissà dove.
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

    /**
     * «Credo di averlo sistemato».
     *
     * ⚠️ Non è un'affermazione definitiva, ed è la differenza con `ignora()`: una
     * nuova occorrenza **contraddice** questo gesto, e `CatturaErrori::incrementa()`
     * riapre la issue da sé, azzerando anche il budget dei contesti perché la
     * prova che serve — «l'ho corretto, perché succede ancora?» — è proprio quella
     * successiva al tentativo di correzione.
     */
    public function risolvi(User $chi): void
    {
        $this->cambiaStato('risolto', $chi, 'Errore risolto', [
            'risolto_at' => now(),
            'risolto_da' => $chi->getKey(),
        ]);
    }

    /**
     * «So che c'è e non me ne importa».
     *
     * 🔴 **L'unico interruttore di silenzio del tracker, e l'unico stato che non
     * si riapre mai** (`CatturaErrori::incrementa()` riapre solo i `risolto`). Il
     * contatore continua a crescere — la riga dice ancora la verità su quante
     * volte succede — ma la issue resta fuori dall'elenco di default e, dal
     * blocco 7, fuori dalla potatura: potare un `ignorato` lo **resusciterebbe**,
     * perché il `firstOrCreate` del percorso caldo non troverebbe più la riga e
     * ne creerebbe una nuova, `aperto`.
     *
     * ⚠️ Azzera `risolto_at`/`risolto_da`: «ignorato» e «chiuso da qualcuno» sono
     * due fatti diversi, e lasciare il secondo attaccato al primo attribuirebbe a
     * una persona una chiusura che non ha fatto.
     */
    public function ignora(User $chi): void
    {
        $this->cambiaStato('ignorato', $chi, 'Errore ignorato', [
            'risolto_at' => null,
            'risolto_da' => null,
        ]);
    }

    /**
     * «Non è sistemato»: la issue torna in elenco.
     *
     * 🔴 **Azzera anche il budget dei contesti**, ed è la ragione per cui questo
     * metodo non è un semplice `stato = 'aperto'`. La riapertura *automatica* lo
     * fa già (`CatturaErrori::incrementa()`), ma quel ramo scatta **solo** su una
     * issue `risolto`: dopo una riapertura a mano lo stato è `aperto`, quindi la
     * prossima occorrenza non passerebbe di lì e il budget resterebbe al tetto.
     * Una issue riaperta a mano non catturerebbe **mai più** un contesto — cioè
     * proprio la prova per cui la si sta riaprendo.
     *
     * ⚠️ Azzera anche `riaperto_automaticamente_at`: quella colonna significa
     * «l'ultima riapertura è avvenuta da sé», e la pagina la stampa come «riaperto
     * automaticamente il …». Lasciarla dopo un gesto umano farebbe dire alla
     * scheda una cosa falsa proprio sul fatto che distingue una regressione da una
     * decisione.
     */
    public function riapri(User $chi): void
    {
        $this->cambiaStato('aperto', $chi, 'Errore riaperto', [
            'risolto_at' => null,
            'risolto_da' => null,
            'riaperto_automaticamente_at' => null,
            'contesti' => 0,
            'ultimo_contesto_at' => null,
        ]);
    }

    /**
     * Lo stato che cambia, e la **riga nel registro di audit**.
     *
     * ⚠️ **`forceFill()` e non `update()`**: `stato`, `contesti` e i timestamp di
     * chiusura sono fuori da `$fillable`, quindi un `update()` di massa li
     * scarterebbe **in silenzio** — il metodo tornerebbe senza aver cambiato
     * niente e senza che nulla lo dica.
     *
     * 🔴 **L'etichetta del soggetto è `classe`, MAI `messaggio`**, e la scelta è
     * un confine di privacy, non una preferenza. Il registro di audit si legge con
     * `tenants.view_all` — cioè dal **Superadmin**, che `system.logs.view` non ce
     * l'ha e la pagina degli errori non la può nemmeno aprire. Mettere il
     * messaggio nell'etichetta (`SoggettiAudit::SOGGETTI`) o qui dentro nelle
     * `properties` lo farebbe **filtrare attraverso il gate più stretto del
     * progetto**: i messaggi sono interpolati e portano dati di richiesta reali
     * («Utente 42 non trovato», un identificativo, un indirizzo). Le `properties`
     * portano quindi la sola `classe`, che è anche ciò che tiene la riga leggibile
     * il giorno in cui la retention (blocco 7) si porta via il soggetto e
     * l'etichetta degrada a «Errore · #12».
     *
     * ⚠️ **Niente `->event()`**: la riga è un **atto**, non il diff delle colonne
     * di un model, ed è così che resta raggiungibile dal filtro del registro con
     * la sentinella `__atto` (`RegistroAudit::ATTI`). Un `->event('updated')`
     * scritto per abitudine la farebbe scivolare fra le modifiche di dominio e
     * sparire da lì.
     *
     * `properties.impersonato_da` arriva gratis dall'hook di `AppServiceProvider`:
     * non si scrive qui, o sarebbero due fonti per lo stesso fatto.
     *
     * @param  array<string, mixed>  $altre
     */
    private function cambiaStato(string $a, User $chi, string $descrizione, array $altre): void
    {
        $da = $this->stato;

        $this->forceFill(array_merge($altre, ['stato' => $a]))->save();

        activity(AuditLog::NAME)
            ->causedBy($chi)
            ->performedOn($this)
            ->withProperties([
                // Il vocabolario del registro: `DettaglioAttivita` rende `da`/`a`
                // come elenco chiave/valore, come per la matrice dei permessi.
                'classe' => $this->classe,
                'da' => $da,
                'a' => $a,
            ])
            ->log($descrizione);
    }
}
