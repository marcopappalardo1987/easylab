<?php

namespace App\Livewire\Tenancy;

use App\Models\Scopes\DepartmentScope;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

/**
 * Il contesto Ente in top bar, con lo switcher fra le proprie sedi (ADR-032).
 *
 * NON è il «selettore di tenant» che ADR-018 ha scartato: quello apriva un
 * secondo percorso di accesso verso dati ALTRUI, questo naviga fra Enti dello
 * stesso account — il confine che lì andava protetto qui non esiste, e la
 * guardia vera sta comunque in `User::passaAllEnte()`, non in questa UI.
 *
 * Terzo componente Livewire annidato del progetto (disciplina della
 * Campanella): si monta su ogni pagina, quindi `mount()` fa due sole letture —
 * il nome dell'Ente corrente e il conteggio delle sedi raggiungibili.
 * L'elenco arriva solo a tendina aperta. Per chi ha una sede sola il
 * componente degrada al solo nome, che è già un guadagno: finora il Design
 * System §5.7 prometteva il «contesto (Ente/ruolo)» e la top bar non lo
 * mostrava affatto.
 *
 * Durante l'impersonazione il nome resta (è contesto utile proprio a chi
 * impersona) ma la tendina è soppressa: il tenant seguito è quello
 * dell'impersonato, e il dominio rifiuterebbe comunque — la UI nasconde, il
 * dominio nega.
 */
class SwitcherEnte extends Component
{
    public bool $aperto = false;

    public ?string $nomeEnte = null;

    /** Sedi raggiungibili oltre a quella corrente; governa la tendina. */
    public int $sediRaggiungibili = 0;

    public function mount(): void
    {
        $user = $this->user();

        if ($user->tenant_id === null) {
            return; // tecnico esterno / utente di piattaforma: nessun contesto Ente.
        }

        // 🔴 **Si legge `CurrentTenant::id()`, non `$user->tenant_id`.**
        // Da quando lo spostamento durante un'impersonazione è effimero (vive
        // in sessione, non sull'utente) le due cose divergono: `tenant_id`
        // resta la sede di partenza mentre i dati mostrati sono già quelli
        // della sede scelta. Leggendo la colonna, lo switcher diceva il nome
        // della sede SBAGLIATA e offriva come «altra» proprio quella in cui ci
        // si trovava — mentre quella da cui si era partiti spariva
        // dall'elenco, cioè non si poteva tornare indietro. Segnalato da Marco
        // il 28 Ago 2026, ed era un difetto introdotto dallo spostamento
        // effimero stesso.
        //
        // `CurrentTenant` è il risolutore da cui dipendono già tutti i global
        // scope: qui si guarda la stessa verità che vede il resto della pagina.
        $corrente = CurrentTenant::id();

        // ⚠️ **Nessun bypass, e non è un caso**: un nodo Ente porta come
        // `tenant_id` il proprio id, quindi il `TenantScope` — che filtra
        // proprio su `CurrentTenant::id()` — lascia passare esattamente la
        // sede in cui si è adesso, e nient'altro. È la query più stretta
        // possibile, e il soft delete resta applicato.
        //
        // Due strade sbagliate già percorse, entrambe scartate per una ragione:
        // `withoutGlobalScopes()` nudo toglierebbe anche il soft delete
        // (`BypassNudiGuardrailTest` lo rende rosso, ed è giusto), e leggere da
        // `sediRaggiungibili()` era troppo stretto — un utente la cui sede non
        // passa dal pivot del contratto perdeva del tutto l'etichetta.
        // T2 (S7): tolto il solo DepartmentScope, per nome. Il Responsabile
        // non ha la radice dell'Ente nel proprio sotto-albero, e la query
        // scopata gli toglieva l'etichetta. Il TenantScope resta: l'id viene
        // da `CurrentTenant`, e il confine fra clienti non si muove.
        $this->nomeEnte = UnitaOrganizzativa::withoutGlobalScope(DepartmentScope::class)
            ->whereKey($corrente)->value('nome');

        $this->sediRaggiungibili = $this->sedi()->where('id', '!=', $corrente)->count();
    }

    public function apri(): void
    {
        $this->aperto = true;
    }

    /**
     * L'apertura e la chiusura passano dal server perché il pannello è reso da
     * un `@if`: non c'è nessuno stato nel browser che possa perdersi.
     */
    public function alterna(): void
    {
        $this->aperto = ! $this->aperto;
    }

    public function chiudi(): void
    {
        $this->aperto = false;
    }

    public function passa(int $enteId): void
    {
        $ente = UnitaOrganizzativa::withoutGlobalScopes()->find($enteId);

        if ($ente === null) {
            return; // fail-closed silenzioso: la UI non offre bersagli illegittimi.
        }

        // 🔴 Impersonando, lo spostamento è EFFIMERO: vive nella sessione di
        // chi impersona e non tocca `users.tenant_id`. `passaAllEnte()`
        // continua a rifiutare — ed è giusto che rifiuti: quella scrive, e
        // scrivere qui sarebbe una modifica permanente fatta per conto del
        // cliente, che si ritroverebbe al prossimo accesso in una sede che non
        // ha scelto lui.
        //
        // ⛔ La legittimità del bersaglio si verifica QUI, dove la chiave si
        // scrive: dev'essere una sede raggiungibile dall'impersonato, cioè
        // dello stesso account e non in lockout. `CurrentTenant` a valle non
        // ricontrolla — costerebbe una query per richiesta — quindi questa
        // riga è l'unica guardia e non può essere allentata.
        if ($this->impersonando()) {
            $legittima = $this->sedi()->whereKey($ente->id)->exists();

            if (! $legittima) {
                return;
            }

            session([CurrentTenant::SEDE_IMPERSONATA => [
                'utente' => $this->user()->getKey(),
                'ente' => $ente->id,
            ]]);

            // L'attraversamento di un confine si registra, come l'impersonazione
            // stessa e lo switch permanente: il causer è l'impersonato (è chi la
            // guard espone), e la riga dell'impersonazione dice chi c'è dietro.
            activity(AuditLog::NAME)
                ->causedBy($this->user())
                ->performedOn($ente)
                ->withProperties(['effimero' => true, 'impersonazione' => true])
                ->log('sede.cambiata');

            $this->redirect($this->doveTornare());

            return;
        }

        if (! $this->user()->passaAllEnte($ente)) {
            return;
        }

        // Full reload, non navigate SPA: il cambio di tenant cambia tutto —
        // sidebar, liste, scope.
        $this->redirect($this->doveTornare());
    }

    /**
     * 🔴 **Dove si atterra dopo aver cambiato sede: la STESSA pagina, se ha
     * senso anche nella sede nuova; la dashboard altrimenti.**
     *
     * Chiesto da Marco il 28 Ago 2026 — «vorrei restare nella pagina in cui ho
     * switchato». Fino a quel giorno si tornava sempre in dashboard, e la
     * ragione scritta era vera solo a metà: «la pagina da cui si parte
     * appartiene al tenant appena lasciato». Vale per `/strumenti/42`, dove il
     * `42` è una macchina dell'altra sede e dopo il cambio darebbe un 404 dal
     * messaggio incomprensibile. NON vale per `/documenti`, `/scadenzario`,
     * `/strumenti`: sono domande che ogni sede sa rispondere per sé, ed è
     * proprio lì che si passa di sede per confrontare.
     *
     * Il criterio è quindi meccanico e non un elenco da tenere aggiornato a
     * mano: **se la rotta ha parametri, si torna in dashboard**. Un parametro è
     * sempre l'id di qualcosa che apparteneva alla sede lasciata.
     *
     * ⚠️ **La query string si perde, ed è voluto**: un filtro come
     * `?strumentoId=7` nomina una macchina dell'altra sede, e portarselo dietro
     * mostrerebbe un elenco vuoto con un filtro attivo che non si capisce — il
     * difetto che l'archivio documenti aveva già avuto.
     *
     * ⛔ Si accetta solo un percorso della NOSTRA applicazione: un `Referer` è
     * un'intestazione che arriva dal browser, e rimandarci sopra senza
     * verificare sarebbe una redirezione aperta.
     */
    private function doveTornare(): string
    {
        $precedente = url()->previous();

        if (! str_starts_with($precedente, url('/'))) {
            return route('dashboard');
        }

        $percorso = '/'.ltrim((string) parse_url($precedente, PHP_URL_PATH), '/');

        try {
            $rotta = app('router')->getRoutes()
                ->match(Request::create($percorso, 'GET'));
        } catch (Throwable) {
            return route('dashboard');
        }

        // ⚠️ Serve ANCHE un nome: le rotte senza nome di questa applicazione
        // sono redirect di servizio (la radice `/`), e rimandarci sopra
        // significherebbe atterrare su un rimbalzo invece che su una pagina.
        return $rotta->parameterNames() === [] && $rotta->getName() !== null
            ? url($percorso)
            : route('dashboard');
    }

    /**
     * La stessa domanda della pagina /bloccato («dove posso ancora andare?»),
     * la stessa risposta: la query vive in User::sediRaggiungibili() (ADR-013).
     *
     * @return Builder<UnitaOrganizzativa>
     */
    private function sedi()
    {
        return $this->user()->sediRaggiungibili();
    }

    /**
     * 🔴 **L'elenco si calcola SEMPRE, e non più solo a tendina aperta.**
     *
     * La versione pigra era una scelta di costo — questo componente si monta su
     * ogni pagina — ma il prezzo era che il pannello **non si apriva**: il click
     * chiedeva l'elenco al server, il server rispondeva, Livewire ridisegnava il
     * frammento e Alpine ripartiva da capo, richiudendolo. Due tentativi di
     * tenere lo stato al di là del ridisegno non hanno funzionato sull'ambiente
     * vero; la strada giusta era togliere il ridisegno, non sopravvivergli.
     *
     * Il costo reale è **una query in più**, di soli `id` e `nome`, e **solo per
     * chi ha più di una sede** — cioè una minoranza dei clienti: `mount()`
     * calcola già `sediRaggiungibili`, e dove quel conteggio è zero la tendina
     * non esiste e questo metodo non viene mai chiamato dalla vista.
     *
     * In cambio la tendina si comporta come il menù utente, che funziona da
     * sempre: apertura e chiusura tutte nel browser, nessuna chiamata al server
     * finché non si sceglie davvero una sede.
     *
     * @return Collection<int, UnitaOrganizzativa>
     */
    public function altreSedi(): Collection
    {
        // Torna pigro: il pannello è reso da un `@if ($aperto)`, quindi il giro
        // sul server c'è comunque nel gesto di aprire. Caricare l'elenco su
        // ogni pagina — come nel tentativo del 28 Ago — sarebbe stato un costo
        // pagato per una cura che non curava.
        if (! $this->aperto) {
            return new Collection;
        }

        return $this->sedi()
            // Stessa ragione di `mount()`: si esclude la sede in cui si è
            // ADESSO, che durante un'impersonazione non è `tenant_id`.
            ->where('id', '!=', CurrentTenant::id())
            ->orderBy('nome')
            ->get(['id', 'nome']);
    }

    public function impersonando(): bool
    {
        return app('impersonate')->isImpersonating();
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render()
    {
        return view('livewire.tenancy.switcher-ente', [
            'altreSedi' => $this->altreSedi(),
            // 🔴 La tendina vive anche durante un'impersonazione (28 Ago 2026):
            // chi impersona un cliente con più sedi deve poterle vedere tutte —
            // è la ragione per cui impersona. Lo spostamento che ne segue è
            // effimero e non tocca il contesto del cliente (vedi `passa()`).
            'tendina' => $this->sediRaggiungibili > 0,
        ]);
    }
}
