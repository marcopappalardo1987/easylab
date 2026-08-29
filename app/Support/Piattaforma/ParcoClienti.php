<?php

namespace App\Support\Piattaforma;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\Scopes\DepartmentThroughStrumentoScope;
use App\Models\Scopes\GaranziaDepartmentScope;
use App\Models\Scopes\TenantScope;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * 🔴 La porta unica del **Parco clienti** (🔗 ADR-037), sorella di
 * `App\Support\Tenancy\VistaPiattaforma` (🔗 ADR-018).
 *
 * Da qui il Superadmin **guarda** la strumentazione di tutti gli Enti della
 * piattaforma: sedi, macchine, scadenzario, garanzie e catalogo ricambi, in un
 * elenco solo e filtrabile per cliente. Non è una vista aggregata come la cabina
 * di regia — lì si contano i clienti, qui si leggono le **loro righe**.
 *
 * ## ⛔ SOLA LETTURA, ed è la ragione per cui la cosa è accettabile
 *
 * Questa classe restituisce **query** e non scrive mai. Ogni modifica continua a
 * passare dall'impersonazione, che è per cliente e lascia nel registro di audit
 * chi stava agendo e per conto di chi (🔗 ADR-018, ADR-027). Una scrittura
 * cross-cliente perderebbe quel contesto **proprio dove serve di più**: la riga
 * direbbe «qualcuno ha cambiato una scadenza», non «EasyLab l'ha cambiata sul
 * cliente X mentre era EasyLab».
 *
 * ⚠️ E il divieto non si difende da sé: i builder che consegna **sono
 * scrivibili**. `Builder::update()` e `->delete()` passano dal query builder e
 * non emettono eventi di modello, quindi gli hook di `BelongsToTenant` che
 * riforzano `tenant_id` non girano: un `ricambi(...)->update([...])` riscriverebbe
 * righe di **ogni** cliente dietro un permesso che si chiama `.view_all`. È la
 * stessa trappola già scritta per `VistaPiattaforma`, e vale identica qui.
 *
 * ## Perché una porta nuova invece di allargare quella esistente
 *
 * `VistaPiattaforma` risponde alla domanda «quanti clienti, quante sedi, quante
 * macchine»: i suoi builder sono **non filtrati per cliente** di proposito,
 * perché un KPI si conta su tutti. Il parco fa la domanda opposta — «le righe di
 * *questi* clienti» — e ha quindi una cosa in più da difendere: il **perimetro**,
 * che arriva dal browser. Concatenare il filtro fuori dalla porta lo renderebbe
 * N volte da ricordare, ed è esattamente la forma di difetto che questo progetto
 * ha già pagato con l'export che ignorava i filtri e portava via tutto l'elenco.
 *
 * Qui **non c'è modo di ottenere una riga senza un `Perimetro`**: è un argomento
 * obbligatorio di ogni lettore, e i lettori derivano tutti da `clienti()`, dove
 * il perimetro si **interseca** con `VistaPiattaforma::accounts()`. Un id forgiato
 * nel browser non trova corrispondenza e sparisce; non allarga, perché un
 * `whereIn` applicato sopra la porta non è un'unione.
 *
 * ## Gli scope si tolgono per NOME
 *
 * ⛔ Mai `withoutGlobalScopes()` nudo: porterebbe via anche `SoftDeletingScope`,
 * cioè farebbe comparire nel parco di un cliente righe che quel cliente ha
 * cestinato. `BypassNudiGuardrailTest` lo rende rosso, ed è il difetto già pagato
 * in `Account::enti()`. Modello per modello, ecco cosa si toglie e cosa **resta**:
 *
 *   - `UnitaOrganizzativa`, `Strumento` → passano da `VistaPiattaforma`, che
 *     toglie `TenantScope` e `DepartmentScope` per nome. Nessun bypass nuovo.
 *   - `Intervento` → `TenantScope` + `DepartmentThroughStrumentoScope`.
 *   - `Garanzia` → `TenantScope` + `GaranziaDepartmentScope`.
 *   - `Ricambio` → `TenantScope`, che è il solo che registra.
 *
 * ⚠️ **`GaranziaRicambioPrivacyScope` resta applicato, e non è una svista.** Non
 * è uno scope di tenancy: risponde alla domanda «questo utente ha titolo a
 * vedere le garanzie dei pezzi montati?» (🔗 ADR-029), che resta sensata anche
 * cross-cliente. Per il Superadmin è un no-op — la Policy vincola il solo ruolo
 * `Tenant`, e il permesso ce l'ha — mentre per un ruolo futuro che avesse
 * `tenants.view_all` senza `garanzie.ricambio.view` resta fail-closed. Toglierlo
 * «per uniformità» significherebbe concedere in silenzio una categoria di righe a
 * chi non l'ha mai avuta.
 *
 * ## Il permesso
 *
 * `tenants.view_all` — lo stesso della cabina, ed è letteralmente «vedi oltre il
 * proprio Ente». Nessun permesso nuovo, quindi nessun riseeding di
 * `config/rbac.php` (che da S6 è un'arma carica, vedi CLAUDE.md).
 *
 * ⚠️ **Il `porta()` di questa classe è difesa in profondità, e va detto perché
 * la sua prova non è quella che sembra.** Ogni lettore attraversa comunque
 * `VistaPiattaforma`, che il gate ce l'ha: togliendo il `self::porta()` da un
 * metodo, un test comportamentale «l'Admin non ottiene righe» resterebbe
 * **verde**. Il gate qui è quindi falsificabile solo per via **strutturale**, e
 * `ParcoClientiTest` lo prova così — leggendo il sorgente e pretendendo che ogni
 * metodo pubblico apra con la porta. Serve, perché il giorno in cui un lettore
 * non passasse più da `VistaPiattaforma` (un modello nuovo, una lettura diretta)
 * questa classe sarebbe già gatata invece di doverlo diventare.
 *
 * ⚠️ **Solo contesti HTTP autenticati**, come la sorella: `Gate::authorize()`
 * nega sempre senza utente, quindi in console, nei job e nello scheduler questa
 * porta **lancia**. Là il confine non c'è già — `TenantScope` non si applica
 * senza utente — e la query nuda è la forma corretta.
 */
final class ParcoClienti
{
    /**
     * Lo stesso permesso della cabina, e lo stesso di `ParcoGlobale::PERMESSO`.
     *
     * Si legge da `VistaPiattaforma` e non dal componente Livewire per non far
     * dipendere `app/Support` da `app/Livewire`: il valore è identico e un test
     * congela la catena, così la costante non può divergere in silenzio.
     */
    public const PERMESSO = VistaPiattaforma::PERMESSO;

    /**
     * Gli account che si possono **scegliere**: l'insieme legittimo.
     *
     * È la sorgente di verità contro cui `clienti()` interseca il perimetro, ed
     * è la stessa `VistaPiattaforma::accounts()` — quindi senza EasyLab
     * (`di_piattaforma`) e senza i cestinati, per costruzione e non per un
     * `where` da ricordare qui.
     *
     * ⚠️ Dal 29 Ago 2026 «scegliere» non vuol più dire una `<select multiple>`
     * in cima al Parco — quel controllo è diventato la ★ dei preferiti (🔗
     * ADR-037) — ma **segnare un cliente**: è `Preferiti::alterna()` a
     * chiamarla, prima di scrivere una riga di pivot a partire da un id che
     * arriva dal browser. L'insieme è lo stesso di prima, e deve restarlo: un
     * preferito che questa lista non contiene sarebbe una preferenza verso un
     * soggetto che nessuna schermata sa disegnare.
     *
     * @return Builder<Account>
     */
    public static function selezionabili(): Builder
    {
        self::porta();

        return VistaPiattaforma::accounts();
    }

    /**
     * I clienti **dentro il perimetro**: qui avviene la rivalidazione.
     *
     * 🔴 Il filtro si applica **sopra** `VistaPiattaforma::accounts()`, cioè
     * come intersezione. Ne discendono tre proprietà che nessun chiamante può
     * perdere: un id forgiato non allarga l'insieme; l'account di piattaforma
     * resta fuori anche se qualcuno ne scegliesse l'id; un account cestinato
     * resta fuori anche se il suo id fosse ancora in una querystring salvata.
     *
     * ⚠️ **Il `match` non ha `default`.** Un modo sconosciuto non può arrivare
     * qui — `Perimetro` ha il costruttore privato e normalizza tutto ciò che
     * viene dal browser — ma se un quarto modo nascesse domani, `UnhandledMatchError`
     * è la risposta giusta: rumorosa. Un `default` sarebbe il posto in cui, fra
     * sei mesi, qualcuno scriverebbe «e allora mostriamo tutto».
     *
     * @return Builder<Account>
     */
    public static function clienti(Perimetro $perimetro): Builder
    {
        self::porta();

        $clienti = VistaPiattaforma::accounts();

        return match ($perimetro->modo) {
            Perimetro::TUTTI => $clienti,
            Perimetro::PER_PIANO => $clienti->where('accounts.piano', $perimetro->piano),
            Perimetro::SCELTI => $clienti->whereIn('accounts.id', $perimetro->accountIds),
            // Stessa clausola di `SCELTI`, e resta un ramo a parte: i due modi
            // differiscono per **provenienza** degli id (browser contro
            // database), che è ciò che decide i controlli e i messaggi della
            // pagina, non la query. Fonderli qui costringerebbe a distinguerli
            // di nuovo, più in là, in un posto che non è una porta.
            Perimetro::PREFERITI => $clienti->whereIn('accounts.id', $perimetro->accountIds),
        };
    }

    /**
     * Gli **id** dei clienti nel perimetro, pronti per un `whereIn`.
     *
     * Il `select` esplicito serve a poterla annidare: senza, `whereIn`
     * riceverebbe `select *` e Postgres rifiuterebbe il confronto (stessa
     * ragione di `PerimetroClienti::idClienti()`).
     *
     * @return Builder<Account>
     */
    public static function idClienti(Perimetro $perimetro): Builder
    {
        self::porta();

        return self::clienti($perimetro)->select('accounts.id');
    }

    /**
     * Le **sedi** dei clienti nel perimetro: i soli nodi di tipo Ente.
     *
     * Un dipartimento non è una sede, e contarlo o elencarlo mescolerebbe
     * l'alberatura interna di un cliente con l'elenco delle sue sedi.
     *
     * @return Builder<UnitaOrganizzativa>
     */
    public static function sedi(Perimetro $perimetro): Builder
    {
        self::porta();

        return VistaPiattaforma::enti()
            ->where('unita_organizzativa.tipo', TipoUnitaOrganizzativa::Ente)
            ->whereIn('unita_organizzativa.account_id', self::idClienti($perimetro));
    }

    /**
     * Gli **id delle sedi** nel perimetro: il confine di ogni riga di dominio.
     *
     * ⚠️ È il punto in cui il perimetro diventa un confine per le tabelle di
     * business: `strumenti`, `interventi`, `garanzie` e `ricambi` portano tutte
     * `tenant_id`, e quel `tenant_id` è **il nodo radice**, cioè la sede. Passare
     * di qui è ciò che lega ogni lettore alla stessa definizione di «cliente».
     *
     * @return Builder<UnitaOrganizzativa>
     */
    public static function idSedi(Perimetro $perimetro): Builder
    {
        self::porta();

        return self::sedi($perimetro)->select('unita_organizzativa.id');
    }

    /**
     * Le **macchine** dei clienti nel perimetro.
     *
     * ⛔ Non concatenarci `conStato()`, `obsoleti()` né `ordinaPerStato()`: quegli
     * scope compongono sottoquery che partono da `Intervento::query()` e
     * `Garanzia::query()`, cioè **scopate**, e su un builder cross-tenant
     * classificherebbero come verdi le macchine degli altri Enti — con la somma
     * che torna lo stesso. `VistaPiattaformaTest` lo vieta per nome.
     *
     * @return Builder<Strumento>
     */
    public static function strumenti(Perimetro $perimetro): Builder
    {
        self::porta();

        return VistaPiattaforma::strumenti()
            ->whereIn('strumenti.tenant_id', self::idSedi($perimetro));
    }

    /**
     * Lo **scadenzario** dei clienti nel perimetro: gli interventi, tutti gli stati.
     *
     * Il filtro su `stato` non sta qui ma nel chiamante, come per il scadenzario
     * del Tenant: la porta consegna il confine, non la domanda.
     *
     * @return Builder<Intervento>
     */
    public static function interventi(Perimetro $perimetro): Builder
    {
        self::porta();

        return Intervento::query()
            ->withoutGlobalScopes([TenantScope::class, DepartmentThroughStrumentoScope::class])
            ->whereIn('interventi.tenant_id', self::idSedi($perimetro));
    }

    /**
     * Le **garanzie** dei clienti nel perimetro, macchina e ricambio.
     *
     * ⚠️ `GaranziaRicambioPrivacyScope` **resta applicato**: vedi il docblock di
     * classe. Non è uno scope di tenancy e la sua domanda vale anche qui.
     *
     * @return Builder<Garanzia>
     */
    public static function garanzie(Perimetro $perimetro): Builder
    {
        self::porta();

        return Garanzia::query()
            ->withoutGlobalScopes([TenantScope::class, GaranziaDepartmentScope::class])
            ->whereIn('garanzie.tenant_id', self::idSedi($perimetro));
    }

    /**
     * Il **catalogo ricambi** dei clienti nel perimetro.
     *
     * ⚠️ Il catalogo, non gli **utilizzi**. `RicambioUtilizzo` porta con sé
     * `DepartmentThroughStrumentoScope` e `GaranziaRicambioPrivacyScope`, cioè
     * due domande in più a cui nessuna schermata di oggi ha bisogno di
     * rispondere: il giorno in cui servisse, si aggiunge un metodo qui e si
     * risponde allora, invece di consegnarlo adesso «per simmetria».
     *
     * @return Builder<Ricambio>
     */
    public static function ricambi(Perimetro $perimetro): Builder
    {
        self::porta();

        return Ricambio::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->whereIn('ricambi.tenant_id', self::idSedi($perimetro));
    }

    /**
     * Il permesso di piattaforma, chiesto **prima** di costruire il builder.
     *
     * `Gate::authorize()` e non `Gate::allows()`: chi non ha il permesso deve
     * ricevere un 403, non un builder vuoto — che si leggerebbe come «i clienti
     * non hanno macchine», cioè la forma peggiore di negare: silenziosa e
     * plausibile. È la stessa scelta, e la stessa motivazione, di
     * `VistaPiattaforma::porta()`.
     *
     * Un permesso nudo è legittimo: non esiste una Policy su `Strumento`,
     * `Intervento` né `Ricambio`, quindi la trappola del `Gate::before` di
     * spatie non si applica. Su `Garanzia` la Policy esiste, e infatti il suo
     * scope di privacy **resta**: la domanda per-riga continua a passare di lì.
     */
    private static function porta(): void
    {
        Gate::authorize(self::PERMESSO);
    }
}
