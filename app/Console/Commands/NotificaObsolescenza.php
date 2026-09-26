<?php

namespace App\Console\Commands;

use App\Support\Notifiche\AvvisiObsolescenza;
use App\Support\Notifiche\EntiNotificabili;
use Illuminate\Console\Command;

/**
 * L'alert automatico di obsolescenza (🔗 ADR-014 × ADR-011).
 *
 * Gira una volta al giorno e, per ciascun Ente, manda **una sola email** con le
 * macchine che hanno appena superato la soglia di età — per invecchiamento o
 * perché la soglia è stata abbassata — senza mai ripetersi. La logica sta tutta
 * in `App\Support\Notifiche\AvvisiObsolescenza`: qui c'è solo il giro sugli
 * Enti, perché lo stesso servizio è chiamato anche dall'anagrafica quando
 * l'Admin salva una soglia più bassa.
 *
 * ## ⚠️ Comando SEPARATO da `easylab:notifica-scadenze`, e non per estetica
 *
 * Ragione di prodotto: sono due email diverse, con oggetto e corpo diversi, e
 * l'obsolescenza non ha «imminente/scaduto».
 *
 * Ragione tecnica, misurabile: `StrumentoFactory` genera `data_installazione`
 * con `dateTimeBetween('-12 years', 'now')`, quindi con la soglia di default a
 * 10 anni circa una macchina di fixture su sei nasce **già obsoleta**. Se il
 * digest facesse anche questo lavoro, l'`assertNothingSent()` di
 * `NotificaScadenzeTest` diventerebbe rosso una volta su sei — cioè il tipo di
 * rottura che si attribuisce al caso e non al proprio commit.
 *
 * ## ⚠️ `--senza-invio` è obbligatorio al primo avvio su dati esistenti
 *
 * Il comando avvisa degli **attraversamenti**, ma alla prima esecuzione non ha
 * memoria: ogni macchina già obsoleta è un attraversamento mai notificato. Sul
 * solo database di sviluppo sarebbe una lista di centinaia di righe, illeggibile
 * e destinata alla cartella spam nel momento peggiore. Con l'opzione il log si
 * popola in silenzio e dal giorno dopo arrivano solo le novità vere. Non è una
 * scorciatoia di sviluppo: è il primo passo del deploy, e va scritto nella
 * procedura — esattamente come per il digest.
 */
class NotificaObsolescenza extends Command
{
    protected $signature = 'easylab:notifica-obsolescenza
        {--senza-invio : Registra gli avvisi senza notificare nessuno (primo avvio)}';

    protected $description = 'Avvisa gli Enti delle macchine che hanno superato la soglia di età (ADR-014)';

    public function handle(): int
    {
        $senzaInvio = (bool) $this->option('senza-invio');

        foreach (EntiNotificabili::tutti() as $ente) {
            $esito = AvvisiObsolescenza::perEnte($ente, $senzaInvio);

            if ($esito === ['nuovi' => 0, 'tornateNuove' => 0, 'destinatari' => 0]) {
                continue;
            }

            $this->info(sprintf(
                'Ente «%s»: %d nuove obsolete, %d tornate sotto soglia, a %d destinatari.%s',
                $ente->nome,
                $esito['nuovi'],
                $esito['tornateNuove'],
                $esito['destinatari'],
                $senzaInvio ? ' [solo registrazione]' : '',
            ));
        }

        return self::SUCCESS;
    }
}
