<?php

namespace App\Support\Garanzie;

use App\Enums\SoggettoGaranzia;
use App\Models\Garanzia;
use App\Models\Scopes\DepartmentScope;
use App\Models\Scopes\GaranziaDepartmentScope;
use App\Models\Scopes\GaranziaRicambioPrivacyScope;
use App\Models\Scopes\TenantScope;
use App\Models\Strumento;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

/**
 * La garanzia della macchina conta dal collaudo, e la scrive questa classe
 * sola (🔗 ADR-065; ERD §6.1; ADR-019 «inizio + durata», ADR-022 la scadenza
 * dichiarata, ADR-059 la garanzia alla nascita).
 *
 * «Data installazione» è diventata «data di collaudo» (23 Nov 2026, decisione
 * di Marco), e l'inizio della garanzia è sparito dai moduli: per
 * `soggetto = macchina` `data_inizio` è sempre `strumenti.data_installazione`.
 * La colonna dello strumento ha tenuto il nome, che è un identificatore.
 *
 * Le strade che scrivono quell'inizio sono quattro, e passano tutte da qui:
 * il modulo «Nuovo strumento» (`Albero`), il tab Garanzie della scheda, il
 * cambio di collaudo (`Strumento::booted()`) e il comando
 * `easylab:allinea-garanzie-collaudo`. Quattro copie della regola sarebbero
 * quattro regole, libere di divergere alla prima modifica — la stessa ragione
 * di `LaboratoriDelResponsabile` e `FornitoriGlobali`.
 *
 * Tre regole da non rompere:
 *
 * - 🔴 **Solo la macchina.** La garanzia di un ricambio montato conta dal
 *   montaggio (`RicambioUtilizzo::fissaMontaggio()`), e un cambio di collaudo
 *   non la tocca: le query qui filtrano `soggetto = macchina`, e
 *   `Garanzia::contaDalCollaudo()` rifiuta l'altro soggetto.
 * - 🔴 **Senza collaudo non si scrive niente.** Una garanzia nuova si rifiuta
 *   (`CollaudoMancante`), e quelle già registrate su una macchina senza
 *   collaudo non si toccano mai: il comando le elenca a parte.
 * - **Tenant esplicito, e nessuno scope di tenancy.** Il riallineamento gira
 *   anche in console, dove i global scope si ritirano
 *   (`CurrentTenant::shouldScope()`), e nel web per chi può modificare la
 *   macchina ma non vedere ogni sua riga: le query dicono `tenant_id` e
 *   `strumento_id`, e tolgono gli scope per nome, così il soft delete resta. Si salva per model e mai by-query, perché la scadenza la
 *   ricalcola l'hook `saving` e l'audit la registra (🔗 nota ADR-015 nel
 *   docblock di `Garanzia`).
 */
final class GaranzieDalCollaudo
{
    public const SENZA_COLLAUDO = 'Per registrare la garanzia della macchina serve prima la data di collaudo: la garanzia conta da lì. Scrivila nella scheda della macchina, con «Modifica».';

    /** Il messaggio del collaudo tolto: va al `required` di `regolaCollaudo()`. */
    public const COLLAUDO_DA_TENERE = 'Questa macchina ha una garanzia che conta dal collaudo: la data si può correggere, non togliere.';

    /**
     * Il giorno da cui conta la garanzia della macchina.
     *
     * @throws CollaudoMancante
     */
    public static function inizio(Strumento $strumento): CarbonInterface
    {
        return $strumento->data_installazione?->copy()->startOfDay()
            ?? throw new CollaudoMancante(self::SENZA_COLLAUDO);
    }

    /**
     * Registra una garanzia della macchina.
     *
     * `$fine` è la durata in mesi (ADR-019) o la scadenza dichiarata
     * (ADR-022): le due forme sono alternative, quindi un parametro solo.
     * Soggetto, Ente e strumento vengono dalla macchina, mai da un payload.
     *
     * @throws CollaudoMancante
     */
    public static function registra(Strumento $strumento, int|CarbonInterface $fine): Garanzia
    {
        $garanzia = new Garanzia([
            'tenant_id' => $strumento->tenant_id,
            'soggetto' => SoggettoGaranzia::Macchina,
            'strumento_id' => $strumento->id,
        ]);

        self::scrivi($garanzia, $strumento, $fine);

        return $garanzia;
    }

    /**
     * Cambia durata o scadenza di una garanzia della macchina, e la fa
     * contare dal collaudo (anche se prima contava da un'altra data).
     *
     * @throws CollaudoMancante
     */
    public static function modifica(Garanzia $garanzia, Strumento $strumento, int|CarbonInterface $fine): void
    {
        if ((int) $garanzia->strumento_id !== (int) $strumento->id) {
            throw new LogicException('GaranzieDalCollaudo: la garanzia non è di questa macchina.');
        }

        self::scrivi($garanzia, $strumento, $fine);
    }

    private static function scrivi(Garanzia $garanzia, Strumento $strumento, int|CarbonInterface $fine): void
    {
        $inizio = self::inizio($strumento);

        is_int($fine) ? $garanzia->fissaDurata($fine) : $garanzia->fissaScadenzaDichiarata($fine);

        // Qui una scadenza che non viene dopo il collaudo è un errore di chi
        // scrive, non un dato storico da lasciare: il modulo la rifiuta con
        // `after:`, e questa è la guardia per chi non passa da lì.
        if (! $garanzia->contaDalCollaudo($inizio)) {
            throw new InvalidArgumentException('Garanzia: la scadenza dichiarata deve venire dopo il collaudo (ADR-022 + ADR-065).');
        }

        $garanzia->save();
    }

    /**
     * Ricalcola le garanzie della macchina dopo un cambio di collaudo, e
     * torna quante ne ha scritte. La chiama `Strumento::booted()`.
     *
     * Scrive solo le righe che non contano già dal collaudo: un salvataggio
     * vuoto lascerebbe una riga di audit che non racconta niente. Una
     * scadenza dichiarata che il nuovo collaudo supera resta com'è (vedi
     * `Garanzia::contaDalCollaudo()`); il modulo della macchina lo rifiuta
     * prima, con `regolaCollaudo()`.
     */
    public static function riallinea(Strumento $strumento): int
    {
        $collaudo = $strumento->data_installazione;

        if ($collaudo === null) {
            return 0;
        }

        return DB::transaction(function () use ($strumento, $collaudo): int {
            $scritte = 0;

            foreach (self::dellaMacchina($strumento)->get() as $garanzia) {
                if (! $garanzia->data_inizio->isSameDay($collaudo) && $garanzia->contaDalCollaudo($collaudo)) {
                    $garanzia->save();
                    $scritte++;
                }
            }

            return $scritte;
        });
    }

    /**
     * Le garanzie vive della macchina di uno strumento, col tenant esplicito.
     *
     * @return Builder<Garanzia>
     */
    public static function dellaMacchina(Strumento $strumento): Builder
    {
        return self::senzaScopeDiTenancy()
            ->where('tenant_id', $strumento->tenant_id)
            ->where('strumento_id', $strumento->id)
            ->where('soggetto', SoggettoGaranzia::Macchina->value)
            ->orderBy('id');
    }

    /**
     * Le garanzie senza gli scope di tenancy e di privacy, **per nome**: resta
     * il soft delete, perché una garanzia cestinata non esiste per nessuna
     * lettura di dominio (`BypassNudiGuardrailTest`). Il confine lo mettono le
     * query, col `tenant_id` esplicito.
     *
     * @return Builder<Garanzia>
     */
    private static function senzaScopeDiTenancy(): Builder
    {
        return Garanzia::withoutGlobalScopes([TenantScope::class, GaranziaDepartmentScope::class, GaranziaRicambioPrivacyScope::class]);
    }

    /**
     * Le garanzie della macchina che non contano dal collaudo: quelle
     * registrate prima del 23 Nov 2026, con un inizio scritto a mano. Le legge
     * il comando, per elencarle e — col via — riallinearle.
     *
     * @return Collection<int, Garanzia>
     */
    public static function disallineate(): Collection
    {
        return self::garanzieDiMacchineVive()
            ->filter(fn (Garanzia $g) => $g->strumento->data_installazione !== null
                && ! $g->data_inizio->isSameDay($g->strumento->data_installazione))
            ->values();
    }

    /**
     * Le garanzie della macchina su macchine senza data di collaudo. Non si
     * toccano mai: si elencano, perché qualcuno scriva il collaudo.
     *
     * @return Collection<int, Garanzia>
     */
    public static function senzaCollaudo(): Collection
    {
        return self::garanzieDiMacchineVive()
            ->filter(fn (Garanzia $g) => $g->strumento->data_installazione === null)
            ->values();
    }

    /**
     * Garanzie vive della macchina, con lo strumento caricato. Restano fuori
     * le macchine cestinate e — per la stessa ragione di `dellaMacchina()` —
     * una garanzia rimasta a un Ente che non è più quello della macchina.
     *
     * Il confronto delle date si fa in PHP e non in SQL: su SQLite le colonne
     * `date` scritte da un `insert()` a blocchi e quelle scritte dal model
     * hanno due forme diverse, e l'uguaglianza fra stringhe le darebbe per
     * diverse (vedi CLAUDE.md, «SQLite e Postgres sulle date»).
     *
     * @return Collection<int, Garanzia>
     */
    private static function garanzieDiMacchineVive(): Collection
    {
        return self::senzaScopeDiTenancy()
            ->where('soggetto', SoggettoGaranzia::Macchina->value)
            ->with(['strumento' => fn ($q) => $q->withoutGlobalScopes([TenantScope::class, DepartmentScope::class])])
            ->orderBy('tenant_id')
            ->orderBy('strumento_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (Garanzia $g) => $g->strumento !== null
                && (int) $g->strumento->tenant_id === (int) $g->tenant_id);
    }

    /**
     * Le regole del campo collaudo quando si modifica una macchina che ha
     * garanzie (🔗 ADR-065). Due cose si dicono nel modulo invece di lasciarle
     * scoprire dopo:
     *
     * - il collaudo **si corregge, non si toglie**: le garanzie resterebbero
     *   con un inizio che non è più di niente. Vale solo se la data c'era —
     *   una macchina storica senza collaudo si modifica come sempre;
     * - il collaudo **viene prima di una scadenza dichiarata**: oltre, quella
     *   garanzia non si potrebbe più far contare da lì.
     *
     * @return list<mixed>
     */
    public static function regolaCollaudo(Strumento $strumento): array
    {
        $garanzie = $strumento->exists ? self::dellaMacchina($strumento)->get() : collect();

        return [
            Rule::requiredIf(fn () => $strumento->data_installazione !== null && $garanzie->isNotEmpty()),
            'nullable',
            'date',
            function (string $attributo, mixed $valore, Closure $fallisce) use ($garanzie): void {
                $limite = $garanzie->pluck('data_scadenza_dichiarata')->filter()->sort()->first();

                if ($limite !== null && strtotime((string) $valore) !== false && $limite->lte(Carbon::parse($valore))) {
                    $fallisce('La garanzia della macchina vale fino al '.$limite->format('d/m/Y').': il collaudo deve venire prima.');
                }
            },
        ];
    }
}
