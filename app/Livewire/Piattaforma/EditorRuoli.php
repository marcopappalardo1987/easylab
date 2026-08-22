<?php

namespace App\Livewire\Piattaforma;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 🔴 L'editor della matrice ruolo→permesso (S6 — ADR-016).
 *
 * Per ora è un **guscio**: l'intestazione e nient'altro. È voluto, ed è la
 * stessa sequenza con cui sono nate `Cabina` e `RegistroAudit` — una pagina che
 * non mostra nulla ma è già gatata rende il gate **dimostrabile prima** che ci
 * sia qualcosa da proteggere; l'ordine opposto mette la guardia addosso a una
 * vista già scritta, e la prova diventa «non sembra rotto». La regola che la
 * pagina governerà esiste già e regge da sola (`App\Support\Rbac\MatriceRuoli`),
 * senza pagina: la griglia arriva dopo, sopra due cose provate.
 *
 * ## Il gate: `roles.manage`, cioè l'**opposto** della scelta del registro di
 * audit, per lo stesso ragionamento
 *
 * `RegistroAudit` rifiutò `audit.view` — che pure esiste a catalogo e sembra
 * fatto apposta — perché **non è nel set bloccato**, quindi questo editor potrà
 * ridistribuirlo: gatare una vista cross-tenant su un permesso ridistribuibile è
 * una falla ad attivazione differita. Qui vale lo stesso criterio con esito
 * rovesciato: `roles.manage` **è** nel set bloccato (`config/rbac.php`), quindi
 * non è ridistribuibile da questa stessa pagina. Il criterio, formulato una
 * volta per entrambe: **si gata su un permesso del set bloccato**. Il registro ci
 * arrivò per esclusione, qui ci si arriva per elezione.
 *
 * È anche il permesso che ADR-016 nomina per questa UI («Accesso: solo chi ha
 * `roles.manage` — esso stesso bloccato per evitare auto-delega») e quello che il
 * progetto **usa già come gate di una scrittura**, in
 * `FissaVisibilitaSede::fissaVisibilita()`.
 *
 * ⚠️ **Niente AND con `tenants.view_all`**, per la ragione già scritta nel
 * registro: non aggiunge protezione (chi passa il primo ha già il secondo) e
 * crea un modo di **rompere** la pagina. Oggi i due permessi appartengono agli
 * stessi due ruoli e sono **entrambi bloccati**, quindi non possono divergere per
 * mano di questo editor — la circolarità è il perno della feature: *il gate di
 * questa pagina è protetto dalla regola che questa pagina implementa*.
 *
 * ## Perché una rotta propria, e non un tab della cabina
 *
 * Delle due ragioni del registro, la prima qui **non** si applica: la matrice
 * non è paginata e non dichiara `#[Url]`, quindi non collide con il `page` unico
 * di `ElencaClienti`. La seconda sì, ed è più forte che là: la proprietà di
 * sicurezza è **per-URL**. Dentro `Cabina` le 324 scritture vivrebbero sotto una
 * rotta il cui `can:` dice `tenants.view_all`, mentre il permesso di questa
 * feature è `roles.manage` — e per un'azione che scrive «la guardia che regge è
 * quella di **rotta**» (ADR-018, nota corretta dopo essersi contraddetta). Un tab
 * lascerebbe come unica difesa il `Gate::authorize()` in azione, cioè una guardia
 * sola dove il progetto ne vuole due.
 *
 * ## Perché `Gate::authorize()` in testa a `render()`
 *
 * `RegistroAudit` non ne ha bisogno perché il suo `render()` passa da
 * `VistaPiattaforma::audit()`, e il permesso si chiede **dentro la porta**. Qui
 * porta non c'è e non deve esserci: `roles` e `permissions` sono tabelle
 * **globali**, senza tenancy, quindi non c'è alcuno scope da togliere e un
 * `VistaPiattaforma::ruoli()` sarebbe il «bypass finto» che il docblock della
 * porta rifiuta per nome. Il gate va quindi scritto qui, esplicitamente: senza,
 * il montaggio diretto del componente — `Livewire::test()`, che **disabilita i
 * middleware** — non incontrerebbe nessuna guardia.
 *
 * La rotta sta **dentro** il gruppo `['auth','account.lockout','two-factor.enforce']`
 * per la ragione già scritta per `/piattaforma`: il Superadmin è un utente
 * tenant-bound con un account proprio, e se quell'account fosse in lockout deve
 * vedere `/bloccato` come chiunque.
 *
 * 🔗 ADR-016 (RBAC e UI di gestione), ADR-018 (tenancy senza bypass, `can:` di
 * rotta sulle azioni), `App\Support\Rbac\MatriceRuoli` (la regola).
 */
#[Layout('components.layouts.app')]
class EditorRuoli extends Component
{
    /**
     * Il permesso che apre la pagina, in un posto solo.
     *
     * Lo leggono la rotta (`can:`), la voce di `x-piattaforma.nav` e i test
     * strutturali: stessa forma di `VistaPiattaforma::PERMESSO`, e per la stessa
     * ragione — tre stringhe uguali scritte in tre file sono tre occasioni di
     * gatare la pagina su un permesso e la voce di menù su un altro.
     */
    public const PERMESSO = 'roles.manage';

    public function render(): View
    {
        // La guardia gira **a ogni render**, prima di qualunque lettura: è ciò
        // che rende il 403 provabile senza middleware. Il `can:` di rotta resta
        // la guardia larga (ed è quella che vale sugli update Livewire, dove
        // un'azione con `skipRender()` non arriverebbe mai qui).
        Gate::authorize(self::PERMESSO);

        return view('livewire.piattaforma.editor-ruoli');
    }
}
