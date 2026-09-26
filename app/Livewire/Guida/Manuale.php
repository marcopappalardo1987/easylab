<?php

namespace App\Livewire\Guida;

use App\Support\Guide\Manuale as Libreria;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * La Guida: indice a sinistra, video e testo a destra, in una pagina sola.
 *
 * 🔗 `app/Support/Guide/Manuale.php` per la lettura dei manifest,
 * `guide/CATALOGO.md` per le guide previste.
 *
 * ⚠️ **Al momento la vede solo la piattaforma** (`can:tenants.view_all`, vedi
 * la rotta). Non è una regola di sicurezza — una guida non è un dato di nessuno
 * — ma un cancello di rilascio: cinque guide su quarantasei non sono un manuale,
 * e una voce di menù che porta a un indice quasi vuoto fa più danno che bene.
 * Il permesso scelto è nel set bloccato di `config/rbac.php`, quindi l'editor
 * ruoli non può aprirlo per sbaglio a un cliente prima che il manuale sia pronto.
 *
 * Nessun global scope in gioco: le guide sono file su disco, uguali per tutti.
 * L'unica cosa che cambia da utente a utente è **se** la pagina si apre.
 */
#[Layout('components.layouts.app')]
#[Title('Guida — Easy Lab')]
class Manuale extends Component
{
    /** Il filtro di ricerca. In querystring: un risultato si manda a qualcuno. */
    #[Url(as: 'q', except: '')]
    public string $ricerca = '';

    /** La guida aperta. In querystring per la stessa ragione. */
    #[Url(as: 'guida', except: '')]
    public string $slug = '';

    public function mount(): void
    {
        // Senza scelta si apre la prima: una pagina che si apre vuota sembra
        // rotta, e la prima guida dell'indice è anche la prima da leggere.
        if ($this->slug === '' || Libreria::trova($this->slug) === null) {
            $this->slug = Libreria::tutte()->value('slug', '');
        }
    }

    public function apri(string $slug): void
    {
        if (Libreria::trova($slug) !== null) {
            $this->slug = $slug;
        }
    }

    /**
     * Cercando, la guida aperta può uscire dai risultati: si passa alla prima
     * che resta. Restare su una guida che l'indice non mostra più è il modo
     * più veloce di far credere che il filtro non funzioni.
     */
    public function updatedRicerca(): void
    {
        $trovate = $this->risultati();

        if ($trovate->isNotEmpty() && ! $trovate->contains('slug', $this->slug)) {
            $this->slug = $trovate->first()['slug'];
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    public function risultati(): Collection
    {
        return Libreria::cerca($this->ricerca);
    }

    public function render()
    {
        $risultati = $this->risultati();

        return view('livewire.guida.manuale', [
            'gruppi' => $risultati->groupBy('argomentoTitolo'),
            'trovate' => $risultati->count(),
            'totali' => Libreria::tutte()->count(),
            'guida' => Libreria::trova($this->slug),
        ]);
    }
}
