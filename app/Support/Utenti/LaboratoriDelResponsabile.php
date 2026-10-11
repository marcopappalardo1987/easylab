<?php

namespace App\Support\Utenti;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Database\Eloquent\Collection as Persone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * I laboratori di un Responsabile Reparto: quali si possono assegnare, e l'unica
 * strada per scriverli (🔗 ADR-057; ADR-006 lo scope di reparto).
 *
 * Il pivot `responsabile_unita` esiste dal primo giorno ed è ciò che
 * `AccessibleNodes` legge per decidere che cosa un Responsabile vede: i nodi
 * assegnati più tutto ciò che contengono, e **niente** se non ne ha. Fino al
 * 10 Ott 2026 però nessuna schermata lo scriveva — lo riempivano i seeder — e
 * un Responsabile invitato da «Persone» entrava e non vedeva nulla, senza che
 * nessuno potesse rimediare.
 *
 * Le schermate che lo scrivono sono due (la persona, da «Persone»; il
 * laboratorio, da «Laboratori») e passano entrambe da qui, perché le regole
 * sono una cosa sola:
 *
 * - **si assegna solo a un Responsabile Reparto**: per ogni altro ruolo il
 *   pivot non conta, e una riga lasciata lì tornerebbe a contare il giorno in
 *   cui la persona lo ridiventa;
 * - **solo laboratori dell'Ente della persona**, vivi, e **mai la sede**:
 *   assegnare la radice sarebbe fare di un Responsabile un Admin che non
 *   amministra;
 * - **si tengono le sole radici**: chi segue un laboratorio segue già ciò che
 *   contiene, e una seconda riga per un sotto-laboratorio resterebbe lì a
 *   concedere, da sola, il giorno in cui il laboratorio gli viene tolto;
 * - **si scrive una sede alla volta**: chi segue laboratori anche in un'altra
 *   sede dello stesso contratto li ritrova quando ci passa, e assegnargli
 *   quelli di qui non glieli porta via.
 *
 * ⚠️ **Chi può farlo non si decide qui**: l'autorizzazione (`utenti.update`) e
 * il confine di tenancy — quale persona, quale nodo — li scrive la schermata
 * che chiama, che è la sola a sapere dove si trova chi agisce. Qui stanno le
 * invarianti che valgono per chiunque chiami.
 *
 * ⚠️ Query builder e non Eloquent per leggere i nodi: i global scope di
 * `UnitaOrganizzativa` (tenant, reparto) filtrerebbero in base a chi guarda, e
 * questa classe deve dare la stessa risposta a un Admin, a un Superadmin e a
 * un comando. Il confine lo dà il `tenant_id` scritto a mano.
 */
final class LaboratoriDelResponsabile
{
    /** Come si scrive un percorso: «Virologia › Sequenziamento». */
    private const SEPARATORE = ' › ';

    /**
     * I laboratori che si possono assegnare in un Ente: tutto il suo albero
     * tranne la sede, in ordine di percorso.
     *
     * @return Collection<int, object{id: int, nome: string, parent_id: int, percorso: string, ordine: string}> per id
     */
    public static function assegnabili(int $enteId): Collection
    {
        $nodi = DB::table('unita_organizzativa')
            ->where('tenant_id', $enteId)
            ->whereNull('deleted_at')
            ->get(['id', 'nome', 'parent_id', 'tipo'])
            ->keyBy('id');

        return $nodi
            ->reject(fn (object $nodo) => $nodo->tipo === TipoUnitaOrganizzativa::Ente->value)
            ->map(function (object $nodo) use ($nodi): ?object {
                $catena = self::catena($nodo, $nodi);

                // Un laboratorio rimasto sotto un nodo cestinato non è
                // raggiungibile da nessuna pagina: non si offre.
                if ($catena === null) {
                    return null;
                }

                $nomi = array_map(fn (object $n) => (string) $n->nome, $catena);

                return (object) [
                    'id' => (int) $nodo->id,
                    'nome' => (string) $nodo->nome,
                    'parent_id' => (int) $nodo->parent_id,
                    'percorso' => implode(self::SEPARATORE, $nomi),
                    // Per l'ordine, e solo per quello: nome dopo nome, col
                    // separatore più basso che esista. Ordinando il percorso
                    // scritto, «Virologia 2» finirebbe fra «Virologia» e i
                    // sotto-laboratori di «Virologia».
                    'ordine' => mb_strtolower(implode("\0", $nomi))."\0".$nodo->id,
                ];
            })
            ->filter()
            ->sortBy('ordine')
            ->keyBy('id');
    }

    /**
     * I laboratori assegnati a ciascuna persona, come id: i soli vivi e del suo
     * Ente, cioè quelli che `AccessibleNodes` le conta davvero.
     *
     * @param  list<int>  $utenti
     * @return array<int, list<int>> per id di persona
     */
    public static function assegnati(array $utenti): array
    {
        if ($utenti === []) {
            return [];
        }

        return DB::table('responsabile_unita')
            ->join('unita_organizzativa', 'unita_organizzativa.id', '=', 'responsabile_unita.unita_organizzativa_id')
            ->join('users', 'users.id', '=', 'responsabile_unita.user_id')
            ->whereIn('responsabile_unita.user_id', $utenti)
            ->whereColumn('unita_organizzativa.tenant_id', 'users.tenant_id')
            ->whereNull('unita_organizzativa.deleted_at')
            ->orderBy('responsabile_unita.unita_organizzativa_id')
            ->get(['responsabile_unita.user_id', 'responsabile_unita.unita_organizzativa_id'])
            ->groupBy('user_id')
            ->map(fn (Collection $righe) => $righe->pluck('unita_organizzativa_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }

    /**
     * Le persone che in un Ente hanno il ruolo Responsabile Reparto, non
     * cestinate: quelle a cui un laboratorio si può assegnare.
     *
     * @return Persone<int, User>
     */
    public static function responsabiliDellEnte(int $enteId): Persone
    {
        // ⚠️ Il tie-break sull'id: a parità di nome l'ordine è una proprietà
        // del motore, e SQLite e Postgres non la decidono allo stesso modo.
        return User::query()
            ->where('tenant_id', $enteId)
            ->role(User::DEPARTMENT_SCOPED_ROLE)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Chi segue un laboratorio: i Responsabili a cui è assegnato proprio lui,
     * e quelli che lo ricevono da un laboratorio che lo contiene.
     *
     * @return list<array{persona: User, tramite: ?string}> `tramite` è il
     *                                                      percorso del laboratorio da cui lo eredita; `null` se gli è
     *                                                      assegnato questo.
     */
    public static function chiSegue(int $nodoId, int $enteId): array
    {
        return self::perLaboratorio($nodoId, $enteId)['seguono'];
    }

    /**
     * Ciò che la pagina di un laboratorio mostra dei Responsabili: chi lo segue
     * già, e chi nell'Ente potrebbe seguirlo — in una lettura sola.
     *
     * @return array{seguono: list<array{persona: User, tramite: ?string}>, altri: list<User>}
     */
    public static function perLaboratorio(int $nodoId, int $enteId): array
    {
        $assegnabili = self::assegnabili($enteId);

        // Questo laboratorio e, risalendo, quelli che lo contengono: il più
        // vicino per primo.
        $catena = [];
        for ($id = $nodoId; $assegnabili->has($id); $id = $assegnabili->get($id)->parent_id) {
            $catena[] = $id;
        }

        // La sede, un laboratorio cestinato, quello di un altro Ente: non è
        // un laboratorio di qui, e non lo segue né lo può seguire nessuno.
        if ($catena === []) {
            return ['seguono' => [], 'altri' => []];
        }

        $righe = DB::table('responsabile_unita')
            ->whereIn('unita_organizzativa_id', $catena)
            ->get(['user_id', 'unita_organizzativa_id'])
            ->groupBy('user_id');

        $seguono = [];
        $altri = [];

        // Dall'elenco dei Responsabili dell'Ente, e non dal pivot: una riga
        // rimasta a chi non lo è più, o di un'altra sede, non conta per
        // `AccessibleNodes` e non si mostra qui.
        foreach (self::responsabiliDellEnte($enteId) as $persona) {
            if (! $righe->has($persona->id)) {
                $altri[] = $persona;

                continue;
            }

            $assegnati = $righe->get($persona->id)->pluck('unita_organizzativa_id')->map(fn ($id) => (int) $id)->all();
            $daDove = collect($catena)->first(fn (int $id) => in_array($id, $assegnati, true));

            $seguono[] = [
                'persona' => $persona,
                'tramite' => $daDove === $nodoId ? null : $assegnabili->get($daDove)->percorso,
            ];
        }

        return ['seguono' => $seguono, 'altri' => $altri];
    }

    /**
     * Sostituisce i laboratori di un Responsabile con quelli indicati.
     *
     * @param  array<int, int|string>  $nodi
     * @return array{prima: list<int>, dopo: list<int>}
     *
     * @throws AssegnazioneRifiutata
     */
    public static function imposta(User $responsabile, array $nodi, ?User $attore): array
    {
        return self::scrivi($responsabile, fn (array $correnti): array => $nodi, $attore);
    }

    /**
     * Aggiunge un laboratorio a quelli che la persona segue già.
     *
     * @return array{prima: list<int>, dopo: list<int>}
     *
     * @throws AssegnazioneRifiutata
     */
    public static function aggiungi(User $responsabile, int $nodo, ?User $attore): array
    {
        return self::scrivi($responsabile, fn (array $correnti): array => [...$correnti, $nodo], $attore);
    }

    /**
     * Toglie un laboratorio da quelli che la persona segue.
     *
     * @return array{prima: list<int>, dopo: list<int>}
     *
     * @throws AssegnazioneRifiutata
     */
    public static function togli(User $responsabile, int $nodo, ?User $attore): array
    {
        return self::scrivi($responsabile, fn (array $correnti): array => array_values(array_diff($correnti, [$nodo])), $attore);
    }

    /**
     * La sola scrittura. `$voluti` riceve i laboratori che la persona segue
     * oggi — fra quelli ancora assegnabili — e torna quelli che deve seguire.
     *
     * @param  callable(list<int>): array<int, int|string>  $voluti
     * @return array{prima: list<int>, dopo: list<int>}
     *
     * @throws AssegnazioneRifiutata
     */
    private static function scrivi(User $responsabile, callable $voluti, ?User $attore): array
    {
        if (! $responsabile->isDepartmentScoped()) {
            throw AssegnazioneRifiutata::nonResponsabile();
        }

        // Un Responsabile senza Ente non ha laboratori fra cui scegliere:
        // l'elenco è vuoto, e qualunque scelta viene rifiutata qui sotto.
        $enteId = (int) $responsabile->tenant_id;
        $assegnabili = self::assegnabili($enteId);

        // ⚠️ «Correnti» sono le sole righe ancora assegnabili: una rimasta su
        // un laboratorio cestinato non deve far rifiutare l'aggiunta di un
        // altro. Sparisce da sé qui sotto, perché `$dopo` non la contiene.
        $correnti = array_values(array_filter(
            self::righeNellEnte($responsabile, $enteId),
            fn (int $id) => $assegnabili->has($id),
        ));

        $nodi = array_values(array_unique(array_map(fn ($id) => (int) $id, $voluti($correnti))));

        foreach ($nodi as $id) {
            if (! $assegnabili->has($id)) {
                throw AssegnazioneRifiutata::laboratorioEstraneo();
            }
        }

        $dopo = self::soleRadici($nodi, $assegnabili);

        return DB::transaction(function () use ($responsabile, $enteId, $dopo, $assegnabili, $attore): array {
            // 🔴 **Solo le righe di QUESTO Ente.** Chi segue laboratori anche
            // in un'altra sede dello stesso contratto li ritrova quando ci
            // passa (`AccessibleNodes` conta le righe della sede in cui la
            // persona si trova): salvare quelli di qui non deve portarglieli
            // via. Per questo non è un `sync()`, che toglierebbe ogni riga
            // che non sia nell'elenco — comprese quelle dell'altra sede.
            //
            // Fra le righe di qui ci sono anche quelle rimaste su un
            // laboratorio cestinato: `$dopo` non le contiene, quindi se ne
            // vanno. La scrittura sul pivot azzera la memo dei nodi
            // (`AccessibleNodesMemo`): chi è collegato vede subito il cambio.
            $prima = self::righeNellEnte($responsabile, $enteId);

            $daTogliere = array_values(array_diff($prima, $dopo));
            $daAggiungere = array_values(array_diff($dopo, $prima));

            if ($daTogliere !== []) {
                $responsabile->unitaResponsabili()->detach($daTogliere);
            }

            if ($daAggiungere !== []) {
                $responsabile->unitaResponsabili()->attach($daAggiungere);
            }

            if ($prima !== $dopo) {
                $nome = fn (int $id) => $assegnabili->get($id)?->percorso ?? "#{$id}";

                // `User` è esente da `AuditsDomainWrites`: le sue righe si
                // scrivono a mano, come per il ruolo.
                activity(AuditLog::NAME)
                    ->causedBy($attore)
                    ->performedOn($responsabile)
                    ->withProperties([
                        'da' => array_map($nome, $prima),
                        'a' => array_map($nome, $dopo),
                        'tenant_id' => $responsabile->tenant_id,
                    ])
                    ->log('Laboratori assegnati');
            }

            return ['prima' => $prima, 'dopo' => $dopo];
        });
    }

    /**
     * Toglie tutto: è ciò che accade a chi **smette** di essere Responsabile.
     *
     * Non passa da `imposta()`, che rifiuterebbe proprio la persona a cui
     * serve: qui il ruolo è già cambiato. E toglie le righe di **ogni** sede,
     * non solo di quella in cui ci si trova: il ruolo è della persona, non
     * della sede, e chi non è più Responsabile non lo è da nessuna parte.
     */
    public static function svuota(User $persona, ?User $attore): void
    {
        $tolti = $persona->unitaResponsabili()->detach();

        if ($tolti > 0) {
            activity(AuditLog::NAME)
                ->causedBy($attore)
                ->performedOn($persona)
                ->withProperties(['tolti' => $tolti, 'tenant_id' => $persona->tenant_id])
                ->log('Laboratori tolti col ruolo');
        }
    }

    /**
     * Le righe del pivot di questa persona **su nodi di quell'Ente**, comprese
     * quelle che non contano più (un laboratorio cestinato). Quelle di un'altra
     * sede non ci sono: da qui non si leggono e non si toccano.
     *
     * @return list<int>
     */
    private static function righeNellEnte(User $persona, int $enteId): array
    {
        return DB::table('responsabile_unita')
            ->join('unita_organizzativa', 'unita_organizzativa.id', '=', 'responsabile_unita.unita_organizzativa_id')
            ->where('responsabile_unita.user_id', $persona->id)
            ->where('unita_organizzativa.tenant_id', $enteId)
            ->orderBy('responsabile_unita.unita_organizzativa_id')
            ->pluck('responsabile_unita.unita_organizzativa_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Toglie dall'insieme ciò che è già compreso in un altro suo elemento.
     *
     * @param  list<int>  $nodi
     * @param  Collection<int, object>  $assegnabili
     * @return list<int>
     */
    private static function soleRadici(array $nodi, Collection $assegnabili): array
    {
        $scelti = array_flip($nodi);

        $radici = array_filter($nodi, function (int $id) use ($scelti, $assegnabili): bool {
            $padre = $assegnabili->get($id)?->parent_id;

            while ($padre !== null && $assegnabili->has($padre)) {
                if (isset($scelti[$padre])) {
                    return false;
                }

                $padre = $assegnabili->get($padre)->parent_id;
            }

            return true;
        });

        sort($radici);

        return array_values($radici);
    }

    /**
     * La catena dei nodi dal primo laboratorio sotto la sede fino a questo, o
     * `null` se risalendo non si arriva a una sede viva.
     *
     * @param  Collection<int, object>  $nodi
     * @return list<object>|null
     */
    private static function catena(object $nodo, Collection $nodi): ?array
    {
        $catena = [];
        $visti = [];

        while ($nodo->tipo !== TipoUnitaOrganizzativa::Ente->value) {
            // Un ciclo nell'albero è un dato corrotto: non si gira in tondo.
            if (isset($visti[$nodo->id]) || $nodo->parent_id === null || ! $nodi->has($nodo->parent_id)) {
                return null;
            }

            $visti[$nodo->id] = true;
            array_unshift($catena, $nodo);
            $nodo = $nodi->get($nodo->parent_id);
        }

        return $catena;
    }
}
