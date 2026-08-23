<?php

namespace App\Livewire\Piattaforma;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 🔴 L'error tracker interno — il guscio gatato (S6).
 *
 * 🔗 `docs/Architettura/Error Tracker Interno (piano).md`, ADR-016 (RBAC),
 * ADR-018 (tenancy senza bypass, `can:` di rotta sulle azioni).
 *
 * Le eccezioni PHP finiscono a database perché su Laravel Cloud `laravel.log`
 * vive su un disco **effimero e per-replica**, azzerato a ogni deploy e a ogni
 * risveglio da scale-to-zero: un errore visto da un cliente può non lasciare
 * nulla di consultabile. Questa è la pagina da cui lo si guarda.
 *
 * ⚠️ **In questo blocco la pagina non mostra nulla**: è un guscio con la sola
 * intestazione, e la cattura non esiste ancora. È deliberato, ed è la stessa
 * sequenza di `Cabina`, `RegistroAudit` ed `EditorRuoli` — una pagina vuota ma
 * già gatata rende il gate **dimostrabile prima** che ci sia qualcosa da
 * proteggere, mentre l'ordine opposto mette la guardia addosso a una vista già
 * scritta e la prova diventa «non sembra rotto». `AccessoErroriTest` è quindi
 * l'intero valore del blocco.
 *
 * ## Il permesso, e la prima pagina che il Superadmin non vede
 *
 * `system.logs.view` esiste a catalogo dalla S1, è nel **set bloccato** di
 * `config/rbac.php` — quindi l'editor della matrice non può darlo né toglierlo
 * a nessuno — ed è del **solo Developer**: il Superadmin ne è escluso per
 * eccezione esplicita (`'except' => ['system.logs.view']`), pur avendo tutti
 * gli altri permessi della piattaforma.
 *
 * ⚠️ **È la prima schermata del progetto che il Superadmin non può aprire**, e
 * con essa la prima in cui la partizione di `system.logs.view` **non coincide**
 * con quella di `tenants.view_all`. Ogni pagina di piattaforma nata finora ha
 * potuto permettersi di trattare le due cose come sinonimi; da qui in poi no —
 * ed è la ragione per cui `AccessoErroriTest` si scrive dataset propri invece
 * di riusare `RUOLI_CON_PIATTAFORMA` / `RUOLI_SENZA_PIATTAFORMA`.
 *
 * Non è un dettaglio di autorizzazione ma una scelta di prodotto: qui si legge
 * tutto ciò che si è rotto in **ogni** Ente, con dentro messaggi, percorsi e —
 * dai blocchi successivi — input di richiesta. È il gate più stretto del
 * progetto, e chi un giorno vorrà allargarlo trova il perché nel negativo
 * dedicato al Superadmin, non un 403 muto. Farlo è comunque **un commit su
 * `config/rbac.php` più un riseeding**, non un click: il permesso è bloccato.
 *
 * **Come ci arriva il Developer.** Nessuna seconda voce di sidebar: entra da
 * `/piattaforma` con `tenants.view_all` — che ha, avendo tutto — e trova la
 * **quarta voce** di sub-nav, filtrata sulla `PERMESSO` di questa classe. Il
 * Superadmin quella voce non la vede, perché una voce di menù che porta a un
 * 403 è un invito a bussare.
 *
 * ## Perché `Gate::authorize()` in testa a `render()`
 *
 * Per la ragione già scritta in `EditorRuoli`: `RegistroAudit` non ne ha
 * bisogno perché il suo `render()` passa da `VistaPiattaforma::audit()` e il
 * permesso si chiede **dentro la porta**. Qui porta non c'è e non deve
 * esserci — `errori` e `occorrenze_errore` sono tabelle **globali**, senza
 * tenancy, quindi non c'è nessuno scope da togliere e un
 * `VistaPiattaforma::errori()` sarebbe il «bypass finto» che il docblock della
 * porta rifiuta per nome. Il gate va quindi scritto qui, esplicitamente: senza,
 * il montaggio diretto del componente — `Livewire::test()`, che **disabilita i
 * middleware** — non incontrerebbe nessuna guardia.
 *
 * Il `can:` di rotta resta comunque, e non è ridondante: è la sola guardia che
 * regge sugli update Livewire, dove un'azione con `skipRender()` non arriva mai
 * a `render()`. Le tre azioni del blocco 6 (risolvi/ignora/riapri) sono
 * esattamente quel caso.
 *
 * La rotta sta **dentro** il gruppo `['auth','account.lockout','two-factor.enforce']`
 * per la ragione già scritta per `/piattaforma`: il Developer è un utente
 * tenant-bound con un account proprio (ADR-018), e se quell'account fosse in
 * lockout deve vedere `/bloccato` come chiunque.
 *
 * ⚠️ **Il file sta in `app/Livewire/Piattaforma/`, non in una sottocartella
 * propria.** `LocalizzazioneTest:77` deriva il proprio universo da un `glob()`
 * su `app/Livewire` con un doppio asterisco, e in PHP quel pattern **non è
 * ricorsivo**: si ferma a profondità 2. A profondità 3 questo file sfuggirebbe
 * al meta-test in silenzio — non lo farebbe fallire, glielo **toglierebbe**. È
 * la stessa trappola già colta sui due model in `SchemaErroriTest`.
 */
#[Layout('components.layouts.app')]
class Errori extends Component
{
    /**
     * Il permesso che apre la pagina, in un posto solo.
     *
     * Lo leggono la rotta (`can:`), la voce di `x-piattaforma.nav` e i test
     * strutturali: stessa forma di `EditorRuoli::PERMESSO` e per la stessa
     * ragione — tre stringhe uguali scritte in tre file sono tre occasioni di
     * gatare la pagina su un permesso e la voce di menù su un altro. Qui pesa
     * di più che altrove, perché è l'unico permesso di piattaforma la cui
     * partizione non coincide con `tenants.view_all`: una copia sbagliata
     * aprirebbe la pagina al Superadmin senza che nulla lo dica.
     */
    public const PERMESSO = 'system.logs.view';

    public function render(): View
    {
        // La guardia gira **a ogni render**, prima di qualunque lettura: è ciò
        // che rende il 403 provabile senza middleware.
        Gate::authorize(self::PERMESSO);

        return view('livewire.piattaforma.errori');
    }
}
