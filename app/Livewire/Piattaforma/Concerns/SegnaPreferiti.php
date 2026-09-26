<?php

namespace App\Livewire\Piattaforma\Concerns;

use App\Support\Piattaforma\Preferiti;

/**
 * La ★ dei **clienti preferiti**, nell'elenco Clienti della cabina (🔗 ADR-037).
 *
 * Il filtro «I miei preferiti» del Parco clienti si **legge** da tre schede ma
 * si **scrive** da una sola vista, ed è questa: l'elenco Clienti è già l'elenco
 * canonico degli Account — con ricerca, filtri e paginazione — quindi una
 * seconda schermata per scegliere i preferiti sarebbe una seconda copia dello
 * stesso elenco, libera di mostrare un insieme diverso. La sostituita era una
 * `<select multiple>` che non ricordava nulla fra una visita e l'altra.
 *
 * 🔴 **La stella è di chi guarda, non del cliente** (🔗 ADR-018 per il resto del
 * ragionamento sui confini): due Superadmin sulla stessa pagina vedono stelle
 * diverse, perché la riga sta su `clienti_preferiti` (utente + account) e non su
 * `accounts`. Per questo la vista non deve mai stampare *quanti* l'hanno
 * segnato né *chi*: sarebbe l'attributo di cliente che il pivot esiste per non
 * essere.
 *
 * ## L'autorizzazione, e perché **non** si ripete qui
 *
 * `alternaPreferito()` riceve un id **dal browser**, quindi il gate di rotta
 * (`can:tenants.view_all`) non basta: dice «puoi stare in questa pagina», non
 * «puoi toccare QUESTO account» — e `Account` non ha global scope, quindi un id
 * forgiato raggiungerebbe qualunque cliente, cestinato o di piattaforma.
 *
 * ⚠️ Il controllo c'è, ma **una volta sola e dentro `Preferiti`**: quella porta
 * chiede `Gate::authorize('tenants.view_all')` in ogni suo metodo e **rilegge
 * l'account da `ParcoClienti::selezionabili()`** — l'insieme legittimo del
 * Parco — prima di scrivere. Rifarlo qui
 * darebbe due definizioni dello stesso confine, libere di divergere — che è
 * esattamente il difetto che la porta è nata per chiudere. La disciplina del
 * progetto («ogni azione che accetta un id chiede il permesso per conto
 * proprio») è rispettata: l'azione **non** eredita dalla rotta, delega a una
 * classe che gata. La prova di mutazione è la stessa in entrambe le letture —
 * si scavalca la porta scrivendo diretto sulla relazione e i test negativi
 * diventano rossi.
 *
 * ⚠️ Nessuna modale, quindi **nessun `chiudiOgniModale()`**: la stella non è una
 * quinta leva, e chiamarlo qui chiuderebbe un pannello aperto per un gesto che
 * con quel pannello non c'entra. L'invariante «una modale alla volta» resta di
 * `Cabina`, che è il solo posto che le conosce tutte.
 */
trait SegnaPreferiti
{
    /**
     * Gli id preferiti della persona, **una volta per richiesta**.
     *
     * ⚠️ `private` e non `public`: Livewire serializza le sole property
     * pubbliche, quindi questa nasce e muore dentro il singolo render — che è
     * ciò che serve. Pubblica, viaggerebbe nel payload come stato del client, e
     * un insieme di preferiti *rimandato dal browser* sarebbe di nuovo un id
     * non fidato, per giunta usato in lettura senza passare dalla porta.
     *
     * @var array<int,true>|null
     */
    private ?array $memoPreferiti = null;

    /**
     * Segna o toglie un cliente dai preferiti.
     *
     * Non risponde nulla e non lascia messaggi: l'esito si vede nella stella,
     * che è a due centimetri dal dito. Un flash sopra la tabella per un gesto
     * ripetibile e reversibile sarebbe rumore.
     */
    public function alternaPreferito(int $accountId): void
    {
        Preferiti::alterna($accountId);

        // Il render di questa stessa risposta rilegge: senza, la pagina
        // tornerebbe indietro con la stella di **prima** del clic.
        $this->memoPreferiti = null;
    }

    /**
     * Vero se quel cliente è fra i preferiti di chi sta guardando.
     *
     * 🔴 **Una query per pagina, non una per riga.** `Preferiti::contiene()`
     * risponde alla stessa domanda ma con un `exists()` a testa: chiamato dal
     * `@forelse` della tabella diventerebbe un round-trip per cliente, cioè
     * cento query a `perPage = 100` — il difetto N+1 che `dettagliDellaPagina()`
     * evita in questa stessa vista, rifatto una colonna più in là. Qui l'insieme
     * si carica intero (è piccolo per costruzione: sono i clienti che una
     * persona segue) e la riga lo interroga in memoria.
     */
    public function ePreferito(int $accountId): bool
    {
        return isset($this->preferiti()[$accountId]);
    }

    /**
     * L'insieme dei preferiti come mappa `id => true`, per il test `isset()`.
     *
     * ⚠️ Non filtra sui clienti in pagina, ed è voluto: sono gli id **segnati**,
     * non quelli visibili. Chi mostra un elenco di preferiti passa da
     * `Preferiti::clienti()`, che interseca con l'insieme legittimo — vedi il
     * docblock di quella porta.
     *
     * @return array<int,true>
     */
    private function preferiti(): array
    {
        return $this->memoPreferiti ??= array_fill_keys(Preferiti::id(), true);
    }
}
