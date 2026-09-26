<?php

namespace App\Support\Piattaforma;

use App\Models\Account;
use App\Models\User;
use App\Notifications\AccountEliminato;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 🔴 L'eliminazione **definitiva** di un cliente: dati, file, abbonamento
 * (🔗 ADR-040, ADR-032 l'Account intestatario, ADR-018 la tenancy).
 *
 * Il gemello inverso di `ProvisionaEnte`, e vive in una classe come lui per la
 * stessa ragione: il gesto è lungo, il suo **ordine è la sua correttezza**, e
 * va provabile senza passare da Livewire.
 *
 * ## ⛔ Non è un cestino, e ribalta una regola scritta
 *
 * Il progetto ha sempre scelto il soft delete — ADR-038 lo motiva su `User`,
 * `Documento` dichiara che la rimozione fisica è materiale della retention, e
 * la FK `unita_organizzativa.account_id` è **RESTRICT per scelta dichiarata**:
 * «un account con Enti non si cancella hard». Qui si rovescia, per il solo
 * Account e per il solo gesto amministrativo di piattaforma, perché un tenant
 * creato per sbaglio altrimenti resta per sempre — e con lui l'email del suo
 * Admin, che `users.email` (unique senza condizione) tiene occupata a vita.
 *
 * **Non c'è ripristino e non c'è backup.** Chi chiama questa classe ha già
 * ottenuto una conferma esplicita: qui dentro non si chiede più niente.
 *
 * ## 🔴 L'ordine, che è tutto
 *
 * Nessuna FK ha `cascadeOnDelete`: sono tutte RESTRICT, quindi le foglie vanno
 * prima delle radici e sbagliare l'ordine dà un errore di integrità **a metà
 * lavoro**, cioè un tenant mezzo cancellato. La transazione lo rende atomico,
 * ma l'ordine resta ciò che lo fa funzionare la prima volta.
 *
 * ⚠️ **`avvisi_scadenza` va nominata a mano**: il suo `tenant_id` non è una FK
 * ma un indice denormalizzato (per lo scheduler in console), quindi
 * dimenticarla non dà nessun errore — lascia righe orfane che il digest legge.
 *
 * ⚠️ **Il registro di audit resta**, ed è deliberato: `activity_log` è morph e
 * senza FK. La traccia di cosa è stato fatto deve sopravvivere a ciò su cui è
 * stato fatto, o questo gesto sarebbe l'unico del progetto senza memoria.
 *
 * ## Le chiamate di rete stanno FUORI dalla transazione
 *
 * Stripe e Backblaze: una transazione che contiene un round-trip tiene un lock
 * aperto per la sua durata, e soprattutto **il rollback non annulla ciò che un
 * terzo ha già fatto**. È la disciplina che `PortaListinoStripe` porta già
 * scritta.
 *
 * ⚠️ E se falliscono, **l'eliminazione resta fatta**: si riporta il guasto con
 * l'id da chiudere a mano. Annullare per un servizio esterno che non risponde
 * legherebbe un gesto di dominio alla disponibilità di un terzo — e a quel
 * punto i dati sono già andati.
 */
final class EliminaCliente
{
    /**
     * Le tabelle da svuotare per `tenant_id`, **nell'ordine in cui il database
     * le accetta**: le foglie prima di ciò a cui puntano.
     *
     * ⛔ Un elenco esplicito e non una scoperta automatica: ciò che questa
     * classe cancella è una decisione, e dedurla dallo schema significherebbe
     * che aggiungere una tabella cambia in silenzio cosa un gesto distruttivo
     * porta via. Un meta-test verifica che l'elenco sia completo.
     *
     * @var list<string>
     */
    public const TABELLE = [
        // Punta a strumenti **e** a interventi: prima di entrambi.
        'ricambio_utilizzo',
        'garanzie',
        'documenti',
        'spostamenti_strumento',
        'interventi',
        // Ora le tre radici del dominio.
        'strumenti',
        'ricambi',
        'fornitori',
        // ⚠️ Nessuna FK qui: se la si dimentica, il database tace.
        'avvisi_scadenza',
    ];

    /**
     * Esegue. Restituisce i guasti dei servizi esterni, che **non** hanno
     * impedito l'eliminazione.
     *
     * @return list<string> vuota se è filato tutto liscio
     */
    public function esegui(Account $account, ?User $causer = null): array
    {
        $conseguenze = self::conseguenze($account);

        // ⚠️ **Prima di cancellare**, o dopo non ci sarebbe più nessuno da
        // avvisare: gli indirizzi si raccolgono adesso e si notificano alla
        // fine. Il nome pure — serve nell'email e nell'audit.
        $destinatari = $account->membri()->pluck('email')->all();
        $ragioneSociale = (string) $account->ragione_sociale;
        $accountId = $account->getKey();

        // ⚠️ **Cestinate comprese**: la FK `unita_organizzativa.account_id` è
        // RESTRICT, quindi una sede nel cestino dimenticata qui fa fallire il
        // `forceDelete()` dell'account in fondo alla transazione.
        $enti = $account->enti()->withTrashed()->pluck('id')->all();

        // ⚠️ **Tutto l'albero, non le sole radici**: un reparto vero non ha
        // `account_id` (il model lo vieta), e cancellare l'Ente sopra un reparto
        // ancora vivo sbatte sulla FK `parent_id`. Stesso perimetro dell'export
        // GDPR (`PerimetroTenant`): ogni nodo il cui `tenant_id` è uno degli Enti.
        $nodi = DB::table('unita_organizzativa')
            ->whereIn('tenant_id', $enti)
            ->orWhereIn('id', $enti)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $subscription = $account->subscriptions()->where('stripe_status', '!=', 'canceled')->first();

        DB::transaction(function () use ($account, $enti, $nodi) {
            foreach (self::TABELLE as $tabella) {
                DB::table($tabella)->whereIn('tenant_id', $enti)->delete();
            }

            // Cascade dagli Enti, ma esplicite: leggere qui l'elenco completo di
            // ciò che sparisce vale più di due righe risparmiate.
            DB::table('tecnico_cliente')->whereIn('ente_id', $enti)->delete();
            DB::table('responsabile_unita')->whereIn('unita_organizzativa_id', $nodi)->delete();

            // ⚠️ **Dalle foglie alla radice**: `unita_organizzativa.parent_id`
            // punta a sé stessa, quindi cancellare un nodo che ha ancora figli
            // sbatte sulla FK. `orderByDesc('id')` non basterebbe — l'ordine di
            // creazione non è l'ordine dell'albero — quindi si scende per
            // profondità finché non resta niente.
            self::eliminaAlbero($nodi);

            self::eliminaPersoneSenzaAltriContratti($account);

            // `account_user`, `subscriptions` e `clienti_preferiti` cascano da
            // sé; `registrazioni.account_id` è `nullOnDelete` e la riga resta
            // come traccia di **da dove** quel cliente era entrato.
            $account->forceDelete();
        });

        // ─── Da qui in poi i dati non ci sono più. Nulla può annullare. ───

        $guasti = [];

        // 🔴 **Dopo il delete, mai prima**: `StripeWebhookController::accountDa()`
        // dichiara «account cestinato → nessuna scrittura e 200», quindi il
        // `customer.subscription.deleted` di ritorno è un no-op pulito solo in
        // quest'ordine. Al contrario riscriverebbe lockout e piano su una riga
        // che stiamo eliminando.
        if ($subscription !== null) {
            try {
                // `cancelNow()` e non `cancel()`: l'abbonamento finisce
                // **adesso**. Nessun rimborso — il periodo già pagato è una
                // decisione commerciale, e prenderla da un click di conferma
                // sarebbe esattamente ciò che non si vuole quando si elimina un
                // moroso o un tenant di prova.
                $subscription->cancelNow();
            } catch (Throwable $e) {
                $guasti[] = "L'abbonamento su Stripe ({$subscription->stripe_id}) non è stato chiuso: "
                    .$e->getMessage().' Va disdetto a mano dalla dashboard.';
            }
        }

        foreach ($enti as $tenantId) {
            try {
                Storage::disk('documenti')->deleteDirectory("tenant-{$tenantId}");
            } catch (Throwable $e) {
                $guasti[] = "I file della sede {$tenantId} non sono stati rimossi dall'archivio "
                    ."(prefisso «tenant-{$tenantId}»): ".$e->getMessage();
            }
        }

        // ⛔ L'audit **dopo**, e con i conteggi: le righe non ci sono più, e
        // questa è la sola cosa che resta a rispondere «cosa c'era».
        //
        // ⚠️ Scritto da qui e non dal model: `Account` ha `AuditsDomainWrites`,
        // e un `activity()` nel model insieme al trait rende rosso
        // `AuditCoverageGuardrailTest` — due righe per un gesto solo.
        activity(AuditLog::NAME)
            ->causedBy($causer)
            ->withProperties(array_merge($conseguenze->perAudit(), [
                'account_id' => $accountId,
                'ragione_sociale' => $ragioneSociale,
                'guasti' => $guasti,
            ]))
            ->log('Cliente eliminato definitivamente');

        // ⚠️ **Ultima cosa, e dentro `rescue()`.** Prima sarebbe annunciare la
        // fine di un contratto che potrebbe ancora fallire; e un SMTP giù non
        // deve trasformare un'eliminazione riuscita in un errore.
        foreach ($destinatari as $email) {
            rescue(fn () => Notification::route('mail', $email)
                ->notify(new AccountEliminato($ragioneSociale)));
        }

        return $guasti;
    }

    /**
     * Cosa si porta via, contato prima di farlo.
     *
     * ⚠️ `withoutGlobalScopes()` ovunque, ed è l'unico posto legittimo in cui
     * serve: si sta guardando **dentro** un tenant che non è il nostro, e i
     * conteggi devono essere quelli veri — un numero filtrato dallo scope
     * direbbe «0 strumenti» su un cliente pieno, cioè la rassicurazione
     * sbagliata davanti al pulsante peggiore della piattaforma.
     */
    public static function conseguenze(Account $account): ConseguenzeEliminazione
    {
        $enti = $account->enti()->pluck('id')->all();

        $quanti = fn (string $tabella) => $enti === []
            ? 0
            : DB::table($tabella)->whereIn('tenant_id', $enti)->count();

        $membri = $account->membri()->get();

        return new ConseguenzeEliminazione(
            sedi: count($enti),
            strumenti: $quanti('strumenti'),
            interventi: $quanti('interventi'),
            documenti: $quanti('documenti'),
            membri: $membri->count(),
            personeSenzaAltriContratti: $membri
                ->filter(fn (User $m) => $m->accounts()->count() === 1)
                ->count(),
            abbonamentoAttivo: $account->subscriptions()
                ->where('stripe_status', '!=', 'canceled')->exists(),
        );
    }

    /**
     * Gli Enti, dalle foglie alla radice.
     *
     * ⚠️ Un ciclo e non un `delete()` solo: `parent_id` punta alla stessa
     * tabella con RESTRICT, quindi si cancella per **livelli** — chi non è
     * genitore di nessuno, e poi di nuovo. Il `while` termina perché ogni giro
     * toglie almeno una riga (un albero finito ha sempre almeno una foglia); la
     * guardia sul conteggio invariato è la rete contro un ciclo a database, che
     * altrimenti girerebbe per sempre.
     *
     * @param  list<int>  $enti
     */
    private static function eliminaAlbero(array $enti): void
    {
        $rimasti = $enti;

        while ($rimasti !== []) {
            $genitori = DB::table('unita_organizzativa')
                ->whereIn('id', $rimasti)
                ->whereNotNull('parent_id')
                ->pluck('parent_id')->all();

            $foglie = array_values(array_diff($rimasti, $genitori));

            if ($foglie === []) {
                // Un ciclo: non può accadere con un albero, e se accade è meglio
                // un errore che una transazione che non finisce mai.
                throw new \RuntimeException(
                    'Le sedi di questo cliente formano un ciclo su `parent_id`: eliminazione interrotta.'
                );
            }

            DB::table('unita_organizzativa')->whereIn('id', $foglie)->delete();

            $rimasti = array_values(array_diff($rimasti, $foglie));
        }
    }

    /**
     * Le persone che restavano **solo** su questo contratto.
     *
     * 🔴 È ciò che libera davvero l'indirizzo: `users.email` è unique senza
     * condizione (ADR-038), quindi un utente vivo e senza contratto continuerebbe
     * a rifiutare ogni futura registrazione con quella casella — che è
     * esattamente il vicolo cieco da cui questa funzione è nata.
     *
     * ⛔ **Chi appartiene anche ad altri account resta intatto.** Un tecnico
     * EasyLab o un consulente su due clienti non deve sparire perché uno dei due
     * è stato eliminato.
     */
    private static function eliminaPersoneSenzaAltriContratti(Account $account): void
    {
        foreach ($account->membri()->get() as $membro) {
            if ($membro->accounts()->count() > 1) {
                continue;
            }

            $membro->forceDelete();
        }
    }
}
