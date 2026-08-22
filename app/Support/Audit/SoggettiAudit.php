<?php

namespace App\Support\Audit;

use App\Models\Account;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Scopes\DepartmentScope;
use App\Models\Scopes\DepartmentThroughStrumentoScope;
use App\Models\Scopes\GaranziaDepartmentScope;
use App\Models\Scopes\GaranziaRicambioPrivacyScope;
use App\Models\Scopes\TenantScope;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 Come si legge il soggetto di una riga di audit **attraverso i tenant**.
 *
 * Il problema che risolve, e che non si vede finché non lo si prova: `subject`
 * punta a undici modelli **scopati**, e chi guarda il registro è tenant-bound
 * come chiunque (ADR-018). Caricare la relazione in modo ingenuo restituisce
 * **null** per i soggetti di ogni altro cliente — cioè la pagina mostrerebbe
 * righe senza soggetto, in silenzio e senza dire perché. È il difetto per cui
 * questa classe esiste.
 *
 * ⚠️ **Gli scope sono cinque, e uno è di privacy.** Oltre a `TenantScope` e
 * `DepartmentScope` ci sono `DepartmentThroughStrumentoScope` (Documento,
 * Intervento, RicambioUtilizzo) e i due di `Garanzia` — fra cui
 * `GaranziaRicambioPrivacyScope`, che **non** è tenancy: è ADR-029, chi può
 * vedere le garanzie dei pezzi. Elencarne tre, come la prima stesura del piano,
 * avrebbe lasciato mute proprio le righe più delicate.
 *
 * **Perché una lista uniforme e non una mappa per classe.** `MorphTo::__call`
 * bufferizza `withoutGlobalScopes` e `replayMacros()` lo rigioca sul builder di
 * **ogni tipo presente in pagina**: una query per tipo, non per riga. Togliere
 * uno scope che un modello non registra è un **no-op**, quindi la stessa lista
 * vale per tutti e non c'è niente da tenere allineato per classe. `withTrashed()`
 * è a sua volta un no-op sui modelli senza soft delete (`User`,
 * `SpostamentoStrumento`), e serve perché un audit che perde il nome di ciò che
 * è stato cancellato è inutile **proprio nel caso che conta**.
 *
 * ⚠️ **Le classi cancellate ucciderebbero la pagina.**
 * `MorphTo::createModelByType()` va in fatal su un `subject_type` la cui classe
 * non esiste più, e `activity_log` è append-only: sopravvive alle proprie classi
 * (`LetturaContaore` è stata cancellata in S3). Una riga orfana basterebbe.
 * L'eager load si limita quindi ai tipi **risolvibili**, e le altre righe si
 * rendono come «soggetto non più disponibile» invece di far cadere tutto.
 */
final class SoggettiAudit
{
    /**
     * Gli scope da togliere, **tutti**, su ogni tipo.
     *
     * @var list<class-string>
     */
    private const SCOPE = [
        TenantScope::class,
        DepartmentScope::class,
        DepartmentThroughStrumentoScope::class,
        GaranziaDepartmentScope::class,
        GaranziaRicambioPrivacyScope::class,
    ];

    /**
     * FQCN → [sostantivo, colonna del nome o `null`].
     *
     * ⚠️ Quattro modelli **non hanno una colonna nome** (`Garanzia`,
     * `Intervento`, `RicambioUtilizzo`, `SpostamentoStrumento`): per quelli il
     * nome si risale allo strumento, o «Modifica intervento» non direbbe su
     * quale macchina — che su un registro di audit è l'informazione.
     *
     * @var array<class-string, array{0: string, 1: ?string}>
     */
    private const SOGGETTI = [
        Account::class => ['Account', 'ragione_sociale'],
        Documento::class => ['Documento', 'nome'],
        Fornitore::class => ['Fornitore', 'ragione_sociale'],
        Garanzia::class => ['Garanzia', null],
        Intervento::class => ['Intervento', null],
        Ricambio::class => ['Ricambio', 'nome'],
        RicambioUtilizzo::class => ['Ricambio montato', null],
        SpostamentoStrumento::class => ['Spostamento', null],
        Strumento::class => ['Strumento', 'nome'],
        UnitaOrganizzativa::class => ['Ente', 'nome'],
        User::class => ['Utente', 'name'],
    ];

    /** Gli scope che questa classe toglie, per il meta-test che li tiene onesti. */
    public static function scope(): array
    {
        return self::SCOPE;
    }

    /** I tipi che questa classe sa nominare, per il filtro della vista. */
    public static function tipi(): array
    {
        return self::SOGGETTI;
    }

    /**
     * Il vincolo da passare a `->with(['subject' => ...])`.
     *
     * Il `morphWith` carica lo strumento **solo** per i quattro tipi che ne
     * hanno bisogno: una relazione in più per quei tipi, niente per gli altri.
     */
    public static function vincolo(): Closure
    {
        // ⚠️ **La relazione annidata va liberata a sua volta.** Gli scope tolti
        // sul `morphTo` valgono per il soggetto, non per ciò che il soggetto
        // carica: `Strumento` porta i propri `TenantScope` e `DepartmentScope`,
        // quindi senza questo vincolo lo strumento di un altro cliente torna
        // `null` e l'etichetta ricade su «#12». È il difetto originale,
        // rientrato dalla finestra un livello più in basso — trovato da un test
        // che lo cercava, non rileggendo il codice.
        $liberoDaScope = fn ($q) => $q->withTrashed()->withoutGlobalScopes(self::SCOPE);

        return function (MorphTo $morphTo) use ($liberoDaScope) {
            $morphTo->morphWith([
                // ⚠️ **La garanzia ha due strade verso la macchina, e una è un
                // doppio salto.** `garanzie.strumento_id` è NULL **sse**
                // `soggetto = ricambio` (invariante di schema, imposto da
                // `verificaSoggetto()`): per quelle righe la macchina si
                // raggiunge via `ricambioUtilizzo` (ADR-020). Senza, l'etichetta
                // ricade su «Garanzia · #1» proprio sulle righe che ADR-029
                // tratta come le più delicate — cioè metà delle garanzie.
                //
                // E **anche l'anello di mezzo va liberato**: `RicambioUtilizzo`
                // registra `TenantScope` e `DepartmentThroughStrumentoScope`,
                // quindi senza il vincolo su di lui il salto si interrompe
                // attraverso i tenant. È lo stesso difetto un livello più in
                // basso, per la terza volta: gli scope tolti su una relazione
                // non valgono per ciò che quella relazione carica.
                Garanzia::class => [
                    'strumento' => $liberoDaScope,
                    'ricambioUtilizzo' => $liberoDaScope,
                    'ricambioUtilizzo.strumento' => $liberoDaScope,
                ],
                Intervento::class => ['strumento' => $liberoDaScope],
                RicambioUtilizzo::class => ['strumento' => $liberoDaScope],
                SpostamentoStrumento::class => ['strumento' => $liberoDaScope],
            ]);

            $morphTo->withTrashed()->withoutGlobalScopes(self::SCOPE);
        };
    }

    /**
     * Le sole righe il cui soggetto si può caricare senza far cadere la pagina.
     *
     * @param  Collection<int,Activity>  $righe
     * @return Collection<int,Activity>
     */
    public static function righeRisolvibili(Collection $righe): Collection
    {
        return $righe->filter(fn (Activity $r) => self::risolvibile($r))->values();
    }

    /**
     * Le righe il cui **causer** si può caricare.
     *
     * Serve solo a evitare l'N+1 della colonna «Chi»: `User` non ha global
     * scope, quindi qui non c'è niente da togliere. Il filtro sulla classe c'è
     * per la stessa ragione dell'altro — `causer_type` oggi è sempre `User`, ma
     * questa tabella sopravvive alle proprie classi.
     *
     * @param  Collection<int,Activity>  $righe
     * @return Collection<int,Activity>
     */
    public static function conCauserRisolvibile(Collection $righe): Collection
    {
        return $righe->filter(
            fn (Activity $r) => $r->causer_type !== null && class_exists($r->causer_type)
        )->values();
    }

    /** La classe del soggetto esiste ancora? */
    private static function risolvibile(Activity $attivita): bool
    {
        return $attivita->subject_type !== null && class_exists($attivita->subject_type);
    }

    public static function etichetta(Activity $attivita): EtichettaSoggetto
    {
        if ($attivita->subject_type === null) {
            return EtichettaSoggetto::assente();
        }

        // ⚠️ **Prima la classe, poi la relazione.** Se il tipo non esiste più,
        // toccare `->subject` farebbe lazy load e `createModelByType()` andrebbe
        // in fatal — cioè il difetto che l'eager load filtrato esiste per
        // evitare, rientrato dalla finestra qui.
        //
        // *Nessun test la rende rossa da sola, ed è dichiarato*: oggi il filtro
        // sull'eager load lascia la relazione non caricata per questi tipi, e il
        // ramo qui sotto produce la stessa etichetta. Le due guardie difendono
        // lo stesso fatto da due lati, e il test lo prende togliendo l'altra.
        // Questa resta perché è quella che regge se un domani qualcuno caricasse
        // i soggetti da un'altra strada.
        if (! self::risolvibile($attivita)) {
            return EtichettaSoggetto::mancante(class_basename($attivita->subject_type), $attivita->subject_id);
        }

        [$sostantivo, $colonna] = self::SOGGETTI[$attivita->subject_type]
            // Un tipo che la mappa non conosce ma la cui classe esiste: un
            // modello nuovo che nessuno ha aggiunto qui. Il meta-test lo prende,
            // ma intanto la pagina non deve tacere.
            ?? [class_basename($attivita->subject_type), null];

        $soggetto = $attivita->relationLoaded('subject') ? $attivita->subject : null;

        if ($soggetto === null) {
            return EtichettaSoggetto::mancante($sostantivo, $attivita->subject_id);
        }

        return EtichettaSoggetto::di(
            $sostantivo,
            self::nome($soggetto, $colonna),
            cestinato: method_exists($soggetto, 'trashed') && $soggetto->trashed(),
        );
    }

    /** Il nome proprio, o quello dello strumento a cui la riga si riferisce. */
    private static function nome(Model $soggetto, ?string $colonna): string
    {
        if ($colonna !== null) {
            // `filled()` e non `??`: una colonna nome **vuota** non è assente, e
            // con il solo `??` la cella renderebbe «Strumento ·», col separatore
            // appeso al nulla.
            return filled($soggetto->{$colonna}) ? (string) $soggetto->{$colonna} : '#'.$soggetto->getKey();
        }

        $strumento = self::strumentoDi($soggetto);

        return filled($strumento?->nome)
            ? '#'.$soggetto->getKey().' · '.$strumento->nome
            : '#'.$soggetto->getKey();
    }

    /**
     * La macchina a cui una riga senza nome si riferisce.
     *
     * Due strade, e la seconda esiste per la sola `Garanzia` sul ricambio, dove
     * `strumento_id` è NULL per invariante di schema.
     */
    private static function strumentoDi(Model $soggetto): ?Model
    {
        if ($soggetto->relationLoaded('strumento') && $soggetto->strumento !== null) {
            return $soggetto->strumento;
        }

        if (! $soggetto->relationLoaded('ricambioUtilizzo')) {
            return null;
        }

        $montaggio = $soggetto->ricambioUtilizzo;

        return $montaggio?->relationLoaded('strumento') ? $montaggio->strumento : null;
    }
}
