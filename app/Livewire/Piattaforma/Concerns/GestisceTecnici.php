<?php

namespace App\Livewire\Piattaforma\Concerns;

use App\Enums\TipoUnitaOrganizzativa;
use App\Livewire\Piattaforma\Tecnici;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Tenancy\VistaPiattaforma;
use App\Support\Utenti\InvitaUtente;
use App\Support\Utenti\InvitoRifiutato;
use App\Support\Utenti\RuoliAssegnabili;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * I gesti che **scrivono** su `/piattaforma/tecnici` (🔗 ADR-038).
 *
 * Tre: invitare un tecnico di EasyLab, dargli (o togliergli) i **clienti su cui
 * lavora**, e farlo uscire di scena. Il componente resta all'elenco; qui c'è
 * tutto ciò che tocca il database, perché è tutto ciò che va difeso.
 *
 * ## 🔴 La forma «esterna» è l'unica che funziona, e si scrive `null`
 *
 * Il tecnico creato da qui nasce con ruolo `Tecnico` e **nessun Ente** (🔗
 * ADR-007/030). Il modello dati accetterebbe anche l'altra forma — un tecnico
 * *dentro* l'Ente EasyLab — e le conseguenze sarebbero opposte: `Assegnabili`
 * non lo proporrebbe su nessun cliente (il primo ramo confronta `tenant_id` con
 * quello della macchina, il secondo pretende `tenant_id IS NULL`) e
 * `Intervento::tecnicoLabel()` lo renderebbe «—». Sarebbe una persona che
 * esiste, entra, e non serve a niente. Per questo l'Ente qui non è un parametro:
 * è una costante del gesto.
 *
 * ## 🔴 Il portafoglio è un **conferimento d'accesso**
 *
 * Aggiungere una sede al portafoglio apre a quel tecnico **tutte le macchine di
 * quella sede** (`App\Support\Tenancy\AccessoTecnico`) *e* lo fa comparire nella
 * tendina «Assegnatario» di quel cliente (`App\Support\Utenti\Assegnabili`). La
 * revoca ha effetto **immediato**, perché entrambe le letture sono sottoquery
 * SQL e non liste in cache. Non è una nota organizzativa, ed è per questo che
 * l'avviso sta **dentro la modale**, accanto alle caselle, e non in una guida
 * che nessuno apre mentre conferisce.
 *
 * ## ⛔ `User` non ha global scope: il confine lo scrive questo file
 *
 * 🔗 ADR-018 esenta `users` dal `TenantScope` — è l'identità, non il dominio —
 * quindi **non c'è nessuno scope che rifiuti un id sbagliato**. «Sono i tecnici
 * di piattaforma» è la coppia `tenant_id IS NULL` + ruolo `Tecnico`, scritta in
 * `tecniciDiPiattaforma()` e attraversata da **ogni** azione: l'id dell'Admin di
 * un cliente, o di un tecnico *interno* che un `tenant_id` ce l'ha, non trova
 * riga e diventa un 404 prima di qualunque scrittura.
 *
 * ## ⛔ Le sedi escono da una porta esistente, mai da un bypass
 *
 * `VistaPiattaforma::enti()` (🔗 ADR-018) è l'unica sorgente delle sedi
 * selezionabili: toglie `TenantScope` e `DepartmentScope` **per nome**, tiene il
 * soft delete, e chiede `tenants.view_all` prima di consegnare il builder.
 * `ParcoBypassGuardrailTest` scandisce questa cartella e diventa rosso al primo
 * `withoutGlobalScope` scritto a mano.
 *
 * ⚠️ **Il pivot però si legge col query builder**, non dalla relazione
 * `portafoglioClienti()`: quella è Eloquent su `UnitaOrganizzativa` e ne
 * applicherebbe i global scope, quindi per chi apre la pagina (Superadmin, che
 * un `tenant_id` suo ce l'ha) il portafoglio risulterebbe **vuoto** e salvare lo
 * cancellerebbe. È la stessa ragione, e la stessa forma, di
 * `Assegnabili::perSede()` e `AccessoTecnico::portafoglio()`.
 *
 * ## L'audit si scrive a mano
 *
 * `User` è **esente** dal trait `AuditsDomainWrites` per scelta dichiarata
 * (🔗 ADR-027: la sua vita è raccontata da `AuditLogSubscriber` su un altro
 * canale). Le righe seguono quindi la strada di `User::passaAllEnte()`:
 * `activity()` esplicite, un atto con un nome. ⛔ Mai il trait **e**
 * `activity()` sullo stesso modello — `AuditCoverageGuardrailTest` lo rende
 * rosso.
 *
 * ⚠️ Il trait scrive due property dell'ospite — `search` (per andare a cercare
 * la riga appena creata) e `conCestinati` (per non far sparire il tecnico
 * appena cestinato insieme al bottone che lo ripristina) — e usa `resetPage()`
 * di `WithPagination`. Sono le sole dipendenze verso il componente.
 *
 * @property string $search
 * @property bool $conCestinati
 */
trait GestisceTecnici
{
    // ─── Invito ──────────────────────────────────────────────────────────────

    public bool $invitoAperto = false;

    /** @var array<string,mixed> */
    public array $nuovo = ['nome' => '', 'email' => ''];

    /**
     * 🔴 Il ruolo scelto nella modale: **arriva dal browser**.
     *
     * Dal 6 Ott 2026 (🔗 ADR-046) le figure sono due — Tecnico e Gestore — e la
     * tendina le offre entrambe. Ciò che la tendina non offre resta rifiutato
     * dall'azione che scrive (🔗 ADR-038): «Superadmin non è conferibile» non si
     * prova su una `<select>` che non lo mostra, si prova forzando il valore.
     */
    public string $nuovoRuolo = User::TECNICO_ROLE;

    /**
     * L'id che il rifiuto `UTENTE_CESTINATO` propone di ripristinare.
     *
     * ⚠️ Si valorizza solo se quella persona è davvero un tecnico di
     * piattaforma: un'email cestinata può appartenere all'Admin di un cliente, e
     * offrire «ripristina» lì significherebbe rimettere in servizio qualcuno di
     * un altro perimetro da una pagina che non lo amministra.
     */
    public ?int $ripristinabile = null;

    // ─── Portafoglio ─────────────────────────────────────────────────────────

    /** Il tecnico di cui si sta scegliendo il portafoglio; `null` = modale chiusa. */
    public ?int $portafoglioTecnico = null;

    /** @var array<int,mixed> gli id di sede spuntati — arrivano dal browser */
    public array $portafoglioSedi = [];

    /**
     * Le sedi selezionabili, lette **una volta per richiesta**.
     *
     * Non è una property pubblica: Livewire serializza quelle, e qui ci sarebbe
     * l'intero elenco delle sedi della piattaforma dentro ogni payload.
     */
    private ?Collection $sediCache = null;

    // ─── Invito ──────────────────────────────────────────────────────────────

    public function apriInvito(): void
    {
        Gate::authorize(Tecnici::PERMESSO);

        $this->nuovo = ['nome' => '', 'email' => ''];
        $this->nuovoRuolo = User::TECNICO_ROLE;
        $this->ripristinabile = null;
        $this->resetValidation();

        // Una modale alla volta: il portafoglio aperto sotto l'invito
        // resterebbe montato, e salvarlo dopo l'invito scriverebbe su un tecnico
        // che chi guarda non sta più guardando.
        $this->portafoglioTecnico = null;
        $this->portafoglioSedi = [];

        $this->invitoAperto = true;
    }

    public function chiudiInvito(): void
    {
        $this->invitoAperto = false;
        $this->nuovo = ['nome' => '', 'email' => ''];
        $this->nuovoRuolo = User::TECNICO_ROLE;
        $this->ripristinabile = null;
        $this->resetValidation();
    }

    /**
     * Crea la persona e la invita: ruolo `Tecnico`, **nessun Ente**.
     *
     * ⚠️ L'autorizzazione si rifà qui e non ci si appoggia ad `apriInvito()`:
     * le property sono pubbliche, quindi il form si apparecchia con un `set` e
     * si invia senza passare dall'apertura.
     */
    public function invitaTecnico(): void
    {
        Gate::authorize(Tecnici::PERMESSO);

        // ⚠️ Si normalizza **prima** di validare: un'email incollata con uno
        // spazio in coda verrebbe rifiutata da `email` con un messaggio che non
        // dice cosa c'è di sbagliato. `is_string` e non `?? ''` perché `nuovo` è
        // un array pubblico e un update può consegnare un array annidato —
        // `trim(array)` è un `TypeError`, cioè un 500 al posto di un errore di
        // campo. (Stessa forma già chiusa in `ProvisionaCliente`.)
        $pulito = fn (string $campo) => is_string($this->nuovo[$campo] ?? null) ? trim($this->nuovo[$campo]) : '';

        $this->nuovo['nome'] = $pulito('nome');
        $this->nuovo['email'] = mb_strtolower($pulito('email'));

        $dati = $this->validate([
            'nuovo.nome' => ['required', 'string', 'min:2', 'max:255'],
            'nuovo.email' => ['required', 'email', 'max:255'],
        ], [], [
            'nuovo.nome' => 'nome del tecnico',
            'nuovo.email' => 'email del tecnico',
        ])['nuovo'];

        // 🔴 La guardia sta **nell'azione che scrive**, non dove si disegna il
        // form: `nuovoRuolo` arriva dal browser. Superadmin e Developer portano
        // `tenants.view_all` — e il Developer è la chiave di riserva della
        // piattaforma (🔗 ADR-016) — quindi il rifiuto è del codice, non
        // dell'assenza da una tendina.
        if (! RuoliAssegnabili::ammesso($this->nuovoRuolo, piattaforma: true)) {
            $this->addError('nuovoRuolo', "Da questa pagina si creano solo tecnici e gestori di EasyLab: «{$this->nuovoRuolo}» non è conferibile da nessuna interfaccia.");

            return;
        }

        $this->ripristinabile = null;

        try {
            $esito = (new InvitaUtente(
                nome: $dati['nome'],
                email: $dati['email'],
                ruolo: $this->nuovoRuolo,
                // 🔴 `null`, e non l'Ente di EasyLab: è la forma «esterna» di
                // ADR-030, l'unica che funziona cross-cliente. Vedi il docblock
                // di classe.
                ente: null,
            ))->esegui();
        } catch (InvitoRifiutato $rifiuto) {
            // Il messaggio è già in italiano e già destinato a un umano: si
            // mostra, non si reinterpreta. Si ramifica sul **codice**, che è
            // l'API — la lezione già pagata da chi ramificava con `str_contains`
            // su una frase poi riscritta.
            $this->addError('nuovo.email', $rifiuto->getMessage());

            if ($rifiuto->codice === InvitoRifiutato::UTENTE_CESTINATO) {
                $this->ripristinabile = $this->tecniciDiPiattaforma(conCestinati: true)
                    ->where('email', $dati['email'])
                    ->value('id');
            }

            return;
        }

        // La riga nuova si va a **cercare**, non si spera: con l'ordinamento per
        // nome il tecnico appena creato cade dove capita, e tornare a pagina 1
        // lo rende meno probabile da vedere, non più.
        $this->search = $esito->utente->name;
        $this->resetPage();

        // ⚠️ «In consegna» e non «inviato»: la notifica è accodata, e affermare
        // che è partita sarebbe una bugia che da questa pagina nessuno può più
        // smentire.
        $figura = $this->nuovoRuolo;

        $this->chiudiInvito();

        $this->annuncia(match (true) {
            $esito->invitoFallito !== null => "{$figura} «{$esito->utente->name}» creato, ma l'invito a {$esito->utente->email} NON è partito ({$esito->invitoFallito}): ripetere il gesto per riprovare.",
            default => "{$figura} «{$esito->utente->name}» creato. Invito in consegna a {$esito->utente->email}.",
        }, fallito: $esito->invitoFallito !== null);
    }

    // ─── Portafoglio ─────────────────────────────────────────────────────────

    public function apriPortafoglio(mixed $tecnicoId): void
    {
        $tecnico = $this->risolviTecnico($tecnicoId);

        $this->invitoAperto = false;
        $this->resetValidation();

        $this->portafoglioTecnico = $tecnico->id;
        $this->portafoglioSedi = $this->portafoglioAttuale($tecnico->id);
    }

    public function chiudiPortafoglio(): void
    {
        $this->portafoglioTecnico = null;
        $this->portafoglioSedi = [];
    }

    /**
     * L'altra strada per puntare la modale su un tecnico: la property.
     *
     * Senza questo hook un `$wire.set('portafoglioTecnico', <id dell'Admin di un
     * cliente>)` intitolerebbe la modale su quella persona — e `salvaPortafoglio()`
     * la rifiuterebbe solo al salvataggio, dopo che le caselle sono state
     * spuntate. La modale direbbe una cosa e l'azione ne farebbe un'altra.
     */
    public function updatingPortafoglioTecnico(mixed $valore): void
    {
        if ($valore !== null) {
            $this->risolviTecnico($valore);
        }
    }

    /**
     * Scrive `tecnico_cliente`: chi lavora su quali sedi.
     *
     * ⚠️ **Le sedi scelte si intersecano con quelle selezionabili**, e non ci si
     * fida di ciò che la modale ha offerto: `portafoglioSedi` è un array
     * pubblico, quindi un id inventato — o quello di una sede **cestinata**, che
     * la porta non consegna più — arriverebbe intatto fino a `sync()` e il pivot
     * lo accetterebbe (la FK esiste ancora: il soft delete non cancella la riga).
     * L'intersezione è l'unica barriera, perché il pivot non ne ha una propria.
     */
    public function salvaPortafoglio(): void
    {
        $tecnico = $this->risolviTecnico($this->portafoglioTecnico);

        $ammesse = $this->idSediAmmesse();

        $scelte = collect($this->portafoglioSedi)
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->intersect($ammesse)
            ->unique()
            ->values();

        $prima = collect($this->portafoglioAttuale($tecnico->id));

        // ⚠️ **Ciò che la pagina non può mostrare, non lo può nemmeno revocare.**
        // Una sede cestinata (o quella di EasyLab stessa) resta fuori
        // dall'elenco selezionabile: se fosse già in portafoglio, un `sync()`
        // sulle sole caselle spuntate la staccherebbe **in silenzio**, come
        // effetto collaterale di un salvataggio che riguardava altro. Si
        // conserva, e la revoca resta un gesto che qualcuno ha visto.
        $fuoriPortata = $prima->diff($ammesse);
        $finale = $scelte->merge($fuoriPortata)->unique()->values();

        $tecnico->portafoglioClienti()->sync($finale->all());

        $conferiti = $finale->diff($prima)->values();
        $revocati = $prima->diff($finale)->values();

        // 🔴 Audit **a mano** e in due righe distinte: conferire e revocare sono
        // due atti opposti, e un'unica riga «portafoglio modificato» costringerebbe
        // a leggere le proprietà per sapere cosa è successo. `User` è esente dal
        // trait (🔗 ADR-027), quindi qui non scrive nessun altro.
        if ($conferiti->isNotEmpty()) {
            $this->registra($tecnico, 'Clienti conferiti al tecnico', $conferiti->all());
        }

        if ($revocati->isNotEmpty()) {
            $this->registra($tecnico, 'Clienti revocati al tecnico', $revocati->all());
        }

        $this->chiudiPortafoglio();

        // ⚠️ Il plurale si accorda: «1 sedi aperte» era ciò che leggeva chi
        // spuntava una sola sede, cioè il caso più comune, nel gesto che apre a
        // una persona tutte le macchine di un cliente.
        //
        // I due rami separati non sono ridondanza: un portafoglio si tocca quasi
        // sempre in una direzione sola, e «2 sedi aperte, 0 chiuse» costringe a
        // leggere uno zero per sapere che non è successo niente da quel lato.
        $this->annuncia(match (true) {
            $conferiti->isEmpty() && $revocati->isEmpty() => "Il portafoglio di {$tecnico->name} non è cambiato.",
            $revocati->isEmpty() => "Portafoglio di {$tecnico->name} aggiornato: ".self::sedi($conferiti->count()).' in più. Ha effetto subito.',
            $conferiti->isEmpty() => "Portafoglio di {$tecnico->name} aggiornato: ".self::sedi($revocati->count()).' in meno. Ha effetto subito.',
            default => "Portafoglio di {$tecnico->name} aggiornato: ".self::sedi($conferiti->count()).' in più e '.self::sedi($revocati->count()).' in meno. Ha effetto subito.',
        });
    }

    /** «1 sede» / «3 sedi»: l'accordo del plurale, in un punto solo. */
    private static function sedi(int $quante): string
    {
        return $quante.($quante === 1 ? ' sede' : ' sedi');
    }

    // ─── Cestino ─────────────────────────────────────────────────────────────

    /**
     * Cestina un tecnico: gli nega il login e lo toglie dalle tendine.
     *
     * Non serve un `if` per ciascuna di quelle conseguenze — è il punto di 🔗
     * ADR-038: il `SoftDeletes` del model toglie la riga, e login, tendine,
     * destinatari del digest e candidati all'impersonazione cadono tutti dallo
     * stesso global scope. Lo storico continua a nominarlo, perché le relazioni
     * di attribuzione leggono `withTrashed()`.
     */
    public function cestina(mixed $tecnicoId): void
    {
        $tecnico = $this->risolviTecnico($tecnicoId);

        // ⚠️ Nessuno si cestina da sé. Oggi la figura elencata qui (Tecnico
        // senza Ente) non è quella che apre la pagina (`tenants.view_all`), ma
        // dall'editor dei ruoli quel permesso è ridistribuibile a runtime: il
        // giorno in cui il ruolo `Tecnico` lo ricevesse, questa riga è ciò che
        // impedisce a qualcuno di chiudersi fuori con un click.
        if ($tecnico->id === auth()->id()) {
            $this->annuncia('Non puoi cestinare te stesso.', fallito: true);

            return;
        }

        $tecnico->delete();

        $this->registra($tecnico, 'Persona cestinata');

        // Il cestinato resta a schermo: sparirebbe con la riga, e con lei il
        // bottone che lo rimette in servizio.
        $this->conCestinati = true;

        $this->annuncia("{$tecnico->name} è nel cestino: non entra più e non compare in nessuna tendina. Lo storico continua a portare il suo nome.");
    }

    public function ripristina(mixed $tecnicoId): void
    {
        $tecnico = $this->risolviTecnico($tecnicoId, conCestinati: true);

        if (! $tecnico->trashed()) {
            return;
        }

        $tecnico->restore();

        $this->registra($tecnico, 'Persona ripristinata');

        $this->chiudiInvito();

        $this->annuncia("{$tecnico->name} è di nuovo in servizio, col portafoglio che aveva.");
    }

    // ─── Letture condivise ───────────────────────────────────────────────────

    /**
     * Il confine «personale di EasyLab che lavora per portafoglio», in **un
     * posto solo**: tecnici e, dal 6 Ott 2026, gestori (🔗 ADR-046).
     *
     * ⛔ `users` non ha global scope di tenancy (🔗 ADR-018 la esenta): ciò che
     * questo metodo non esclude, **entra**. Le due condizioni sono la
     * definizione stessa della figura — `tenant_id IS NULL` la rende «esterna»,
     * il ruolo le dà un portafoglio — e sono le stesse che
     * `Assegnabili::perSede()` usa dall'altro lato per proporla.
     *
     * I ruoli vengono da `RuoliAssegnabili::perPiattaforma()`: la pagina
     * amministra esattamente le figure che può creare, e non una di più.
     *
     * @return Builder<User>
     */
    protected function tecniciDiPiattaforma(bool $conCestinati = false): Builder
    {
        return User::query()
            ->when($conCestinati, fn (Builder $q) => $q->withTrashed())
            ->whereNull('tenant_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', RuoliAssegnabili::perPiattaforma()));
    }

    /**
     * Le figure che la modale d'invito offre.
     *
     * @return list<string>
     */
    public function ruoliConferibili(): array
    {
        return RuoliAssegnabili::perPiattaforma();
    }

    /** True se quel ruolo, al primo accesso, deve configurare il secondo fattore. */
    public function imponeSecondoFattore(mixed $ruolo): bool
    {
        return is_string($ruolo) && RuoliAssegnabili::imponeSecondoFattore($ruolo);
    }

    /**
     * L'id che arriva dal browser diventa una riga, o un 404.
     *
     * 🔴 **Riautorizza e rilegge**, entrambe le cose, a ogni azione: il permesso
     * di rotta dice «puoi stare in questa pagina», non «puoi toccare QUESTA
     * persona», e ciò che l'apertura della modale ha impostato non è una prova
     * di niente.
     */
    protected function risolviTecnico(mixed $id, bool $conCestinati = false): User
    {
        Gate::authorize(Tecnici::PERMESSO);

        // `whereKey` con un array diventa un `whereIn`, quindi un payload
        // `[1,2]` pescherebbe la prima riga utile invece di fallire.
        if (! is_numeric($id)) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        return $this->tecniciDiPiattaforma($conCestinati)->whereKey((int) $id)->firstOrFail();
    }

    /**
     * Le sedi su cui un tecnico può essere messo: i **clienti**, sede per sede.
     *
     * ⚠️ Enti e non account: «il tecnico lavora su sedi, non su contratti»
     * (🔗 ADR-030, riletto da ADR-032). Un cliente con tre sedi si sceglie tre
     * volte, ed è corretto — il portafoglio apre le macchine *di quella sede*.
     *
     * ⛔ Da `VistaPiattaforma::enti()`, che è la porta: toglie gli scope per
     * nome, tiene il soft delete (una sede cestinata non è offribile) e chiede
     * `tenants.view_all` prima di consegnare il builder.
     *
     * L'account di piattaforma resta fuori per la stessa ragione per cui
     * `VistaPiattaforma::accounts()` lo esclude: qui si dichiara su quali
     * **clienti** lavora una persona, ed EasyLab non è un proprio cliente.
     *
     * @return Collection<int,UnitaOrganizzativa>
     */
    public function sediSelezionabili(): Collection
    {
        return $this->sediCache ??= VistaPiattaforma::enti()
            ->where('tipo', TipoUnitaOrganizzativa::Ente)
            ->whereHas('account', fn ($q) => $q->where('di_piattaforma', false))
            ->with('account:id,ragione_sociale')
            // Tie-break sull'id: a parità di nome l'ordine fra due righe è una
            // proprietà del motore, e Postgres può riordinare i pari.
            ->orderBy('nome')
            ->orderBy('id')
            ->get();
    }

    /** @return list<int> */
    protected function idSediAmmesse(): array
    {
        return $this->sediSelezionabili()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Il portafoglio di un tecnico, letto **dal pivot** col query builder.
     *
     * ⛔ Non da `portafoglioClienti()`: quella relazione è Eloquent su
     * `UnitaOrganizzativa` e ne applica i global scope, quindi per chi apre
     * questa pagina — che un `tenant_id` proprio ce l'ha — tornerebbe **vuota**,
     * e salvare cancellerebbe tutto senza che nessuno abbia chiesto niente. La
     * scrittura invece passa dalla relazione: `sync()` interroga il pivot
     * direttamente, quindi non ha lo stesso difetto.
     *
     * @return list<int>
     */
    protected function portafoglioAttuale(int $tecnicoId): array
    {
        return DB::table('tecnico_cliente')
            ->where('tecnico_id', $tecnicoId)
            ->pluck('ente_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Una riga nel registro di audit (🔗 ADR-027), con l'atto per nome. */
    private function registra(User $tecnico, string $atto, ?array $enteIds = null): void
    {
        $proprieta = ['tecnico_id' => $tecnico->id, 'email' => $tecnico->email];

        if ($enteIds !== null) {
            $proprieta['ente_ids'] = $enteIds;
        }

        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->performedOn($tecnico)
            ->withProperties($proprieta)
            ->log($atto);
    }

    /** Il messaggio in pagina, con la sua tinta. */
    private function annuncia(string $messaggio, bool $fallito = false): void
    {
        session()->flash('tecnici', $messaggio);
        session()->flash('tecniciFallito', $fallito);
    }
}
