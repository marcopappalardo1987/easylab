<?php

namespace App\Support\Piattaforma;

use App\Enums\StatoIntervento;
use App\Enums\TipoIntervento;
use App\Models\Account;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Semaforo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo **scadenzario di tutto il parco clienti** (🔗 ADR-037), in sola lettura.
 *
 * È la stessa domanda di `App\Livewire\Interventi\Scadenzario` — «cosa c'è
 * ancora da fare, e quando scade» — posta però **oltre il proprio Ente**: la
 * ragione per cui il committente ha chiesto questa funzione («mi occupo della
 * strumentazione di molti Enti e voglio vedere la situazione a colpo d'occhio»).
 *
 * ## Nessuna regola nuova, e nessuna riscritta
 *
 * «Scaduto-non-fatto» ha una definizione sola in questo progetto —
 * `Intervento::isScaduto()` e il suo gemello SQL `scopeScadute()`, che un test
 * tiene allineati — e la soglia «imminente» viene da `Semaforo::giorniImminente()`.
 * Qui si **chiamano**, non si ripetono: una seconda copia della regola su una
 * vista cross-cliente vorrebbe dire che il parco e l'Ente possono dire due cose
 * diverse sulla stessa riga, e a scoprirlo sarebbe il cliente.
 *
 * ## Perché una classe e non tre metodi privati nel componente
 *
 * Il confine di questa vista è **la query**, non l'interfaccia: se il `whereIn`
 * del perimetro salta, la pagina non dà errore — mostra di più (ADR-037,
 * «"vuoto" e "tutti" sono un carattere di distanza»). Tenendo la query in una
 * classe di supporto la si può interrogare da un test **sull'SQL** invece che
 * sui dati, che è l'unico modo onesto di provare un tie-break e un confine di
 * date: sui dati un difetto del genere si coglie una volta su tre, ed è un
 * aneddoto, non una rete.
 *
 * ## ⛔ Si LEGGE soltanto
 *
 * Ogni lettore parte da `ParcoClienti`, che è la porta unica di questa funzione:
 * ha il gate su `tenants.view_all` dentro, toglie gli scope **per nome** e
 * riconvalida il perimetro contro l'insieme legittimo degli account. Da qui non
 * si scrive niente — ogni modifica passa dall'impersonazione, che è per cliente
 * e lascia una riga di audit col contesto. `ParcoBypassGuardrailTest` sorveglia
 * che nessuna scrittura venga concatenata alla porta.
 *
 * ⛔ **`ParcoClienti` e mai `VistaPiattaforma`**: i builder della cabina non sono
 * filtrati per cliente — servono a contare tutti i clienti, non a elencare le
 * righe di quelli scelti — e usarli qui mostrerebbe «tutti» mentre il filtro in
 * cima alla pagina dice altro.
 */
final class ScadenzarioParco
{
    /**
     * Le tre partizioni, e sono tre: scaduto, entro la soglia «imminente» di
     * ADR-005, oltre. Le stesse del scadenzario dell'Ente e le stesse due
     * domande del digest (ADR-011) più il resto. Nessuna quarta soglia.
     */
    public const STATI = ['scaduti', 'in_scadenza', 'oltre'];

    /**
     * Gli interventi **aperti** del perimetro, con ricerca e tipo applicati ma
     * senza la partizione.
     *
     * Serve identica alle righe e ai tre contatori: se i due la costruissero
     * per conto proprio, le tile direbbero numeri che l'elenco non conferma.
     *
     * ⛔ **Solo gli interventi di una macchina ancora leggibile.** Cestinare uno
     * strumento non tocca i suoi interventi — nessun hook, nessun observer — e
     * nessuno scope di `Intervento` li nasconde. Su una scheda va bene (lo
     * storico resta leggibile a chi riapre la macchina); qui no: questo è un
     * elenco di cose **da fare**, e una riga così non si può chiudere da
     * nessuna parte e gonfia per sempre un contatore. È la stessa decisione già
     * presa dal digest e dal scadenzario dell'Ente.
     *
     * La sottoquery passa da `ParcoClienti::strumenti()` e non da
     * `Strumento::query()`: quest'ultima è **scopata sul tenant di chi guarda**,
     * quindi su una vista cross-cliente non lascerebbe passare nemmeno una riga
     * — un elenco vuoto che si legge come «non c'è niente da fare».
     *
     * ⚠️ **Colonne sempre qualificate**: le sottoquery sono sulla stessa tabella
     * `strumenti` e su Postgres una colonna nuda diventerebbe ambigua dove
     * SQLite passerebbe.
     *
     * @return Builder<Intervento>
     */
    public static function base(Perimetro $perimetro, string $search = '', ?TipoIntervento $tipo = null): Builder
    {
        $query = ParcoClienti::interventi($perimetro)
            ->where('interventi.stato', StatoIntervento::NonFatto->value)
            ->whereIn('interventi.strumento_id', ParcoClienti::strumenti($perimetro)->select('strumenti.id'));

        if (filled($search)) {
            $like = '%'.self::jolly($search).'%';

            $query->where(function (Builder $q) use ($like, $perimetro) {
                $q->whereRaw("LOWER(interventi.descrizione) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereIn(
                        'interventi.strumento_id',
                        ParcoClienti::strumenti($perimetro)->select('strumenti.id')
                            ->whereRaw("LOWER(strumenti.nome) LIKE ? ESCAPE '\\'", [$like])
                    );
            });
        }

        if ($tipo !== null) {
            $query->where('interventi.tipo', $tipo->value);
        }

        return $query;
    }

    /**
     * Applica una delle tre partizioni, o nessuna.
     *
     * 🔴 **Nessuna delle tre regole è riscritta qui**: `scadute()` è il gemello
     * SQL di `isScaduto()`, `apertiEntroSoglia()` porta con sé
     * `Semaforo::giorniImminente()`. Un 30 scritto a mano qui sarebbe una
     * seconda soglia, e il giorno in cui la prima cambia resterebbe indietro in
     * silenzio.
     *
     * ⚠️ `$oggi` si calcola **una volta** e vale per tutto il metodo:
     * `scadute()` taglia a `< oggi` e `in_scadenza` riprende da `>= oggi`,
     * quindi le due metà non possono divergere di un giorno e un intervento che
     * scade OGGI sta fra i non scaduti — che è la regola di `isScaduto()`.
     *
     * ⛔ Il confine superiore si esprime SEMPRE come `>= oggi+soglia+1` e mai
     * come `> oggi+soglia`: su SQLite le colonne `date` sono stringhe
     * `'Y-m-d 00:00:00'` e il confronto è **lessicografico**, quindi
     * `'2026-09-28 00:00:00' > '2026-09-28'` è vero mentre su Postgres è falso —
     * cioè un intervento al confine cadrebbe in due partizioni diverse a
     * seconda del driver, verde in locale e rosso in CI. Col giorno successivo
     * i due motori dicono la stessa cosa. Le date si passano come **stringa**
     * (`->toDateString()`), mai come Carbon.
     *
     * @param  Builder<Intervento>  $query
     * @return Builder<Intervento>
     */
    public static function partiziona(Builder $query, ?string $stato): Builder
    {
        $oggi = today()->toDateString();

        return match ($stato) {
            'scaduti' => $query->scadute(),
            'in_scadenza' => $query->apertiEntroSoglia()->where('interventi.data_scadenza', '>=', $oggi),
            'oltre' => $query->where(
                'interventi.data_scadenza',
                '>=',
                today()->addDays(Semaforo::giorniImminente() + 1)->toDateString()
            ),
            default => $query,
        };
    }

    /**
     * L'ordinamento dell'elenco: il più urgente in cima, e **il tie-break**.
     *
     * ⛔ **L'id è l'ultimo criterio, sempre, in entrambe le direzioni.**
     * `data_scadenza` ha pochissimi valori distinti su un parco vero — e su un
     * parco di *molti clienti* i pari sono ancora di più — e a parità di chiave
     * l'ordine fra due pagine è una **proprietà del motore**: SQLite scansiona
     * in modo stabile, Postgres può riordinare i pari fra la query di pagina 1 e
     * quella di pagina 2, e una riga esce da **entrambe**. Già pagato su questo
     * progetto il 25 Ago 2026: 46 righe raccolte su 47, con SQLite verde.
     *
     * Esiste come metodo — invece di due righe dentro `render()` — perché la
     * sua prova va fatta **sull'SQL**: sui dati il difetto si coglie a seconda
     * del piano che il motore sceglie, cioè una volta su tre.
     *
     * @param  Builder<Intervento>  $query
     * @return Builder<Intervento>
     */
    public static function ordinata(Builder $query, string $direzione): Builder
    {
        return $query
            ->orderBy('interventi.data_scadenza', $direzione === 'desc' ? 'desc' : 'asc')
            ->orderBy('interventi.id');
    }

    /**
     * Di **chi** sono le righe in pagina: cliente e sede, per `tenant_id`.
     *
     * 🔴 È il requisito che il committente ha posto per primo, ed è anche ciò
     * che impedisce di agire sul cliente sbagliato: su una vista cross-cliente
     * una riga senza intestazione non è un dettaglio estetico, è un intervento
     * attribuito a chiunque.
     *
     * ⛔ **Non si passa dalla relazione `$intervento->tenant`/`->strumento`.**
     * `UnitaOrganizzativa` e `Strumento` portano `TenantScope`: un eager load
     * qui tornerebbe `null` per ogni riga di un cliente che non è il proprio —
     * cioè una tabella di trattini, plausibile e muta. Si rilegge dalla porta,
     * che gli scope li toglie per nome.
     *
     * Due query costanti e non due per riga. Gli `Account` si restituiscono
     * come **model** e non come stringhe perché servono anche
     * all'impersonazione, che vuole i membri: una query sola per due usi.
     *
     * @param  list<int>  $tenantIds  i `tenant_id` delle righe in pagina
     * @return array{titolari: Collection<int, array{cliente: string, sede: string, account_id: ?int}>, clienti: Collection<int, Account>}
     */
    public static function titolari(Perimetro $perimetro, array $tenantIds): array
    {
        /** @var Collection<int, UnitaOrganizzativa> $sedi */
        $sedi = ParcoClienti::sedi($perimetro)
            ->whereIn('unita_organizzativa.id', $tenantIds)
            ->get(['unita_organizzativa.id', 'unita_organizzativa.nome', 'unita_organizzativa.account_id'])
            ->keyBy('id');

        /** @var Collection<int, Account> $clienti */
        $clienti = ParcoClienti::clienti($perimetro)
            ->whereIn('accounts.id', $sedi->pluck('account_id')->filter()->unique()->values()->all())
            ->get()
            ->keyBy('id');

        $titolari = $sedi->mapWithKeys(fn (UnitaOrganizzativa $sede) => [$sede->id => [
            'cliente' => $clienti->get($sede->account_id)?->ragione_sociale ?? '',
            'sede' => $sede->nome,
            'account_id' => $sede->account_id,
        ]]);

        return ['titolari' => $titolari, 'clienti' => $clienti];
    }

    /**
     * Le **macchine** delle righe in pagina, per id.
     *
     * Stessa ragione di `titolari()`: `$intervento->strumento` è scopato e
     * darebbe `null` fuori dal proprio Ente. Una query sola per tutta la pagina.
     *
     * @param  list<int>  $strumentoIds
     * @return Collection<int, Strumento>
     */
    public static function macchine(Perimetro $perimetro, array $strumentoIds): Collection
    {
        return ParcoClienti::strumenti($perimetro)
            ->whereIn('strumenti.id', $strumentoIds)
            ->get(['strumenti.id', 'strumenti.nome'])
            ->keyBy('id');
    }

    /**
     * Quanti giorni mancano alla scadenza — negativi se è già passata.
     *
     * ⛔ Si confrontano due **inizi di giornata**: `data_scadenza` è castata a
     * `date`, cioè un Carbon a mezzanotte, e confrontarla con `now()` renderebbe
     * «scaduto» un intervento di oggi alle 00:01. È la stessa trappola scritta
     * nel docblock di `Intervento::isScaduto()`.
     */
    public static function giorniAllaScadenza(CarbonInterface $scadenza): int
    {
        return (int) today()->diffInDays($scadenza->copy()->startOfDay(), false);
    }

    /**
     * «Quanto manca», in parole.
     *
     * ⚠️ Lo **scaduto si dice al positivo** («scaduto da 3 giorni») e non come
     * un numero negativo: «-3 giorni» è leggibile solo da chi già sa cosa
     * significa, e questa colonna esiste per chi guarda venti clienti insieme.
     */
    public static function quantoManca(CarbonInterface $scadenza): string
    {
        $giorni = self::giorniAllaScadenza($scadenza);

        return match (true) {
            $giorni < -1 => 'scaduto da '.abs($giorni).' giorni',
            $giorni === -1 => 'scaduto da ieri',
            $giorni === 0 => 'scade oggi',
            $giorni === 1 => 'scade domani',
            default => 'fra '.$giorni.' giorni',
        };
    }

    /**
     * Il termine di ricerca reso innocuo per un `LIKE`.
     *
     * ⛔ **I jolly si neutralizzano**: senza, un `%` da solo restituirebbe ogni
     * riga mentre la pagina si annuncia filtrata — cioè un filtro che si aggira
     * digitando un carattere — e `_` diventerebbe «un carattere qualunque».
     * Stessa forma, e per la stessa ragione, di `Scadenzario::jolly()` e di
     * `RegistroAudit::jolly()`.
     *
     * ⚠️ **`mb_strtolower()` e non `strtolower()`**, che è byte-wise: cercando
     * «SANITÀ» quest'ultimo lascerebbe intatti i due byte di «À» mentre il
     * `LOWER()` di Postgres è UTF-8-aware, e la macchina non si troverebbe. In
     * locale il difetto non si vede — il `LOWER()` di SQLite converte solo A–Z —
     * quindi la rete è un'asserzione sul **binding**, non sulle righe.
     */
    private static function jolly(string $termine): string
    {
        return addcslashes(mb_strtolower(trim($termine)), '%_\\');
    }
}
