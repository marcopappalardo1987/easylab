<?php

namespace App\Livewire\Piattaforma\Concerns;

use App\Models\Account;
use App\Models\User;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * L'impersonazione dalla cabina di regia (S6 — Wireframe §4, DS §5.8).
 *
 * **Si impersona una persona, non un contratto.** L'Account è un rapporto
 * commerciale: non ha una sessione, non ha permessi, non ha un Ente attivo. La
 * riga della tabella bersaglia quindi un **membro**, e quando i membri sono più
 * d'uno la scelta è esplicita invece che implicita — «il primo» sarebbe una
 * decisione presa dall'ordinamento di una query.
 *
 * ⚠️ **L'ingresso è un `<a href>` GET, non un'azione Livewire**, e non è una
 * scorciatoia: `take()` sostituisce l'utente in sessione, e una risposta
 * Livewire lascerebbe in pagina un componente **montato per l'utente
 * precedente** — con il suo scope, i suoi permessi già risolti e il suo Ente.
 * Il giro completo dal server è ciò che rende lo scambio osservabile.
 *
 * ⚠️ **Rischio dichiarato e accettato** (ADR-016, nota del 21 Ago 2026):
 * `GET /impersonate/take/{id}` non porta token CSRF, quindi una pagina esterna
 * può far navigare lì un Superadmin già connesso. L'attaccante non legge la
 * risposta (same-origin), il gesto è loggato e il banner è visibile a schermo.
 * Chiusura nel security pass di S7.
 *
 * ⚠️ **Il filtro dei candidati è ergonomia, non autorizzazione.** La rotta
 * accetta qualunque id, e va bene così: `utenti.impersonate` è nel set 🔒 e
 * appartiene ai soli Developer e Superadmin, che dalla cabina vedono già tutto
 * — ADR-018 dice che l'accesso cross-tenant *è* l'impersonazione. Chi volesse
 * «mettere in sicurezza» quella rotta con uno scoping starebbe risolvendo un
 * problema che non c'è, e ne creerebbe uno vero al Developer.
 */
trait OffreImpersonazione
{
    /** L'account di cui è aperta la scelta del membro, o `null`. */
    public ?int $sceltaImpersonazione = null;

    /**
     * Apre la scelta del membro da impersonare.
     *
     * ⚠️ **Chiede `utenti.impersonate` qui**, e non solo nel `@can` che avvolge
     * il bottone. `VistaPiattaforma` gata su `tenants.view_all`, che è un
     * permesso **diverso**: stare nella cabina non è poter entrare in casa di un
     * cliente. Senza questa riga la sola guardia vivrebbe nel Blade — il posto
     * più facile da aggirare — e l'azione raggiungerebbe nome ed email dei
     * membri di un altro tenant.
     *
     * Rilegge poi l'account dalla porta, come ogni altra azione che accetta un
     * id: `Account` non ha global scope e `membri()` è un `belongsToMany` **non
     * scopato**, cioè la relazione da cui si arriva a un utente qualunque della
     * piattaforma partendo da un id inventato.
     */
    public function apriScelta(int $accountId): void
    {
        Gate::authorize('utenti.impersonate');

        VistaPiattaforma::accounts()->whereKey($accountId)->firstOrFail();

        // Simmetrico ad `apriLockout`/`apriFiscali`: una modale alla volta.
        $this->chiudiOgniModale();
        $this->sceltaImpersonazione = $accountId;
    }

    public function chiudiScelta(): void
    {
        $this->sceltaImpersonazione = null;
    }

    /** L'altra strada per aprirla: la property, che Livewire accetta dal browser. */
    public function updatingSceltaImpersonazione(mixed $valore): void
    {
        if ($valore !== null) {
            Gate::authorize('utenti.impersonate');

            VistaPiattaforma::accounts()->whereKey($valore)->firstOrFail();
        }
    }

    /**
     * Il cliente di cui è aperta la scelta, **riletto dalla porta**.
     *
     * ⚠️ Non si pesca dalla pagina corrente. Se lo si facesse, filtrare o
     * cambiare pagina con la modale aperta lascerebbe a schermo un guscio: il
     * titolo troncato («Impersona un membro di ») e la lista vuota, perché quel
     * cliente non è più fra le venti righe caricate. È uno stato che si
     * raggiunge da soli — si apre la modale, si ripensa, si digita nella
     * ricerca dietro. Una query in più, e solo quando la modale è aperta.
     */
    public function clienteScelto(): ?Account
    {
        if ($this->sceltaImpersonazione === null || ! Gate::allows('utenti.impersonate')) {
            return null;
        }

        // ⚠️ `accountsInclusaPiattaforma()` e non `accounts()`: la tabella dei
        // clienti esclude EasyLab perché «Clienti: 13» quando sono 12 è un
        // numero che qualcuno riporterebbe a un socio — ma l'impersonazione non
        // è un conteggio, è un gesto, e il Superadmin deve poter essere
        // impersonato dal Developer (🔗 ADR-018: l'unico protetto è il
        // Developer, e `canBeImpersonated()` continua a dirlo).
        return VistaPiattaforma::accountsInclusaPiattaforma()
            ->with('membri.roles')
            ->find($this->sceltaImpersonazione);
    }

    /**
     * L'account di piattaforma — EasyLab stessa — coi suoi membri impersonabili.
     *
     * 🔴 Esiste perché la tabella della cabina elenca i **clienti**, e il
     * Superadmin non è un cliente: senza questo, il Developer non aveva
     * nessun modo di impersonarlo dall'interfaccia. Segnalato da Marco il
     * 28 Ago 2026.
     *
     * Sta fuori dalla tabella e non dentro, di proposito: mescolarlo alle righe
     * dei clienti rimetterebbe EasyLab nei conteggi da cui è stata tolta
     * apposta.
     *
     * @return array{account: ?Account, candidati: Collection<int,User>}|null
     */
    public function piattaformaImpersonabile(): ?array
    {
        if (! Gate::allows('utenti.impersonate')) {
            return null;
        }

        $account = VistaPiattaforma::accountsInclusaPiattaforma()
            ->with('membri.roles')
            ->where('di_piattaforma', true)
            ->first();

        // 🔴 **Si torna qualcosa anche quando non c'è nessuno da impersonare.**
        // La prima stesura restituiva `null` e la striscia spariva: Marco ha
        // chiesto «non vedo nessun superadmin, non esiste in staging o è un
        // difetto?» — e quella è esattamente la domanda che un'assenza muta
        // costringe a fare. Un'interfaccia che nasconde non distingue «non c'è»
        // da «è rotto».
        //
        // Il Superadmin esiste solo se `SUPERADMIN_EMAIL` e `SUPERADMIN_PASSWORD`
        // erano valorizzate al primo seeding: senza, `SuperadminSeeder` si salta
        // da sé, in silenzio. È un'informazione che serve a chi guarda questa
        // pagina, ed è la sua pagina.
        if ($account === null) {
            return ['account' => null, 'candidati' => new Collection];
        }

        return ['account' => $account, 'candidati' => $this->candidatiDi($account)];
    }

    /**
     * I membri impersonabili degli account **in pagina**, per account.
     *
     * Due query fisse — pivot e ruoli — invece di due per riga: `hasRole()`
     * carica la relazione `roles`, quindi senza l'eager load il filtro sarebbe
     * un N+1 mascherato da `filter()` in PHP, cioè il tipo di N+1 che non si
     * vede leggendo il codice.
     *
     * Non gira affatto per chi non potrà mai impersonare: due query e un giro di
     * filtri per costruire un elenco che il `@can` in vista butta via.
     *
     * @param  Collection<int,Account>  $pagina
     * @return Collection<int,Collection<int,User>>
     */
    private function candidatiDellaPagina(Collection $pagina): Collection
    {
        if (! Gate::allows('utenti.impersonate')) {
            return $pagina->mapWithKeys(fn (Account $a) => [$a->id => collect()]);
        }

        $pagina->loadMissing('membri.roles');

        return $pagina->mapWithKeys(fn (Account $a) => [$a->id => $this->candidatiDi($a)]);
    }

    /**
     * Chi, di questo account, si può impersonare adesso.
     *
     * Il filtro sta in PHP e non in SQL di proposito: `canBeImpersonated()` è
     * **la** definizione di chi è protetto (oggi: il solo Developer), e
     * riscriverla come `whereDoesntHave('roles', …)` ne creerebbe una seconda
     * copia — che il giorno in cui la prima cambia resta indietro in silenzio.
     *
     * ⚠️ **Chi sta già impersonando non impersona nessuno.** Il caso è
     * raggiungibile: un Developer che impersona un Superadmin arriva alla
     * cabina — `@can` e il `can:` di rotta interrogano l'impersonato, che
     * `tenants.view_all` ce l'ha — e si troverebbe un pulsante «Impersona» che
     * porta a un **403 secco del pacchetto**, fuori da qualsiasi UI e senza
     * spiegazione. Meglio nessun pulsante che un pulsante che mente.
     *
     * @return Collection<int,User>
     */
    private function candidatiDi(Account $account): Collection
    {
        if (app('impersonate')->isImpersonating()) {
            return collect();
        }

        $io = auth()->id();

        return $account->membri
            ->filter(fn (User $u) => $u->canBeImpersonated() && $u->id !== $io)
            ->values();
    }

    /**
     * I candidati del cliente scelto, per la modale.
     *
     * @return Collection<int,User>
     */
    public function candidatiScelti(): Collection
    {
        $cliente = $this->clienteScelto();

        return $cliente === null ? collect() : $this->candidatiDi($cliente);
    }
}
