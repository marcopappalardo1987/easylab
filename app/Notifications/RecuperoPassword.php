<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Il link per reimpostare la password, mandato IN CODA come le altre email
 * dell'applicazione (🔗 CatalogoEmail::RECUPERO_PASSWORD).
 *
 * 🔴 Fino al 23 Nov 2026 partiva dentro la richiesta, perché è la notifica di
 * Laravel così com'è: se il server di posta non rispondeva, chi chiedeva il
 * link vedeva un errore 500. E quel 500 arrivava solo per gli indirizzi
 * registrati, quindi diceva a chiunque chi è cliente: proprio ciò che la
 * risposta uniforme di `FortifyServiceProvider` esiste per tacere (S7). In coda
 * la pagina risponde sempre allo stesso modo, e un invio fallito resta fra i
 * job falliti e nel registro degli errori, dove si vede.
 *
 * Il contenuto è quello di Laravel: qui cambia solo quando parte.
 */
class RecuperoPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
