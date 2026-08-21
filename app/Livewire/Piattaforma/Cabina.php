<?php

namespace App\Livewire\Piattaforma;

use App\Livewire\Piattaforma\Concerns\ElencaClienti;
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
    use ElencaClienti;

    public function render(): View
    {
        // Un solo oggetto e non quattro chiamate sparse: i numeri nascono dalla
        // stessa passata, quindi restano d'accordo fra loro. È anche ciò che
        // attraversa `porta()` — il conteggio delle **sedi** è la prova che il
        // confine è stato passato davvero, perché `accounts()` da solo non
        // attraversa alcuno scope (Account è modello di piattaforma) e lo stesso
        // numero lo darebbe una query nuda di un Tenant qualunque.
        $clienti = $this->clienti();
        $dettagli = $this->dettagliDellaPagina($clienti->getCollection());

        return view('livewire.piattaforma.cabina', [
            'riepilogo' => MetrichePiattaforma::riepilogo(),
            'clienti' => $clienti,
            'sediPerAccount' => $dettagli['sedi'],
            'strumentiPerAccount' => $dettagli['strumenti'],
            'strumentiPerSede' => $dettagli['perSede'],
        ]);
    }
}
