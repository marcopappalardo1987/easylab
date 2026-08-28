<?php

namespace App\Notifications;

use App\Support\Mail\MarchioEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * 🔴 La risposta all'enumerazione degli indirizzi (🔗 ADR-012).
 *
 * ## Il problema che questa classe esiste per risolvere
 *
 * `/registrati` è **pubblica e non autenticata**. Se il modulo rispondesse
 * «questa email è già registrata», chiunque potrebbe scoprire, un indirizzo
 * alla volta, **chi è cliente di Easy Lab** — l'anagrafica commerciale, senza
 * autenticarsi e senza lasciare traccia riconoscibile. È la stessa ragione per
 * cui il recupero password di Laravel risponde sempre allo stesso modo.
 *
 * ## La forma della risposta, e perché è l'unica che regge
 *
 * Il modulo risponde **identico** nei due casi: stesso redirect, stesso
 * messaggio, stessa pagina «controlla la posta». Ciò che cambia è **soltanto
 * cosa arriva nella casella**, che è raggiungibile solo da chi la possiede:
 *
 * · email nuova    → `VerificaEmailRegistrazione`, col link;
 * · email già nota → **questa**, che dice «esisti già, entra da qui».
 *
 * ⛔ E nel secondo caso **non si crea nessuna riga** `registrazioni`: la
 * risposta è indistinguibile, ma il database non registra il tentativo.
 *
 * ⚠️ **Nessun link firmato e nessun dato dell'account.** Non dice quale Ente,
 * non dice quale piano, non dice quando: dice «se sei tu, la porta è /login, e
 * se hai perso la password c'è il recupero». Un'email che confermasse dettagli
 * a un mittente non verificato sarebbe la stessa fuga, spostata di un passo.
 *
 * **Non `ShouldQueue`**, e qui la scelta si rovescia rispetto alle altre due
 * notifiche di questo blocco: il rate limiting della registrazione è per IP e
 * per email, ma un mittente ostile che prova mille indirizzi genererebbe
 * comunque una coda di mille job. Restando sincrona, la spesa è dentro la
 * richiesta che il limiter già stringe, e non c'è nessuna coda da riempire.
 * Il prezzo — l'SMTP nel percorso della richiesta — è pagato dal chiamante, che
 * la incapsula in un `rescue()` per la ragione dichiarata lì.
 */
class AccountGiaEsistente extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Easy Lab · Hai già un account')
            ->markdown('mail.account-gia-esistente', [
                'marchio' => MarchioEmail::piattaforma(),
                'login' => Route::has('login') ? route('login') : config('app.url'),
                'recupero' => Route::has('password.request') ? route('password.request') : config('app.url'),
            ]);
    }
}
