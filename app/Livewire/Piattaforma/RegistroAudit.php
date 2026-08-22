<?php

namespace App\Livewire\Piattaforma;

use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 🔴 Il registro di audit della piattaforma (S6).
 *
 * `activity_log` ha dieci scrittori e — fino a questa pagina — **zero
 * lettori**: impersonazioni, login falliti, forzature semaforo, lockout,
 * accessi tecnici, scarichi di documenti, cambi di visibilità garanzie, ogni
 * scrittura di dominio del trait, e gli inviti non consegnati. ADR-012 lo
 * dichiara come gap: «nella stessa condizione di `failed_jobs`».
 *
 * **Perché una rotta a sé e non un tab della cabina.** Non è una preferenza:
 * `ElencaClienti` dichiara già `#[Url] search/sortBy/sortDir/perPage` e
 * `WithPagination` ha un `page` solo — due tabelle paginate nello stesso
 * componente **collidono**. In più la proprietà di sicurezza è per-URL: una
 * rotta separata ha il proprio assert strutturale sui middleware e i propri 403.
 *
 * ⚠️ **Gate su `tenants.view_all`, non su `audit.view`**, benché quest'ultimo
 * esista a catalogo e sembri fatto apposta. Due ragioni:
 * 1. `audit.view` **non è nel set bloccato** di `config/rbac.php`, quindi
 *    l'editor permessi di S6 potrà ridistribuirlo a chiunque: gatare una vista
 *    **cross-tenant** su un permesso ridistribuibile è una falla ad attivazione
 *    differita. `tenants.view_all` è nel set bloccato, e per questo regge.
 * 2. `audit.view` ce l'ha già **l'Admin** (`config/rbac.php`, non è fra le sue
 *    eccezioni), e lo Schema Ruoli §157 lo annota come limitato al proprio Ente:
 *    è il permesso della **futura vista per-cliente**, non di questa. Usarlo qui
 *    gliela toglierebbe di mano.
 *
 * Metterli in AND sarebbe peggio di uno solo: non aggiunge protezione (chi passa
 * il primo ha già il secondo) e crea un modo di rompere la pagina — revocare
 * `audit.view` al Superadmin dall'editor darebbe un 403 su una schermata di
 * piattaforma per una ragione che non c'entra col confine.
 *
 * ⚠️ **Attribuzione**: fino a `AuditLog::ATTRIBUZIONE_AFFIDABILE_DA` le righe
 * scritte durante un'impersonazione nominano l'**impersonato** e non chi agiva
 * davvero — lab404 sostituisce l'utente della guard, e `CauserResolver` legge
 * quello. Da quella data le righe scritte **dentro una richiesta** portano
 * `impersonato_da`; quelle differite (code, console, webhook) no, perché lì non
 * c'è una sessione da interrogare. Lo storico non è recuperabile, e la pagina
 * dichiara entrambi i limiti invece di lasciarli dedurre.
 *
 * **Guscio di proposito**, come `Cabina` prima di lui: una pagina che non mostra
 * ancora nulla ma è **già gatata** rende il gate dimostrabile prima che ci sia
 * qualcosa da proteggere. Tabella (blocco 2), filtri ed espansione (blocco 3).
 */
#[Layout('components.layouts.app')]
class RegistroAudit extends Component
{
    use WithPagination;

    public function render(): View
    {
        // La porta gira **a ogni render** anche finché la pagina è vuota: è ciò
        // che rende il 403 provabile senza middleware, cioè su `Livewire::test()`.
        VistaPiattaforma::audit();

        return view('livewire.piattaforma.registro-audit');
    }
}
