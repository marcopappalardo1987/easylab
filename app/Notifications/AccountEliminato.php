<?php

namespace App\Notifications;

use App\Support\Mail\MarchioEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'avviso a chi aveva un contratto che è stato **eliminato** (🔗 ADR-040).
 *
 * ⛔ **Si manda DOPO l'eliminazione, mai prima.** Prima significherebbe
 * annunciare la fine di un contratto che potrebbe ancora fallire a metà — e la
 * peggiore delle due direzioni: un cliente che legge «i tuoi dati non ci sono
 * più» e li ritrova al login perderebbe fiducia in ogni messaggio successivo.
 *
 * ⚠️ **`Notification::route('mail', $email)` e non `$user->notify()`**: quando
 * questa parte, l'utente non esiste più — è stato eliminato insieme al
 * contratto. Gli indirizzi vanno raccolti prima e passati a mano, come già fa
 * `AccountGiaEsistente` per un'altra ragione.
 *
 * **Non `ShouldQueue`.** Il chiamante la avvolge in `rescue()`, e accodarla
 * significherebbe che un job fallito prova a notificare un account che non c'è
 * più — un `failed()` senza nessuno a cui riferirsi. La spesa è dentro un gesto
 * amministrativo raro, non in un percorso di traffico.
 *
 * ⚠️ **Nessun dettaglio di ciò che c'era dentro**: né quanti strumenti, né
 * quante sedi. Chi legge sa già cosa aveva, e l'elenco in un'email che non si
 * può richiamare indietro sarebbe un inventario dei propri dati spedito in
 * chiaro. I conteggi stanno nel registro di audit, dove servono davvero.
 */
class AccountEliminato extends Notification
{
    public function __construct(public readonly string $ragioneSociale) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Easy Lab · Il tuo account è stato chiuso')
            ->markdown('mail.account-eliminato', [
                'marchio' => MarchioEmail::piattaforma(),
                'ragioneSociale' => $this->ragioneSociale,
            ]);
    }
}
