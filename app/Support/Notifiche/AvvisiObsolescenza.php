<?php

namespace App\Support\Notifiche;

use App\Enums\TipoUnitaOrganizzativa;
use App\Enums\TransizioneAvviso;
use App\Models\AvvisoScadenza;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Notifications\AvvisoObsolescenza;
use Illuminate\Support\Facades\DB;

/**
 * L'alert di obsolescenza di un Ente: chi ha appena **attraversato la linea**
 * dell'età, e chi è tornato al di qua (🔗 ADR-014 · ADR-011 · ADR-013).
 *
 * Una macchina attraversa la linea in due modi: invecchiando di un giorno, o
 * perché l'Admin ha **abbassato** `soglia_obsolescenza_anni` dall'anagrafica.
 * In entrambi i casi parte **una sola email per Ente** con l'elenco intero, e
 * mai una ripetizione: la memoria è `avvisi_scadenza`, la stessa dello scheduler
 * delle scadenze, con la transizione `Obsoleta`.
 *
 * ## 🔴 La data di riferimento è `data_installazione`, MAI «installazione + soglia»
 *
 * È la decisione centrale di questa classe, e quella che sembra ovvia è la
 * sbagliata. La colonna `avvisi_scadenza.data_scadenza` entra nella unique
 * `avvisi_scadenza_unico` (`tenant_id` + morph + transizione + data), quindi
 * **è** la chiave anti-duplicati. Scriverci la data di scadenza dell'obsolescenza
 * (`installazione + soglia`) legherebbe la chiave a un valore **mutabile per
 * Ente**: abbassando la soglia da 10 a 8, ogni macchina già obsoleta da anni
 * cambierebbe chiave (`install+10` → `install+8`) e verrebbe **riavvisata** —
 * l'email unica conterrebbe l'intero parco vecchio invece delle sole macchine
 * che hanno davvero attraversato la linea, cioè esattamente il rumore che
 * questa feature esiste per evitare.
 *
 * Con la `data_installazione` nuda la chiave è **stabile sotto i cambi di
 * soglia** e si muove solo se cambia un FATTO — una data di installazione
 * corretta a mano — che è la stessa semantica della proroga già scritta nella
 * migration di `avvisi_scadenza`: un'altra data è un'altra cosa, e merita il suo
 * avviso.
 *
 * ## ⚠️ Gira in DUE contesti, e i global scope non sono gli stessi
 *
 * Da `easylab:notifica-obsolescenza` gli scope **si ritirano**
 * (`CurrentTenant::shouldScope()` è false senza utente): una query nuda vedrebbe
 * tutti gli Enti. L'isolamento è perciò responsabilità di questo codice, e ogni
 * query porta il proprio `where('tenant_id', …)` esplicito — senza, l'email di
 * un Ente conterrebbe le macchine di un altro. Da `Albero::save()` gli scope
 * sono invece **attivi**, e chi salva può essere department-scoped: vede solo il
 * proprio sotto-albero. Il codice qui sotto regge entrambi i casi, e il passo
 * che lo rende possibile è il reset conservativo.
 *
 * ## 🔴 Il reset è CONSERVATIVO, e la forma corta sarebbe sbagliata
 *
 * «Cancella tutte le righe delle macchine che non risultano più obsolete»
 * sarebbe più breve di due righe e in `Albero::save()` cancellerebbe **in
 * silenzio** gli avvisi delle macchine che chi salva non ha il diritto di
 * vedere: non risultano obsolete perché non risultano affatto. Qui si cancellano
 * quindi solo le righe di macchine **positivamente lette** (`$visibili`): una
 * macchina non vista non viene toccata, e il giro notturno in console — dove si
 * vede tutto — è l'autorità che ricompone l'invariante entro ventiquattr'ore.
 *
 * ⚠️ Stessa conseguenza, e corretta, sul soft delete: uno strumento cestinato
 * non viene letto, quindi la sua riga di avviso **non** si cancella. Se tornasse
 * dal cestino ancora obsoleto, non riceverebbe un secondo avviso per qualcosa di
 * cui si era già detto.
 *
 * ⚠️ **Una macchina trasferita fra Enti (ADR-015) viene riavvisata nel nuovo
 * Ente**, ed è voluto: coerentemente con la scelta di ADR-015 di trasferire lo
 * storico a chi la macchina ce l'ha ora.
 *
 * 🔴 **E la riga del vecchio proprietario NON cade: resta finché non la porta
 * via la potatura a 24 mesi.** Il reset conservativo tocca solo le macchine
 * positivamente lette, e dopo il trasferimento quella macchina non è più fra le
 * sue: `$visibili` non la contiene, quindi `$daCancellare` nemmeno. Distinguere
 * «non è più mia» da «non ho il diritto di vederla» richiederebbe una lettura
 * senza scope, cioè proprio il bypass che ADR-018 vieta — quindi la riga
 * sopravvissuta è il prezzo dichiarato, non una svista.
 *
 * ⚠️ È la ragione per cui `tenant_id` sta nella unique (migration del 27 Ago
 * 2026): le due righe — vecchio Ente e nuovo — condividono morph, riferimento,
 * transizione e data, perché la data è la `data_installazione` nuda. Senza il
 * tenant nella chiave la `create()` qui sotto violava il vincolo e faceva
 * **abortire il comando notturno**, lasciando senza avviso ogni Ente successivo
 * a quello, tutti i giorni, finché la macchina restava dov'era. Il test è
 * «warns the new Ente when an obsolete machine is transferred across tenants».
 *
 * ## ⚠️ Sincrono, e non un Job
 *
 * `PermessiInCodaGuardrailTest` afferma e verifica che oggi **nessun** job,
 * notifica o listener in coda interroghi permessi o ruoli, e su quell'assenza di
 * superficie poggia la decisione di non chiudere il buco della cache spatie nei
 * worker. La scelta dei destinatari passa da `hasRole()` (`DestinatariEnte`):
 * metterla dentro un `ShouldQueue` renderebbe falso quel docblock. Le email
 * restano comunque in coda — `AvvisoObsolescenza` è già `ShouldQueue` — quindi
 * il costo sincrono è quello di poche query, non di un SMTP.
 */
final class AvvisiObsolescenza
{
    /** Soglia di ripiego, identica a `Strumento::sogliaObsolescenza()`. */
    private const SOGLIA_DI_DEFAULT = 10;

    /**
     * @return array{nuovi: int, tornateNuove: int, destinatari: int}
     */
    public static function perEnte(UnitaOrganizzativa $ente, bool $senzaInvio = false): array
    {
        $niente = ['nuovi' => 0, 'tornateNuove' => 0, 'destinatari' => 0];

        // Solo il nodo Ente porta la soglia: su un dipartimento la colonna c'è
        // ma non significa nulla, e `Albero::save()` non la scrive.
        if ($ente->tipo !== TipoUnitaOrganizzativa::Ente) {
            return $niente;
        }

        // ADR-013, lo stesso motivo per cui il digest salta gli Enti bloccati:
        // dire «rinnova il parco macchine» a un cliente a cui abbiamo chiuso la
        // porta per insoluto è la contraddizione in un minuto solo. E non si
        // scrive nemmeno il log, così allo sblocco l'avviso è ancora una novità.
        // ⚠️ La verifica si ripete qui benché `EntiNotificabili` filtri già:
        // l'altro chiamante è una richiesta web, dove quel filtro non passa.
        if ($ente->account()->where('is_locked', true)->exists()) {
            return $niente;
        }

        $soglia = (int) ($ente->soglia_obsolescenza_anni ?? self::SOGLIA_DI_DEFAULT);

        // RILEVAZIONE. Il confine dell'obsolescenza esiste già in due forme nel
        // progetto (`isObsoleto()` per-model e `scopeObsoleti()` in SQL, tenute
        // allineate da `ObsolescenzaTest`): qui si usa la seconda e non se ne
        // scrive una terza. Le soglie si passano esplicitamente — senza
        // argomento lo scope chiamerebbe `soglieDegliEntiVisibili()`, che
        // costruisce un ramo OR per OGNI Ente del database, facendo dipendere il
        // risultato di questo Ente da quanti altri ne esistono.
        //
        // Il secondo `orderBy` sull'id è il tie-break che il progetto pretende
        // su ogni ordinamento: a parità di data di installazione Postgres è
        // libero di riordinare i pari, SQLite no.
        //
        // ⚠️ **Il `where('strumenti.tenant_id')` è la SECONDA delle due
        // restrizioni di tenant, e va detto o passa per load-bearing.** Misurato
        // con la prova di mutazione del 27 Ago 2026: toglierlo lascia il test
        // «never puts machines of another Ente in the list» VERDE, perché
        // `scopeObsoleti([$ente->id => …])` costruisce già un ramo
        // `where('strumenti.tenant_id', …)` per ciascuna soglia ricevuta.
        // Togliendo **entrambe** — cioè chiamando `obsoleti()` senza argomento —
        // quel test diventa rosso, quindi l'isolamento è davvero misurato: non
        // lo è *questa riga* in particolare. Resta perché la protezione non deve
        // dipendere dalla forma interna di uno scope che vive in un altro file:
        // il giorno in cui `scopeObsoleti` accettasse una soglia sola senza il
        // ramo per Ente, l'email di un Ente conterrebbe le macchine di un altro
        // e nessun test lo direbbe. È difesa in profondità dichiarata, non
        // ridondanza dimenticata.
        $obsoleti = Strumento::query()
            ->where('strumenti.tenant_id', $ente->id)
            ->obsoleti([$ente->id => $soglia])
            ->orderBy('strumenti.data_installazione')
            ->orderBy('strumenti.id')
            ->get(['id', 'nome', 'unita_organizzativa_id', 'data_installazione'])
            ->keyBy('id');

        // LE RIGHE GIÀ SCRITTE. Il confronto fra le due liste si fa in PHP su
        // stringhe normalizzate e mai con un `whereColumn` fra date: è ciò che
        // toglie di mezzo per costruzione la divergenza SQLite/Postgres, dove
        // una colonna `date` è una stringa da una parte e una data dall'altra.
        $conAvviso = AvvisoScadenza::query()
            ->where('tenant_id', $ente->id)
            ->where('riferimento_type', (new Strumento)->getMorphClass())
            ->where('transizione', TransizioneAvviso::Obsoleta->value)
            ->get(['id', 'riferimento_id', 'data_scadenza']);

        $chiaviObsolete = $obsoleti
            ->map(fn (Strumento $s) => self::chiave($s->id, $s->data_installazione->toDateString()))
            ->values()
            ->all();

        // RESET CONSERVATIVO: solo le macchine che si sono positivamente lette.
        // Vedi il docblock — la forma corta cancellerebbe gli avvisi delle
        // macchine che chi salva non ha il diritto di vedere.
        $visibili = $conAvviso->isEmpty() ? [] : Strumento::query()
            ->where('tenant_id', $ente->id)
            ->whereIn('id', $conAvviso->pluck('riferimento_id')->all())
            ->pluck('id')
            ->all();

        $daCancellare = [];
        $chiaviSopravvissute = [];

        foreach ($conAvviso as $riga) {
            $chiave = self::chiave($riga->riferimento_id, $riga->data_scadenza->toDateString());

            if (in_array($chiave, $chiaviObsolete, true)) {
                $chiaviSopravvissute[] = $chiave;

                continue;
            }

            // Non più obsoleta (soglia rialzata) **oppure** riga orfana: la
            // `data_installazione` è stata corretta nel frattempo, quindi
            // quell'avviso parlava di un fatto che non c'è più.
            if (in_array($riga->riferimento_id, $visibili, true)) {
                $daCancellare[] = $riga->id;
            }
        }

        /** @var list<RigaObsolescenza> $nuove */
        $nuove = [];
        foreach ($obsoleti as $strumento) {
            $chiave = self::chiave($strumento->id, $strumento->data_installazione->toDateString());
            if (in_array($chiave, $chiaviSopravvissute, true)) {
                continue;
            }
            $nuove[] = RigaObsolescenza::daStrumento($strumento);
        }

        // SCRITTURA PRIMA DELL'INVIO, come il digest: un'interruzione costa al
        // più un'email persa e mai una duplicata.
        DB::transaction(function () use ($daCancellare, $nuove, $ente): void {
            if ($daCancellare !== []) {
                AvvisoScadenza::query()->whereIn('id', $daCancellare)->delete();
            }

            // Una `create()` per riga e non un `insert()` in blocco, per la
            // trappola delle date già scritta in `NotificaScadenze`: su SQLite
            // il cast di Eloquent scrive 'Y-m-d 00:00:00' mentre un insert
            // grezzo scrive 'Y-m-d', e le due forme non si riconoscerebbero fra
            // loro al giro successivo.
            foreach ($nuove as $riga) {
                AvvisoScadenza::create([
                    'tenant_id' => $ente->id,
                    'riferimento_type' => (new Strumento)->getMorphClass(),
                    'riferimento_id' => $riga->strumentoId,
                    'transizione' => TransizioneAvviso::Obsoleta,
                    // 🔴 La data NUDA. Vedi il docblock: con «install + soglia»
                    // ogni abbassamento riavviserebbe l'intero parco già noto.
                    'data_scadenza' => $riga->dataInstallazione,
                ]);
            }
        });

        $esito = [
            'nuovi' => count($nuove),
            'tornateNuove' => count($daCancellare),
            'destinatari' => 0,
        ];

        if ($senzaInvio || $nuove === []) {
            return $esito;
        }

        foreach (DestinatariEnte::perEnte($ente->id) as ['utente' => $utente, 'nodi' => $nodi]) {
            $sue = $nodi === null
                ? $nuove
                : array_values(array_filter(
                    $nuove,
                    fn (RigaObsolescenza $riga) => $riga->unitaId !== null && in_array($riga->unitaId, $nodi, true),
                ));

            if ($sue === []) {
                continue;
            }

            // UNA notifica per destinatario con la lista intera, non una per
            // macchina: è la stessa ragione anti-spam per cui il digest è un
            // digest — venti email nello stesso minuto sono il modo più veloce
            // per far filtrare il mittente.
            $utente->notify(new AvvisoObsolescenza($ente->id, $ente->nome, $soglia, $sue));
            $esito['destinatari']++;
        }

        return $esito;
    }

    /**
     * La chiave logica di una riga di avviso: «questa macchina, con questa data
     * di installazione». È la proiezione in PHP della unique a quattro colonne,
     * meno le due che qui sono costanti (il morph e la transizione).
     */
    private static function chiave(int $strumentoId, string $data): string
    {
        return $strumentoId.'|'.$data;
    }
}
