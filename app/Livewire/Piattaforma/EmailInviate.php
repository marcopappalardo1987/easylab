<?php

namespace App\Livewire\Piattaforma;

use App\Models\EmailInviata;
use App\Support\Email\CatalogoEmail;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Piattaforma → Email inviate: quali email sono partite, a chi e quando
 * (🔗 ADR-064; ADR-063 il server di posta, ADR-047 il catalogo).
 *
 * ## 🔴 Il permesso è `tenants.view_all`, e si rifà a ogni render
 *
 * Il registro elenca gli indirizzi di tutti i clienti, quindi sta dietro lo
 * stesso permesso del registro di audit: nel set bloccato, oggi del Developer
 * e del Superadmin. Non `tenants.provision` come la pagina Email, perché qui si
 * legge e basta. Il `can:` di rotta dice «puoi stare qui»; `render()` lo chiede
 * di nuovo, perché ogni cambio di filtro è una richiesta Livewire che non
 * ripassa dal middleware.
 *
 * I filtri arrivano dal browser e finiscono in query string: il tipo vale solo
 * se è a catalogo, lo stato solo se è uno dei tre, il testo è una ricerca con
 * i jolly di SQL neutralizzati.
 */
#[Title('Email inviate — Piattaforma — Easy Lab')]
#[Layout('components.layouts.app')]
class EmailInviate extends Component
{
    use WithPagination;

    public const PERMESSO = VistaPiattaforma::PERMESSO;

    /** Sentinella del filtro «Tipo» per le email fuori catalogo (le prove di invio). */
    public const FUORI_CATALOGO = 'fuori_catalogo';

    private const PER_PAGINA = 50;

    #[Url]
    public string $cerca = '';

    #[Url]
    public string $tipo = '';

    #[Url]
    public string $stato = '';

    public function mount(): void
    {
        Gate::authorize(self::PERMESSO);
    }

    public function updating(string $proprieta): void
    {
        if (in_array($proprieta, ['cerca', 'tipo', 'stato'], true)) {
            $this->resetPage();
        }
    }

    /** @return array<string, string> */
    public function stati(): array
    {
        return [
            EmailInviata::STATO_ACCETTATA => 'Accettate dal server',
            EmailInviata::STATO_NON_PARTITA => 'Non partite',
            EmailInviata::STATO_IN_INVIO => 'In invio',
        ];
    }

    /** @return Builder<EmailInviata> */
    private function filtrata(): Builder
    {
        $query = EmailInviata::query();

        $termine = trim($this->cerca);

        if ($termine !== '') {
            $jolly = '%'.addcslashes(mb_strtolower($termine), '%_\\').'%';

            $query->where(fn (Builder $q) => $q
                ->whereRaw('lower(destinatari) like ?', [$jolly])
                ->orWhereRaw('lower(oggetto) like ?', [$jolly]));
        }

        if ($this->tipo === self::FUORI_CATALOGO) {
            $query->whereNull('tipo');
        } elseif (CatalogoEmail::esiste($this->tipo)) {
            $query->where('tipo', $this->tipo);
        }

        // Uno stato che non esiste non filtra niente: lo decide lo scope.
        $query->nelloStato($this->stato);

        return $query;
    }

    public function render(): View
    {
        Gate::authorize(self::PERMESSO);

        return view('livewire.piattaforma.email-inviate', [
            // L'id è unico e cresce con il tempo: ordina da solo, senza pari fra due pagine.
            'righe' => $this->filtrata()->orderByDesc('id')->paginate(self::PER_PAGINA)->onEachSide(1),
            'tipi' => CatalogoEmail::tutte(),
            'filtriApplicati' => trim($this->cerca) !== '' || $this->tipo !== '' || $this->stato !== '',
        ]);
    }
}
