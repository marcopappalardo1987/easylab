<?php

namespace App\Support\Piattaforma;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Database\Eloquent\Builder;

/**
 * La definizione di «cliente» della piattaforma, scritta **una volta sola**
 * (S6 — Wireframe §4; 🔗 ERD §4.1, ADR-018/032).
 *
 * Esiste perché due passate sullo stesso perimetro non possono avere due
 * definizioni. `MetrichePiattaforma` conta i quattro KPI di oggi e
 * `AndamentiPiattaforma` ricostruisce le stesse tre grandezze mese per mese: se
 * ciascuna si riscrivesse i propri `where`, il grafico e la tile che gli sta a
 * due centimetri potrebbero chiudere su due numeri diversi — e il guasto non
 * sarebbe un 500, sarebbe una cifra **plausibile e sbagliata**, che è ciò che il
 * docblock di `KpiPiattaformaTest` dice di temere.
 *
 * ⚠️ **Sedi e strumenti si legano alla nozione di «cliente» dei due numeri
 * sopra**, con una sottoquery sugli id degli account. Senza, contavano anche
 * l'Ente di EasyLab e le sue macchine: sul database di sviluppo erano una sede
 * di nessun cliente e **1.217 strumenti su 5.105**, cioè il 24% di un numero
 * etichettato «macchine di tutti i clienti». La migration che ha creato
 * `di_piattaforma` lo aveva scritto per esteso — «falserebbe tutti e quattro i
 * KPI» — e la prima stesura di quel blocco ne aveva coperti due.
 *
 * Chiude gratis anche il caso opposto: un account **cestinato** lasciava le
 * proprie sedi e macchine nei totali per sempre, perché `Account` non propaga il
 * soft delete ai figli. La sottoquery passa da `VistaPiattaforma::accounts()`,
 * che il soft delete ce l'ha.
 *
 * ⚠️ **Le sottoquery sono ANNIDATE, non query in più.** `whereIn` con un builder
 * finisce dentro lo stesso SQL: `sedi()` e `strumenti()` restano una query per
 * tabella, ed è la ragione per cui `KpiPiattaformaTest` può pretendere tre
 * statement costanti su dodici clienti come su uno.
 *
 * ⚠️ **Ogni metodo passa da `VistaPiattaforma`**, quindi il permesso
 * `tenants.view_all` è chiesto **per costruzione**: non c'è modo di ottenere uno
 * di questi builder senza attraversare `porta()`. Come lei, non è utilizzabile
 * fuori da un contesto HTTP autenticato — in console `Gate::authorize()` nega
 * sempre.
 */
final class PerimetroClienti
{
    /**
     * Gli account che sono **clienti**: non cestinati, EasyLab esclusa.
     *
     * ⚠️ Senza `select`, e non è una svista: chi deve aggregare
     * (`selectRaw('sum(case when …)')`) non può ricevere un builder che ha già
     * una colonna in proiezione — su Postgres `select accounts.id, sum(…)` senza
     * `GROUP BY` è un errore, e su SQLite passerebbe, cioè verde in locale e
     * rosso in CI. Chi ha bisogno della sottoquery usa `idClienti()`.
     *
     * @return Builder<Account>
     */
    public static function clienti(): Builder
    {
        return VistaPiattaforma::accounts();
    }

    /**
     * Gli **id** degli account clienti, pronti per un `whereIn`.
     *
     * Il `select` esplicito serve a poterla usare come sottoquery: senza,
     * `whereIn` riceverebbe `select *` e Postgres rifiuterebbe il confronto.
     *
     * @return Builder<Account>
     */
    public static function idClienti(): Builder
    {
        return self::clienti()->select('accounts.id');
    }

    /**
     * Le **sedi** dei clienti: i soli nodi di tipo Ente, e solo dei clienti.
     *
     * Un dipartimento non è una sede — contarlo gonfierebbe il numero con la
     * profondità dell'alberatura di ciascun cliente, cioè con un dato che non
     * riguarda la piattaforma.
     *
     * @return Builder<UnitaOrganizzativa>
     */
    public static function sedi(): Builder
    {
        return VistaPiattaforma::enti()
            ->where('tipo', TipoUnitaOrganizzativa::Ente)
            ->whereIn('account_id', self::idClienti());
    }

    /**
     * Le **macchine** dei clienti.
     *
     * `strumenti.tenant_id` punta al nodo radice, che è quello che porta
     * `account_id`: la stessa definizione di cliente, un salto più in là.
     *
     * @return Builder<Strumento>
     */
    public static function strumenti(): Builder
    {
        return VistaPiattaforma::strumenti()
            ->whereIn('tenant_id', self::sedi()->select('unita_organizzativa.id'));
    }
}
