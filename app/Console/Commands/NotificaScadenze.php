<?php

namespace App\Console\Commands;

use App\Enums\SoggettoGaranzia;
use App\Enums\TipoMotivoSemaforo;
use App\Enums\TransizioneAvviso;
use App\Models\AvvisoScadenza;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\DigestScadenze;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Notifiche\DestinatariEnte;
use App\Support\Notifiche\EntiNotificabili;
use App\Support\Notifiche\Referente;
use App\Support\Notifiche\RigaAvviso;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Lo scheduler delle scadenze: l'«email del futuro» (ADR-011, S5).
 *
 * Gira una volta al giorno e manda a ciascun destinatario **un digest per
 * Ente** con i soli cambi di stato: ciò che è appena diventato imminente (soglia
 * di ADR-005, 30 giorni) e ciò che è appena scaduto. Non ripete mai un avviso
 * già dato — la memoria è `avvisi_scadenza`, non una condizione di questa
 * classe — e nei giorni in cui non cambia nulla non manda niente.
 *
 * **⚠️ Qui i global scope NON proteggono nulla, ed è il punto più delicato del
 * comando.** In console `CurrentTenant::shouldScope()` è false, quindi
 * `TenantScope`, `DepartmentScope` e persino `GaranziaRicambioPrivacyScope` si
 * ritirano: una query nuda vedrebbe tutti gli Enti e tutti i nomi dei pezzi.
 * L'isolamento è perciò **responsabilità di questo codice**:
 *
 *   1. ogni query porta il proprio `where('tenant_id', …)` esplicito — il
 *      digest di un Ente non può contenere righe di un altro;
 *   2. il sotto-albero del Responsabile si riapplica a mano, con lo stesso
 *      criterio dell'applicazione (`AccessibleNodes::forUser()`), o l'email
 *      diventerebbe il canale che scavalca lo scope;
 *   3. il nome del ricambio non si protegge con una guardia ma **non si legge**:
 *      la query delle garanzie pezzo seleziona id, scadenza e strumento, come
 *      `Strumento::scadenzeGaranzieRicambi()` (ADR-004/020).
 *
 * Ognuna delle tre ha il proprio test negativo: è un'area rossa dichiarata dalla
 * Policy di Code Review, insieme al «niente duplicati».
 *
 * **Prima si scrive il log, poi si invia.** Se il processo muore in mezzo si
 * perde al più un'email — e il giorno dopo la scadenza è ancora lì da vedere in
 * applicazione — mentre l'ordine inverso, a ogni interruzione, manderebbe due
 * volte le stesse righe alle stesse persone.
 *
 * 🔴 **Il ruolo `Tenant` È fra i destinatari dal 25 Ago 2026**, e la riga che
 * stava qui diceva il contrario: «non è fra i destinatari (decisione del 18 Ago),
 * quindi l'impostazione per-Ente di ADR-029 non entra in gioco — nessun
 * destinatario è il ruolo che quella regola protegge». Era vera, ed era anche
 * ciò che rendeva **strutturalmente vuota** la campanella del Tenant:
 * `DigestScadenze::via()` scrive `database` sempre, ma solo per chi
 * `destinatari()` sceglie. `Funzionalità per Ruolo` §4 gli prometteva le
 * notifiche programmate e `/settings/notifiche` una preferenza per un'email che
 * nessuno gli mandava.
 *
 * ⚠️ Con lui **entra in gioco ADR-029**, e il filtro esiste: le righe delle
 * garanzie ricambio si tolgono a chi il proprio Ente le nasconde
 * (`senzaRicambiNascosti()`), chiedendo l'**ability** della Policy e mai il
 * permesso nudo.
 *
 * **⚠️ `--senza-invio` è obbligatorio al primo avvio su dati esistenti.** Il
 * comando avvisa dei *cambi di stato*, ma alla prima esecuzione non ha memoria:
 * ogni scadenza già aperta è un cambio mai notificato, e un Ente vero ne ha
 * migliaia (3297 interventi entro soglia sul solo database di sviluppo, contati
 * il 18 Ago 2026). La prima email sarebbe illeggibile e verrebbe classificata
 * come spam nel momento peggiore, cioè quando il prodotto si presenta. Con
 * l'opzione il log si popola in silenzio e dal giorno dopo arrivano solo le
 * novità vere — che è ciò che il digest promette. Non è una scorciatoia di
 * sviluppo: è il primo passo del deploy, e va scritto nella procedura.
 */
class NotificaScadenze extends Command
{
    protected $signature = 'easylab:notifica-scadenze
        {--senza-invio : Registra gli avvisi senza notificare nessuno (primo avvio)}';

    protected $description = 'Invia il digest giornaliero delle scadenze (interventi e garanzie) a chi le segue';

    public function handle(): int
    {
        // Chi resta fuori — gli Enti degli account in lockout (ADR-013) — e
        // perché la query sia cross-tenant senza passare da `VistaPiattaforma`
        // sono spiegati per intero nel docblock di `EntiNotificabili`, che dal
        // 27 Ago 2026 è l'unico posto in cui quella lista si costruisce: la
        // condivide con `easylab:notifica-obsolescenza`, e due copie sarebbero
        // state due posti liberi di divergere su chi NON riceve.
        foreach (EntiNotificabili::tutti() as $ente) {
            $this->perEnte($ente);
        }

        return self::SUCCESS;
    }

    private function perEnte(UnitaOrganizzativa $ente): void
    {
        $righe = $this->righeDaAvvisare($ente->id);

        if ($righe === []) {
            return;
        }

        DB::transaction(function () use ($ente, $righe): void {
            // Una `create()` per riga e non un `insert()` in blocco, benché
            // costi più query. Il motivo è la trappola delle date di questo
            // progetto: su SQLite una colonna `date` è una STRINGA, e il cast di
            // Eloquent la scrive come 'Y-m-d 00:00:00' mentre un insert grezzo
            // scriverebbe 'Y-m-d'. Le due forme non sono uguali per il
            // `whereColumn` che confronta questa data con quella della scadenza
            // — quindi il comando non riconoscerebbe più i propri avvisi e
            // rimanderebbe tutto ogni giorno. Su Postgres non accadrebbe (le
            // date sono date e si normalizzano), che è il modo peggiore in cui
            // un bug possa presentarsi: verde in CI, rumoroso nelle caselle dei
            // clienti. Le righe di un giorno sono poche: il costo è nulla.
            foreach ($righe as $riga) {
                AvvisoScadenza::create([
                    'tenant_id' => $ente->id,
                    'riferimento_type' => $this->morphDi($riga),
                    'riferimento_id' => $riga->riferimentoId,
                    'transizione' => $riga->transizione,
                    'data_scadenza' => $riga->scadenza,
                ]);
            }
        });

        $destinatari = $this->option('senza-invio') ? [] : $this->destinatari($ente->id, $righe);

        foreach ($destinatari as ['destinatario' => $destinatario, 'righe' => $sue, 'referente' => $referente]) {
            $destinatario->notify(new DigestScadenze(
                $ente->id,
                $ente->nome,
                $sue,
                alReferente: $referente !== null,
                nomeReferente: $referente?->nome,
            ));
        }

        $scadute = $this->conteggio($righe, TransizioneAvviso::Scaduta);
        $this->info(sprintf(
            'Ente «%s»: %d avvisi (%d scaduti, %d imminenti) a %d destinatari.%s',
            $ente->nome,
            count($righe),
            $scadute,
            count($righe) - $scadute,
            count($destinatari),
            $this->option('senza-invio') ? ' [solo registrazione]' : '',
        ));
    }

    /**
     * Le scadenze che hanno cambiato stato e di cui non si è ancora avvisato.
     *
     * Sei query bulk e una sola per gli strumenti: il calcolo per-model
     * (`Strumento::diagnosiSemaforo()`) costa tre query a macchina, e su un Ente
     * con più di mille strumenti sarebbe il modo per far durare il cron un'ora.
     *
     * @return list<RigaAvviso>
     */
    private function righeDaAvvisare(int $tenantId): array
    {
        $oggi = today()->toDateString();

        // Il confine «imminente» arriva SEMPRE dagli scope di dominio
        // (`apertiEntroSoglia`, `entroSoglia`) e non è mai riscritto qui: la
        // soglia di ADR-005 ha già la sua sede, e una seconda copia sarebbe
        // libera di divergere — l'email direbbe una cosa e il semaforo un'altra
        // sulla stessa macchina. Questo comando aggiunge solo il taglio fra le
        // due transizioni (`>= oggi` imminente, `< oggi` scaduta), che è una
        // distinzione sua e non del semaforo.
        //
        // La prima stesura ricalcolava `today()+soglia+1` a mano per le
        // garanzie: se n'è accorta una mutazione, spostando il confine di un
        // giorno senza far diventare rosso nulla.
        $interventiScaduti = Intervento::query()
            ->where('interventi.tenant_id', $tenantId)
            ->scadute()
            ->whereNotExists($this->giaAvvisato('interventi', Intervento::class, TransizioneAvviso::Scaduta, 'data_scadenza'))
            ->get();

        $interventiImminenti = Intervento::query()
            ->where('interventi.tenant_id', $tenantId)
            ->apertiEntroSoglia()
            ->where('interventi.data_scadenza', '>=', $oggi)
            ->whereNotExists($this->giaAvvisato('interventi', Intervento::class, TransizioneAvviso::Imminente, 'data_scadenza'))
            ->get();

        // `soggetto` esplicito: senza global scope attivo, senza questa clausola
        // le righe ricambio finirebbero anche qui — e con esse il nome del pezzo
        // nella select.
        $garanzieMacchinaScadute = Garanzia::query()
            ->where('garanzie.tenant_id', $tenantId)
            ->where('garanzie.soggetto', SoggettoGaranzia::Macchina->value)
            ->where('garanzie.data_scadenza_effettiva', '<', $oggi)
            ->whereNotExists($this->giaAvvisato('garanzie', Garanzia::class, TransizioneAvviso::Scaduta, 'data_scadenza_effettiva'))
            ->get();

        $garanzieMacchinaImminenti = Garanzia::query()
            ->where('garanzie.tenant_id', $tenantId)
            ->where('garanzie.soggetto', SoggettoGaranzia::Macchina->value)
            ->where('garanzie.data_scadenza_effettiva', '>=', $oggi)
            ->entroSoglia()
            ->whereNotExists($this->giaAvvisato('garanzie', Garanzia::class, TransizioneAvviso::Imminente, 'data_scadenza_effettiva'))
            ->get();

        $ricambiScadute = $this->garanzieRicambio($tenantId, TransizioneAvviso::Scaduta, $oggi);
        $ricambiImminenti = $this->garanzieRicambio($tenantId, TransizioneAvviso::Imminente, $oggi);

        $strumenti = $this->strumentiDi([
            ...$interventiScaduti->pluck('strumento_id')->all(),
            ...$interventiImminenti->pluck('strumento_id')->all(),
            ...$garanzieMacchinaScadute->pluck('strumento_id')->all(),
            ...$garanzieMacchinaImminenti->pluck('strumento_id')->all(),
            ...$ricambiScadute->pluck('strumento_id')->all(),
            ...$ricambiImminenti->pluck('strumento_id')->all(),
        ]);

        $righe = [];

        foreach ([$interventiScaduti, $interventiImminenti] as $insieme) {
            foreach ($insieme as $intervento) {
                $strumento = $strumenti[$intervento->strumento_id] ?? null;
                if ($strumento === null) {
                    continue;
                }
                $righe[] = RigaAvviso::daIntervento($intervento, $strumento->nome, $strumento->unita_organizzativa_id);
            }
        }

        foreach ([$garanzieMacchinaScadute, $garanzieMacchinaImminenti] as $insieme) {
            foreach ($insieme as $garanzia) {
                $strumento = $strumenti[$garanzia->strumento_id] ?? null;
                if ($strumento === null) {
                    continue;
                }
                $righe[] = RigaAvviso::daGaranziaMacchina($garanzia, $strumento->id, $strumento->nome, $strumento->unita_organizzativa_id);
            }
        }

        foreach ([$ricambiScadute, $ricambiImminenti] as $insieme) {
            foreach ($insieme as $garanzia) {
                $strumento = $strumenti[$garanzia->strumento_id] ?? null;
                if ($strumento === null) {
                    continue;
                }
                $righe[] = RigaAvviso::daGaranziaRicambio($garanzia, $strumento->id, $strumento->nome, $strumento->unita_organizzativa_id);
            }
        }

        return $righe;
    }

    /**
     * Garanzie dei pezzi montati (ADR-020).
     *
     * ⚠️ La select è la protezione: id, scadenza e lo strumento su cui il pezzo
     * è montato — **mai** `ricambi.nome`. Non c'è una guardia da ricordarsi
     * perché non c'è un dato da nascondere: quello protetto non viene letto.
     *
     * @return Collection<int, Garanzia>
     */
    private function garanzieRicambio(int $tenantId, TransizioneAvviso $transizione, string $oggi)
    {
        $query = Garanzia::query()
            ->deiPezziMontati()
            ->where('garanzie.tenant_id', $tenantId)
            ->whereNotExists($this->giaAvvisato('garanzie', Garanzia::class, $transizione, 'data_scadenza_effettiva'));

        if ($transizione === TransizioneAvviso::Scaduta) {
            $query->where('garanzie.data_scadenza_effettiva', '<', $oggi);
        } else {
            $query->where('garanzie.data_scadenza_effettiva', '>=', $oggi)
                ->entroSoglia();
        }

        return $query->get([
            'garanzie.id',
            'garanzie.data_scadenza_effettiva',
            'ricambio_utilizzo.strumento_id',
        ]);
    }

    /**
     * «Di questa riga, in questa transizione e con questa data, ho già
     * avvisato?» — il predicato che rende il comando ripetibile.
     *
     * La data entra nel confronto: una scadenza prorogata è un'altra scadenza e
     * torna legittimamente ad avvisare (vedi la migration di `avvisi_scadenza`).
     */
    private function giaAvvisato(string $tabella, string $classe, TransizioneAvviso $transizione, string $colonnaData): callable
    {
        $morph = (new $classe)->getMorphClass();

        return function (QueryBuilder $query) use ($tabella, $morph, $transizione, $colonnaData): void {
            $query->select(DB::raw(1))
                ->from('avvisi_scadenza')
                ->whereColumn('avvisi_scadenza.riferimento_id', "{$tabella}.id")
                ->whereColumn('avvisi_scadenza.data_scadenza', "{$tabella}.{$colonnaData}")
                ->where('avvisi_scadenza.riferimento_type', $morph)
                ->where('avvisi_scadenza.transizione', $transizione->value);
        };
    }

    /**
     * Chi riceve cosa (decisione di prodotto del 18 Ago 2026).
     *
     * - **Admin**: tutte le righe del proprio Ente — è la figura che risponde
     *   della manutenzione davanti al cliente.
     * - **Responsabile Reparto**: solo le macchine del proprio sotto-albero,
     *   con lo stesso criterio che l'applicazione usa per mostrargliele.
     * - **Tecnico assegnato**: i soli interventi che gli sono stati affidati, e
     *   nessuna garanzia. Cercato **senza** filtro sul tenant, perché il tecnico
     *   esterno non ne ha uno (ADR-030).
     * - **Tenant** (dal 25 Ago 2026): tutte le righe del proprio Ente come
     *   l'Admin, **meno** le garanzie ricambio che il suo Ente gli nasconde.
     * - **Referente della macchina** (dal 10 Ott 2026, 🔗 ADR-054): le righe
     *   delle sole macchine di cui la scheda lo indica come referente, alla
     *   casella scritta lì. È una casella e non una persona di Easy Lab: riceve
     *   l'email e nient'altro. 🔴 **Mai le garanzie dei pezzi montati**: è il
     *   dato che ADR-029 nasconde a chi non ha titolo, e un indirizzo scritto
     *   su una scheda un titolo non ce l'ha.
     *
     * Chi rientra da più canali riceve una notifica sola, con l'unione delle
     * proprie righe: un Admin che è anche l'assegnatario non deve ricevere due
     * email che dicono metà cosa ciascuna. Vale anche per il referente il cui
     * indirizzo è quello di una persona già nota a questo giro: le sue righe
     * si uniscono a quelle che riceve da persona, e la casella resta fuori.
     *
     * @param  list<RigaAvviso>  $righe
     * @return list<array{destinatario: User|AnonymousNotifiable, righe: list<RigaAvviso>, referente: ?Referente}>
     */
    private function destinatari(int $tenantId, array $righe): array
    {
        /** @var array<int, array{utente: User, righe: array<int, RigaAvviso>}> $perUtente */
        $perUtente = [];

        $aggiungi = function (User $utente, array $sue) use (&$perUtente): void {
            if ($sue === []) {
                return;
            }

            $perUtente[$utente->id] ??= ['utente' => $utente, 'righe' => []];

            foreach ($sue as $riga) {
                // Chiave per riga d'origine: la stessa scadenza raggiunta da due
                // canali resta una riga sola nel digest.
                $perUtente[$utente->id]['righe'][$riga->tipo->value.':'.$riga->riferimentoId] = $riga;
            }
        };

        // Il «chi» e i «suoi nodi» arrivano da `DestinatariEnte`, condiviso con
        // l'avviso di obsolescenza (27 Ago 2026). Il filtro delle RIGHE resta
        // qui, e con esso il filtro dei ricambi nascosti: è proprio del digest,
        // e l'altro comando non deve ereditarlo per distrazione.
        $persone = DestinatariEnte::perEnte($tenantId);

        foreach ($persone as ['utente' => $utente, 'nodi' => $nodi]) {
            $sue = $nodi === null
                ? $righe
                : array_values(array_filter(
                    $righe,
                    fn (RigaAvviso $riga) => $riga->unitaId !== null && in_array($riga->unitaId, $nodi, true),
                ));

            // ⚠️ `! hasRole('Admin')` e non solo `hasRole(TENANT_ROLE)`: nella
            // stesura precedente il ramo Admin veniva PRIMA e faceva `continue`,
            // quindi chi fosse Admin *e* Tenant riceveva tutto senza passare dal
            // filtro di ADR-029. L'estrazione non deve cambiarlo di straforo —
            // sarebbe una modifica di comportamento travestita da refactoring.
            if (! $utente->hasRole('Admin') && $utente->hasRole(User::TENANT_ROLE)) {
                $sue = $this->senzaRicambiNascosti($utente, $sue);
            }

            $aggiungi($utente, $sue);
        }

        $perTecnico = [];
        foreach ($righe as $riga) {
            if ($riga->tecnicoId !== null) {
                $perTecnico[$riga->tecnicoId][] = $riga;
            }
        }

        $tecnici = [];

        if ($perTecnico !== []) {
            // Senza `where('tenant_id')`: il tecnico esterno non ne ha uno, e
            // filtrarlo lo taglierebbe fuori dagli avvisi del lavoro che gli è
            // stato assegnato.
            foreach (User::query()->whereIn('id', array_keys($perTecnico))->get() as $tecnico) {
                $aggiungi($tecnico, $perTecnico[$tecnico->id]);
                $tecnici[] = $tecnico;
            }
        }

        $caselle = $this->caselleDeiReferenti($tenantId, $righe, [...array_column($persone, 'utente'), ...$tecnici], $aggiungi);

        return [
            ...array_values(array_map(fn (array $voce) => [
                'destinatario' => $voce['utente'],
                'righe' => array_values($voce['righe']),
                'referente' => null,
            ], $perUtente)),
            ...array_values(array_map(fn (array $voce) => [
                'destinatario' => $voce['referente']->casella(),
                'righe' => $voce['righe'],
                'referente' => $voce['referente'],
            ], $caselle)),
        ];
    }

    /**
     * Il canale del referente (🔗 ADR-054): le righe di ogni macchina vanno
     * anche alla casella che la sua scheda indica.
     *
     * Torna le sole caselle che **non** sono già una persona di questo giro.
     * Un referente il cui indirizzo è quello di un Admin, di un Responsabile o
     * del tecnico assegnato è quella persona: le righe gli si aggiungono lì
     * (`$aggiungi`), così riceve un riepilogo solo e valgono le sue preferenze.
     *
     * ⚠️ Il canale esiste solo se l'email è accesa in piattaforma: il referente
     * non ha una campanella, quindi a email spenta non c'è niente da lasciargli.
     *
     * @param  list<RigaAvviso>  $righe
     * @param  list<User>  $persone  chi è già noto a questo giro
     * @param  callable(User, list<RigaAvviso>): void  $aggiungi
     * @return array<string, array{referente: Referente, righe: list<RigaAvviso>}>
     */
    private function caselleDeiReferenti(int $tenantId, array $righe, array $persone, callable $aggiungi): array
    {
        if (! InterruttoriEmail::attiva(CatalogoEmail::RIEPILOGO_SCADENZE)) {
            return [];
        }

        $referenti = Referente::delleMacchine(
            $tenantId,
            array_values(array_unique(array_map(fn (RigaAvviso $riga) => $riga->strumentoId, $righe))),
        );

        if ($referenti === []) {
            return [];
        }

        $perIndirizzo = [];
        foreach ($persone as $persona) {
            $perIndirizzo[mb_strtolower(trim((string) $persona->email))] = $persona;
        }

        $caselle = [];

        foreach ($righe as $riga) {
            $referente = $referenti[$riga->strumentoId] ?? null;

            // 🔴 Le garanzie dei pezzi montati non passano da qui, per nessuno:
            // nemmeno per il referente che è anche un Tenant, a cui il filtro di
            // ADR-029 le ha appena tolte qualche riga più su.
            if ($referente === null || $riga->tipo === TipoMotivoSemaforo::GaranziaRicambio) {
                continue;
            }

            $persona = $perIndirizzo[$referente->email] ?? null;

            if ($persona !== null) {
                $aggiungi($persona, [$riga]);

                continue;
            }

            $caselle[$referente->email] ??= ['referente' => $referente, 'righe' => []];
            $caselle[$referente->email]['righe'][] = $riga;
        }

        return $caselle;
    }

    /**
     * Le righe che un destinatario può leggere, tolte le garanzie dei pezzi
     * montati se il suo Ente gliele nasconde (🔗 ADR-029).
     *
     * 🔴 **Serve al solo Tenant, ma non si gata sul ruolo: si chiede l'ability.**
     * Il ruolo dice *chi* è protetto dalla regola, l'ability dice *quanto vale
     * qui* — e sono due domande diverse, perché l'impostazione è dell'Ente e ha
     * tre stati. `spatie` concede appena il permesso esiste sul ruolo, cioè
     * **prima** che l'impostazione dell'Ente sia letta, e il Tenant
     * `garanzie.ricambio.view` ce l'ha dal 15 Ago: col permesso nudo le righe
     * passerebbero proprio negli Enti su `nascosta`, cioè l'unico caso che
     * questo filtro esiste per coprire.
     *
     * ⚠️ **In console i global scope non filtrano** — `CurrentTenant::shouldScope()`
     * è falso senza utente autenticato — quindi `GaranziaRicambioPrivacyScope`
     * qui non gira: le righe sono già state lette tutte, e questa è l'unica
     * guardia fra loro e una casella di posta. È il motivo per cui il filtro sta
     * a valle e non nella query.
     *
     * ⚠️ E il controllo sta **nel comando, non nella Notification**:
     * `DigestScadenze` è una `Notification`, quindi appartiene all'insieme che
     * `PermessiInCodaGuardrailTest` sorveglia — una lettura di permessi lì
     * dentro girerebbe nel worker, dove la cache dei permessi può essere quella
     * di un altro processo.
     *
     * Il **nome del pezzo** non c'entra e non è mai stato in gioco: la query del
     * comando legge solo id e scadenza, e l'email lo rende neutro per chiunque.
     * Ciò che qui si nega è l'**esistenza della riga**, cioè il fatto che su
     * quella macchina un pezzo sia stato sostituito.
     *
     * @param  list<RigaAvviso>  $righe
     * @return list<RigaAvviso>
     */
    private function senzaRicambiNascosti(User $utente, array $righe): array
    {
        if (Gate::forUser($utente)->allows('view', Garanzia::class)) {
            return $righe;
        }

        return array_values(array_filter(
            $righe,
            fn (RigaAvviso $riga) => $riga->tipo !== TipoMotivoSemaforo::GaranziaRicambio,
        ));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Strumento>
     */
    private function strumentiDi(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        return Strumento::query()
            ->whereIn('id', $ids)
            ->get(['id', 'nome', 'unita_organizzativa_id'])
            ->keyBy('id')
            ->all();
    }

    private function morphDi(RigaAvviso $riga): string
    {
        return $riga->tipo === TipoMotivoSemaforo::Intervento
            ? (new Intervento)->getMorphClass()
            : (new Garanzia)->getMorphClass();
    }

    /** @param  list<RigaAvviso>  $righe */
    private function conteggio(array $righe, TransizioneAvviso $transizione): int
    {
        return count(array_filter($righe, fn (RigaAvviso $riga) => $riga->transizione === $transizione));
    }
}
