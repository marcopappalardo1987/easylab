<?php

namespace App\Support\Piattaforma;

use App\Models\Account;
use App\Models\User;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * I **clienti preferiti** di chi sta guardando (🔗 ADR-037): la porta unica.
 *
 * Nasce da una richiesta precisa: il perimetro «quelli che scelgo» del Parco
 * clienti si ricomponeva a mano a ogni visita, dentro una `<select multiple>`
 * che non ricordava nulla fra una pagina e l'altra. I clienti che si guardano
 * spesso sono **pochi e sempre gli stessi**, quindi la scelta diventa una
 * preferenza durevole della persona — si segna una volta dall'elenco Clienti e
 * si riusa dalle tre schede del Parco.
 *
 * ## Perché una porta e non `auth()->user()->clientiPreferiti()` sparso
 *
 * La stessa ragione di `ParcoClienti`, un piano più in basso: qui si **scrive**
 * a partire da un id che arriva dal browser. Se il controllo vivesse nei
 * chiamanti sarebbe N volte da ricordare — e la scrittura non è innocua, perché
 * l'id di un account **esiste** anche quando quell'account non è un cliente
 * (EasyLab, `di_piattaforma`) o è cestinato. Segnarlo non trapelerebbe righe —
 * l'intersezione di `ParcoClienti::clienti()` lo scarterebbe comunque — ma
 * lascerebbe in tabella una preferenza verso un soggetto che nessuna schermata
 * sa disegnare: un preferito invisibile che non si può togliere.
 *
 * ⛔ Quindi `alterna()` **rilegge l'account da `ParcoClienti::selezionabili()`**,
 * che è lo stesso insieme legittimo con cui `ParcoClienti::clienti()` interseca,
 * e non da `Account::query()`. Fail-closed: un id che non è di un cliente non
 * diventa un preferito, e non solleva — non c'è niente da dire a chi non stava
 * usando la pagina.
 *
 * ⚠️ **Dalla porta del Parco e non da quella della cabina**, benché le due
 * consegnino oggi lo stesso insieme. `ParcoBypassGuardrailTest` tiene
 * l'invariante «il parco non legge dalla porta della cabina» riconoscendo i file
 * **dal nome**, e questo file non si chiama `Parco*`: chiamare
 * `VistaPiattaforma::accounts()` di qui sarebbe passato sotto quella rete senza
 * che nulla lo dicesse. Passando dalla porta giusta la dipendenza è quella che
 * ADR-037 descrive, e il giorno in cui le due definizioni di «cliente»
 * divergessero questo file seguirebbe quella del Parco — che è l'insieme su cui
 * la tabella è costruita.
 *
 * ## Il permesso
 *
 * `tenants.view_all`, lo stesso del Parco e della cabina: mettere da parte un
 * cliente è un gesto **dentro** la vista cross-cliente, non un potere in più.
 * Nessun permesso nuovo, quindi nessun riseeding di `config/rbac.php` — che da
 * S6 è un'arma carica (vedi CLAUDE.md).
 *
 * ⚠️ **Solo contesti HTTP autenticati**, come le due porte sorelle:
 * `Gate::authorize()` nega sempre senza utente. In console e nei job questa
 * classe lancia, ed è la simmetria giusta — un digest notturno non ha preferiti.
 */
final class Preferiti
{
    /** Lo stesso permesso del Parco e della cabina. */
    public const PERMESSO = VistaPiattaforma::PERMESSO;

    /**
     * Gli id dei clienti preferiti, in ordine di ragione sociale.
     *
     * ⚠️ **Qui non si filtra**, e il perché è la stessa scelta di `Perimetro`:
     * l'insieme legittimo lo impone `ParcoClienti::clienti()` intersecando, e
     * una seconda copia di quel filtro in questo punto sarebbe una seconda
     * definizione di «cliente», libera di divergere. Ciò che esce di qui è
     * «quali id ha segnato la persona», non «quali ha titolo a vedere».
     *
     * ⚠️ Con **un'eccezione che arriva da sé**, e va detta perché non si vede:
     * la relazione porta il `SoftDeletingScope` di `Account`, quindi il
     * preferito verso un cliente **cestinato** non esce già da qui. È la
     * direzione giusta — restringe — ma non è la difesa: quella resta
     * l'intersezione, che è anche l'unica a togliere l'account di piattaforma.
     *
     * @return list<int>
     */
    public static function id(): array
    {
        self::porta();

        return self::query()
            ->orderBy('accounts.ragione_sociale')
            ->orderBy('accounts.id')
            ->pluck('accounts.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * I preferiti **veri**: quelli che sono ancora clienti visibili.
     *
     * È l'elenco che si mostra a schermo, quindi passa dall'intersezione — dire
     * «3 preferiti» sopra una tabella costruita su 2 sarebbe la stessa forma di
     * bugia del titolo statico sopra una tabella filtrata.
     *
     * @return Collection<int, Account>
     */
    public static function clienti(): Collection
    {
        self::porta();

        return ParcoClienti::clienti(self::perimetro())
            ->orderBy('accounts.ragione_sociale')
            ->orderBy('accounts.id')
            ->get(['accounts.id', 'accounts.ragione_sociale', 'accounts.piano']);
    }

    /**
     * Il perimetro «i miei preferiti», pronto per `ParcoClienti`.
     *
     * 🔴 È **l'unico** costruttore di quel perimetro, ed è la ragione per cui
     * `Perimetro::daRichiesta()` non sa produrlo: gli id dei preferiti non
     * arrivano dal browser, arrivano dal database. Un componente che se lo
     * dimenticasse otterrebbe `nessuno()`, cioè zero righe — l'errore cade dalla
     * parte giusta.
     */
    public static function perimetro(): Perimetro
    {
        self::porta();

        return Perimetro::preferiti(self::id());
    }

    /** Vero se quel cliente è fra i preferiti di chi sta guardando. */
    public static function contiene(int $accountId): bool
    {
        self::porta();

        return self::query()->whereKey($accountId)->exists();
    }

    /**
     * Segna o toglie un cliente dai preferiti, e dice com'è finita.
     *
     * `toggle()` di `belongsToMany` fa il giro in una chiamata e resta corretto
     * anche sul doppio clic: l'unique del pivot impedirebbe comunque il
     * doppione, ma qui non si arriva nemmeno a chiederlo al database.
     *
     * ⚠️ Un id che non è di un cliente visibile torna `false` **senza scrivere**
     * e senza sollevare: vedi il docblock di classe.
     */
    public static function alterna(int $accountId): bool
    {
        self::porta();

        $account = ParcoClienti::selezionabili()->whereKey($accountId)->first();

        if ($account === null) {
            return false;
        }

        $utente = Auth::user();

        $esito = $utente->clientiPreferiti()->toggle([$account->id]);

        return $esito['attached'] !== [];
    }

    /**
     * La relazione dell'utente corrente, senza scomodare `Auth::user()` ovunque.
     *
     * @return BelongsToMany<Account, User>
     */
    private static function query(): BelongsToMany
    {
        return Auth::user()->clientiPreferiti();
    }

    /**
     * Il permesso, chiesto **prima** di leggere o scrivere.
     *
     * `Gate::authorize()` e non `Gate::allows()`, per la ragione delle due porte
     * sorelle: chi non ha il permesso deve ricevere un 403 e non un elenco
     * vuoto, che si leggerebbe come «non hai preferiti».
     */
    private static function porta(): void
    {
        Gate::authorize(self::PERMESSO);
    }
}
