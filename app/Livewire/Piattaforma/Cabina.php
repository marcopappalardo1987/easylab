<?php

namespace App\Livewire\Piattaforma;

use App\Livewire\Piattaforma\Concerns\AmministraAccount;
use App\Livewire\Piattaforma\Concerns\ElencaClienti;
use App\Livewire\Piattaforma\Concerns\EsportaClienti;
use App\Livewire\Piattaforma\Concerns\FissaVisibilitaSede;
use App\Livewire\Piattaforma\Concerns\OffreImpersonazione;
use App\Livewire\Piattaforma\Concerns\ProvisionaCliente;
use App\Livewire\Piattaforma\Concerns\SegnaPreferiti;
use App\Support\Piattaforma\AndamentiPiattaforma;
use App\Support\Piattaforma\MetrichePiattaforma;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 🔴 La cabina di regia della piattaforma (S6 — Wireframe §4).
 *
 * L'unica schermata del progetto che guarda **oltre il proprio Ente**. Tutto il
 * resto dell'app è tenant-bound per ADR-018, Superadmin compreso: qui il
 * confine si attraversa di proposito, una volta sola, dietro
 * `tenants.view_all` e passando da `App\Support\Tenancy\VistaPiattaforma`.
 *
 * **Autorizzazione a tre livelli**, e ciascuno copre qualcosa che gli altri non
 * coprono — ma non nel modo che verrebbe da scrivere:
 *
 * 1. `can:tenants.view_all` sulla **rotta**. ⚠️ Non ferma solo chi digita
 *    l'URL: `Illuminate\Auth\Middleware\Authorize` è **fra i middleware
 *    persistenti di Livewire** (elenco di default del pacchetto), quindi
 *    viene riapplicato anche sugli update — Livewire rilegge `memo.path`,
 *    rimatcha la rotta e rigira `auth`, `account.lockout` e questo `can:`.
 *    È la guardia più larga delle tre, non la più stretta.
 * 2. `porta()` dentro `VistaPiattaforma`, che gata **ogni lettura**. Vive però
 *    dentro `render()`, che gira **dopo** l'azione: non protegge un'azione che
 *    scrivesse prima di renderizzare, e con `skipRender()` non gira affatto.
 * 3. `authorize()` in **ogni azione** che accetta un id dal browser. È qui che
 *    sta il lavoro vero dei blocchi successivi, e per una ragione che il
 *    livello 1 non copre: il permesso di rotta dice «puoi stare in questa
 *    pagina», **non** «puoi toccare QUESTO account». `Account` non ha global
 *    scope e `membri()` è un `belongsToMany` non scopato, quindi un id che
 *    arriva dal browser raggiunge qualunque cliente.
 *
 * ⚠️ **La prima stesura di questo docblock diceva il contrario** — che il
 * `can:` non arrivasse sugli update e che `porta()` fosse ciò che chiudeva quel
 * buco. Erano due affermazioni e sbagliavano entrambe, in direzioni opposte:
 * il risultato era archiviare come «ridondante» la sola guardia che copre
 * un'azione che non renderizza. Trovato dal confronto sul blocco C.
 *
 * 🔴 **Le esportazioni (`EsportaClienti`) sono l'unica superficie di questa
 * pagina che porta i dati dei clienti FUORI dall'applicazione**, e per questo
 * non hanno una definizione propria del perimetro: riusano `queryClienti()` di
 * `ElencaClienti`, cioè la stessa query che riempie la tabella. Il file contiene
 * quindi esattamente le righe che si vedrebbero sfogliando fino all'ultima
 * pagina — non di più (i filtri valgono) e non di meno (la paginazione non è un
 * filtro di privacy). Una rotta dedicata avrebbe richiesto una seconda lettura
 * dei filtri dalla query string, ed è là che nasce il file che esporta tutto
 * mentre lo schermo mostra dodici righe.
 *
 * **Guscio vuoto di proposito.** KPI (blocco D), tabella clienti con espansione
 * nelle sedi (E), impersonazione (F) e le quattro leve (G) arrivano dopo. Una
 * pagina che non mostra nulla ma è già gatata è ciò che rende il gate
 * *dimostrabile* prima che ci sia qualcosa da proteggere — l'ordine opposto
 * mette la guardia addosso a una vista già scritta, e la prova diventa «non
 * sembra rotto».
 */
#[Layout('components.layouts.app')]
class Cabina extends Component
{
    use AmministraAccount, ElencaClienti, EsportaClienti, FissaVisibilitaSede, OffreImpersonazione, ProvisionaCliente, SegnaPreferiti;

    /**
     * Chiude ogni modale della pagina.
     *
     * ⚠️ Vive **qui** e non nei quattro concern per una ragione precisa: i
     * concern si scrivevano le property a vicenda — `ProvisionaCliente` toccava
     * `pannello` di `AmministraAccount` e `sceltaImpersonazione` di
     * `OffreImpersonazione` senza dichiararli — quindi usati da soli sarebbero
     * fatali, e l'invariante «una modale alla volta» era **a senso unico**:
     * aprire il provisioning chiudeva le altre, aprire le altre non chiudeva il
     * provisioning. Con `x-ui.modal` a tutto schermo il caso non è raggiungibile
     * col mouse, ma è la riga che il prossimo copia — e copierebbe quella
     * sbagliata a seconda del file che legge per primo.
     *
     * Il componente è il solo posto che conosce **tutte** le modali: è suo il
     * compito di sapere che sono mutuamente esclusive.
     */
    public function chiudiOgniModale(): void
    {
        $this->accountInLavorazione = null;
        $this->pannello = '';
        $this->sceltaImpersonazione = null;
        $this->provisioningAperto = false;
        $this->provisioningAccount = null;
    }

    public function render(): View
    {
        // Un solo oggetto e non quattro chiamate sparse: i numeri nascono dalla
        // stessa passata, quindi restano d'accordo fra loro. È anche ciò che
        // attraversa `porta()` — il conteggio delle **sedi** è la prova che il
        // confine è stato passato davvero, perché `accounts()` da solo non
        // attraversa alcuno scope (Account è modello di piattaforma) e lo stesso
        // numero lo darebbe una query nuda di un Tenant qualunque.
        //
        // Gli **andamenti** stanno accanto e non altrove per la stessa ragione:
        // passano dalla stessa porta (quindi non serve e non si scrive una
        // guardia nuova — sono gli stessi dati dei quattro KPI, dalla stessa
        // pagina) e partono dallo stesso `PerimetroClienti`, così la curva chiude
        // sul numero della tile che le sta a due centimetri invece che su uno
        // plausibile e diverso.
        //
        // ⚠️ Costano **tre query costanti** — non una per mese e non una per
        // tile — e girano a **ogni** update di Livewire, ricerca compresa
        // (`wire:model.live.debounce.300ms` su `search`): quel costo si ripaga a
        // ogni tasto, ed è il motivo per cui il vincolo delle tre query non si
        // molla. La risposta al giorno in cui pesasse è un indice, non una cache.
        $clienti = $this->clienti();
        $dettagli = $this->dettagliDellaPagina($clienti->getCollection());

        return view('livewire.piattaforma.cabina', [
            'riepilogo' => MetrichePiattaforma::riepilogo(),
            'andamento' => AndamentiPiattaforma::ultimiDodiciMesi(),
            'clienti' => $clienti,
            'sediPerAccount' => $dettagli['sedi'],
            'strumentiPerAccount' => $dettagli['strumenti'],
            'strumentiPerSede' => $dettagli['perSede'],
            'candidatiPerAccount' => $this->candidatiDellaPagina($clienti->getCollection()),
        ]);
    }
}
