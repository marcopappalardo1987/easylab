<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Notifications\ComodatoInScadenza;
use App\Support\Billing\DurataComodato;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Piani;
use Illuminate\Console\Command;

/**
 * L'avviso che un comodato d'uso sta per scadere (🔗 ADR-058).
 *
 * Gira una volta al giorno. Scrive al cliente — chi amministra il suo contratto
 * — e a tutti i Superadmin quando alla scadenza mancano trenta giorni o meno,
 * **una volta sola per scadenza**: la memoria è `comodato_avvisato_per`, che
 * porta la data di cui si è già scritto. Spostare la scadenza la rende, da
 * sola, una scadenza di cui non si è ancora scritto.
 *
 * ## Che cosa NON fa
 *
 * Non blocca nessuno e non cambia nessun piano: alla scadenza il cliente
 * continua a lavorare. Che cosa farne lo decide chi ha firmato il contratto,
 * non un comando.
 *
 * ## Chi resta fuori
 *
 * - l'account di piattaforma, che non è un cliente;
 * - chi nel frattempo è passato a un piano a pagamento: la sua scadenza non c'è
 *   più (`Account::cambiaPiano()` la toglie), e il filtro sul piano qui sotto
 *   copre il dato scritto da fuori;
 * - un comodato **già scaduto**: l'avviso è un preavviso, e di una data passata
 *   non c'è più niente da preavvisare.
 *
 * ⚠️ A email spenta in piattaforma il comando non scrive la memoria: chi la
 * riaccende trova gli avvisi ancora da mandare, non un registro che dice di
 * averli già mandati.
 */
class NotificaComodato extends Command
{
    protected $signature = 'easylab:notifica-comodato
        {--senza-invio : Segna gli avvisi come dati senza scrivere a nessuno}';

    protected $description = 'Avvisa trenta giorni prima che un comodato d\'uso scada (ADR-058)';

    public function handle(): int
    {
        $senzaInvio = (bool) $this->option('senza-invio');

        if (! $senzaInvio && ! InterruttoriEmail::attiva(CatalogoEmail::COMODATO_IN_SCADENZA)) {
            $this->info('Email spenta in piattaforma: nessun avviso.');

            return self::SUCCESS;
        }

        $inComodato = array_values(array_filter(Piani::codici(), Piani::eGratuito(...)));
        $oggi = today();

        // ⚠️ `orderBy('id')`: l'ordine in cui si scrive ai clienti non dipende
        // dal motore.
        $accounts = Account::query()
            ->where('di_piattaforma', false)
            ->whereIn('piano', $inComodato)
            ->whereNotNull('comodato_scade_il')
            ->whereDate('comodato_scade_il', '>=', $oggi)
            ->whereDate('comodato_scade_il', '<=', $oggi->copy()->addDays(DurataComodato::GIORNI_DI_PREAVVISO))
            ->orderBy('id')
            ->get()
            // In PHP e non con un confronto fra colonne: su SQLite una `date`
            // è una stringa, e due date uguali scritte in due forme non lo sono.
            ->reject(fn (Account $account) => $account->comodato_avvisato_per?->toDateString() === $account->comodato_scade_il->toDateString());

        $superadmin = $senzaInvio ? collect() : User::role(User::SUPERADMIN_ROLE)->orderBy('id')->get();

        foreach ($accounts as $account) {
            // Scrittura PRIMA dell'invio, come il digest: un'interruzione costa
            // al più un avviso perso, mai uno ripetuto ogni mattina.
            $account->forceFill(['comodato_avvisato_per' => $account->comodato_scade_il->toDateString()])->save();

            $destinatari = 0;

            if (! $senzaInvio) {
                $giorni = (int) $oggi->diffInDays($account->comodato_scade_il);
                $avviso = fn (bool $perPiattaforma) => new ComodatoInScadenza(
                    ragioneSociale: (string) $account->ragione_sociale,
                    piano: Piani::etichetta((string) $account->piano),
                    scadeIl: $account->comodato_scade_il->format('d/m/Y'),
                    giorni: $giorni,
                    perPiattaforma: $perPiattaforma,
                );

                $membri = $account->membri()->get();

                foreach ($membri as $membro) {
                    $membro->notify($avviso(false));
                    $destinatari++;
                }

                // Un Superadmin che è anche fra i membri l'ha già ricevuta.
                foreach ($superadmin->whereNotIn('id', $membri->pluck('id')) as $chiGoverna) {
                    $chiGoverna->notify($avviso(true));
                    $destinatari++;
                }
            }

            $this->info(sprintf(
                'Cliente «%s»: comodato in scadenza il %s, a %d destinatari.%s',
                $account->ragione_sociale,
                $account->comodato_scade_il->format('d/m/Y'),
                $destinatari,
                $senzaInvio ? ' [solo registrazione]' : '',
            ));
        }

        return self::SUCCESS;
    }
}
