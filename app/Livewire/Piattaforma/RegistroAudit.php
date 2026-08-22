<?php

namespace App\Livewire\Piattaforma;

use App\Models\User;
use App\Support\Audit\SoggettiAudit;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
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
 * ⚠️ **Il costo è piatto sulle righe, lineare sui tipi in pagina.** Una query
 * per `activity_log`, una per il conteggio, una per il causer, e **una per ogni
 * tipo di soggetto presente** più quelle delle relazioni annidate: con sei tipi
 * sono dieci query, con dieci circa quindici. È il prezzo del `morphTo`, ed è
 * quello giusto — ma il nome del test («piatto da due righe a quaranta») invita
 * a leggerlo come costante, e non lo è.
 *
 * **Guscio di proposito**, come `Cabina` prima di lui: una pagina che non mostra
 * ancora nulla ma è **già gatata** rende il gate dimostrabile prima che ci sia
 * qualcosa da proteggere. Tabella (blocco 2), filtri ed espansione (blocco 3).
 */
#[Layout('components.layouts.app')]
class RegistroAudit extends Component
{
    use WithPagination;

    #[Url]
    public string $sortDir = 'desc';

    private const PER_PAGE = 25;

    /**
     * Inverte l'ordine cronologico.
     *
     * È l'**unico** ordinamento offerto, e non è una semplificazione: un
     * registro è cronologico, e ordinarlo per descrizione o per soggetto
     * risponderebbe a una domanda che nessuno si pone davanti a un audit. La
     * direzione invece è una scelta vera — «dal più vecchio» serve a ricostruire
     * una sequenza.
     */
    public function inverti(): void
    {
        $this->resetPage();

        $this->sortDir = $this->direzione() === 'desc' ? 'asc' : 'desc';
    }

    /**
     * La direzione **effettivamente applicata**.
     *
     * La vista non deve leggere `$sortDir`: arriva dalla query string e può
     * valere qualunque cosa, mentre la query ha usato il fallback. Una freccia
     * che indica il contrario dell'ordine applicato è una bugia piccola, e per
     * questo credibile.
     */
    public function direzione(): string
    {
        return mb_strtolower($this->sortDir) === 'asc' ? 'asc' : 'desc';
    }

    public function render(): View
    {
        // La porta gira **a ogni render**, anche prima di paginare: è ciò che
        // rende il 403 provabile senza middleware, cioè su `Livewire::test()`.
        $direzione = $this->direzione();

        $righe = VistaPiattaforma::audit()
            ->orderBy('created_at', $direzione)
            // ⚠️ **Il tie-break non è prudenza.** I timestamp si serializzano al
            // secondo, e login e impersonazione vengono scritti nella *stessa*
            // richiesta: i pari sono la norma. Senza, la paginazione perde e
            // ripete righe fra una pagina e l'altra — e su un registro una riga
            // persa è precisamente ciò che non deve succedere.
            ->orderBy('id', $direzione)
            ->paginate(self::PER_PAGE)
            ->onEachSide(1);

        // ⚠️ L'eager load si applica alle **sole righe risolvibili**, e non con
        // un `with()` sulla query: `MorphTo::getEager()` itera i tipi presenti e
        // `createModelByType()` va in **fatal** su una classe che non esiste
        // più. `activity_log` è append-only e sopravvive alle proprie classi
        // (`LetturaContaore` è stata cancellata in S3): una riga orfana
        // basterebbe a far cadere l'intera pagina.
        //
        // Le righe escluse restano con la relazione **non caricata**, ed è per
        // questo che `SoggettiAudit::etichetta()` non tocca mai `->subject`
        // senza aver prima verificato la classe: altrimenti il lazy load
        // rifarebbe esattamente il fatal che questa riga evita.
        SoggettiAudit::righeRisolvibili($righe->getCollection())
            ->load(['subject' => SoggettiAudit::vincolo()]);

        // ⚠️ **Anche il causer**, o la colonna «Chi» fa una query per riga —
        // venticinque per pagina. Non serve togliergli scope (`User` non ne ha,
        // ed è dichiarato in `TenantScopeGuardrailTest::NON_TENANT_MODELS`),
        // serve solo caricarlo. Vale lo stesso filtro del soggetto: `causer` è
        // un `morphTo` come l'altro, e oggi punta sempre a `User`, ma «oggi» non
        // è una garanzia su una tabella che sopravvive alle proprie classi.
        SoggettiAudit::conCauserRisolvibile($righe->getCollection())->load('causer');

        // «per conto di» deve dire un **nome**, non un id: la verifica del piano
        // chiede di leggere «attribuita all'Admin con per conto di il
        // Developer», e «#12» non è quello. Una query sola per pagina, sugli id
        // raccolti — resta O(1).
        $impersonatori = User::whereIn(
            'id',
            $righe->getCollection()->map(fn ($r) => $r->properties?->get('impersonato_da'))->filter()->unique()
        )->pluck('name', 'id');

        return view('livewire.piattaforma.registro-audit', [
            'righe' => $righe,
            'direzione' => $direzione,
            'impersonatori' => $impersonatori,
        ]);
    }
}
