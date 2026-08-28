<?php

namespace App\Livewire\Anagrafica;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\UnitaOrganizzativa;
use App\Support\Mail\MarchioEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Il **marchio email** dell'Ente: logo e colore con cui escono digest, avvisi e
 * inviti (🔗 ADR-011; ERD §4.1).
 *
 * ## Il permesso è `unita_organizzativa.update`, e non uno nuovo
 *
 * La regola che serve è «chi può rinominare l'Ente può cambiarne il marchio», e
 * quel permesso la esprime già: Developer, Superadmin e Admin ce l'hanno;
 * Responsabile Reparto, Tenant e Tecnico hanno il solo `.view`. Un permesso
 * nuovo sarebbe costato una modifica a `config/rbac.php` e quindi un riseeding
 * di `RolesAndPermissionsSeeder` — che da S6 fa `syncPermissions()` e
 * **cancella** le personalizzazioni di runtime dell'editor `/piattaforma/ruoli`
 * in entrambe le direzioni. Un permesso in più costa la matrice di un cliente.
 *
 * ⚠️ **`unita_organizzativa.update` non è nel set `locked`**, quindi l'editor
 * ruoli può ridistribuirlo a runtime. È accettabile qui — il marchio è un dato
 * dell'anagrafica, non un confine di piattaforma — ed è la stessa lettura che
 * ha portato il registro di audit a NON usare `audit.view`. Va scritto perché
 * chi legge questa classe sappia che l'insieme di chi ci arriva è deciso a
 * runtime e non da questo file.
 *
 * ## Nessun parametro di rotta, quindi nessun IDOR da difendere
 *
 * 🔴 L'Ente si deriva dal `tenant_id` di chi guarda, con i global scope
 * **attivi**: non esiste un `/anagrafica/marchio/{ente}` da manomettere. Un
 * utente senza `tenant_id` prende 403 e non «vede tutti gli Enti»: è il
 * fail-closed di ADR-018, dove l'assenza di contesto è una negazione e non un
 * permesso.
 *
 * ⚠️ E il `can:` della rotta **non basta da solo** per le azioni che scrivono:
 * `skipRender()` fa saltare `render()`, quindi una chiamata diretta a `salva()`
 * non ripasserebbe da lì. Ogni azione ha il proprio `Gate::authorize()`.
 */
#[Layout('components.layouts.app')]
class MarchioEnte extends Component
{
    use WithFileUploads;

    public UnitaOrganizzativa $ente;

    /** `#rrggbb`, o `null` per «usa il blu di Easy Lab». */
    public ?string $colore = null;

    /** Il file appena scelto, non ancora salvato. */
    public $logo = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $enteId = Auth::user()?->tenant_id;

        // 🔴 Fail-closed: un utente autenticato SENZA tenant non vede nulla
        // (ADR-018). Senza questa riga il `firstOrFail()` qui sotto girerebbe
        // con `whereKey(null)` e darebbe 404 — che è lo stesso esito ma per la
        // ragione sbagliata, e il giorno in cui la query cambiasse forma
        // diventerebbe silenziosamente «il primo Ente che capita».
        abort_if($enteId === null, 403);

        $this->ente = UnitaOrganizzativa::whereKey($enteId)
            ->where('tipo', TipoUnitaOrganizzativa::Ente)
            ->firstOrFail();

        $this->colore = $this->ente->marchio_colore;
    }

    protected function rules(): array
    {
        return [
            // Il colore è un esadecimale a sei cifre. Vuoto = «nessuna scelta»,
            // che è il modo di tornare al blu di Easy Lab senza un secondo
            // comando.
            'colore' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // ⛔ **SVG escluso due volte**, e non per ridondanza: `image` di
            // Laravel non ammette più l'SVG senza `allow_svg`, e `mimes` lo
            // esclude comunque. Un SVG è XML eseguibile, e per giunta Gmail e
            // Outlook non lo rendono — cioè sarebbe una testata vuota proprio
            // sui due client che contano.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:512', 'dimensions:max_width=1200,max_height=400'],
        ];
    }

    protected function messages(): array
    {
        return [
            'colore.regex' => 'Il colore va scritto come #rrggbb, per esempio #06589c.',
            'logo.image' => 'Il logo dev\'essere un\'immagine PNG o JPG.',
            'logo.mimes' => 'Sono ammessi solo PNG e JPG: i client di posta non rendono gli SVG.',
            'logo.max' => 'Il logo non può superare i 512 KB.',
            'logo.dimensions' => 'Il logo non può superare 1200×400 pixel.',
        ];
    }

    public function salva(): void
    {
        Gate::authorize('unita_organizzativa.update');

        $this->validate();

        $precedente = $this->ente->marchio_logo_path;
        $path = $precedente;

        if ($this->logo !== null) {
            // 🔴 **L'id dell'Ente STA NEL PERCORSO**, ed è ciò che rende
            // impossibile per costruzione che il file di un Ente finisca sotto
            // quello di un altro: il percorso non arriva mai dal client, si
            // costruisce qui con l'Ente che `mount()` ha già risolto dal
            // `tenant_id`. Il nome è un ULID e non quello del file caricato —
            // un nome scelto dall'utente è un percorso scelto dall'utente.
            $path = $this->logo->storeAs(
                'marchi/'.$this->ente->id,
                (string) Str::ulid().'.'.strtolower($this->logo->extension()),
                MarchioEmail::DISCO,
            );

            $this->cancellaFile($precedente, $path);
        }

        $this->ente->fissaMarchioEmail($path, $this->colore ?: null);

        $this->reset('logo');
        $this->notice = 'Marchio aggiornato.';
    }

    public function rimuoviLogo(): void
    {
        Gate::authorize('unita_organizzativa.update');

        $precedente = $this->ente->marchio_logo_path;

        $this->ente->fissaMarchioEmail(null, $this->ente->marchio_colore);

        $this->cancellaFile($precedente, null);

        $this->reset('logo');
        $this->notice = 'Logo rimosso: le email tornano al marchio Easy Lab.';
    }

    /**
     * Il file sostituito se ne va, ma un errore del disco non fa fallire il
     * salvataggio.
     *
     * ⚠️ Il disco `documenti` ha `throw => true`: un `delete()` su un file già
     * sparito **lancia**, e senza questo `try` un secondo salvataggio dopo una
     * cancellazione manuale butterebbe giù la pagina per un file che non c'è
     * più — cioè per un problema già risolto. La riga a registro basta.
     *
     * La guardia `$nuovo !== $precedente` è la rete contro il caso in cui i due
     * percorsi coincidano: cancellare il file appena scritto lascerebbe la
     * colonna che punta al nulla.
     */
    private function cancellaFile(?string $precedente, ?string $nuovo): void
    {
        if ($precedente === null || $precedente === $nuovo) {
            return;
        }

        try {
            Storage::disk(MarchioEmail::DISCO)->delete($precedente);
        } catch (Throwable $e) {
            Log::warning('Logo del marchio non cancellato', [
                'path' => $precedente,
                'errore' => $e->getMessage(),
            ]);
        }
    }

    /**
     * L'anteprima chiede l'inchiostro allo **stesso** metodo che lo calcola per
     * l'email (luminanza WCAG): riscriverlo qui vorrebbe dire avere due
     * risposte alla stessa domanda, e la seconda si accorgerebbe di essere
     * sbagliata solo guardando una email già partita.
     */
    public function render()
    {
        $scelto = $this->colore ?: MarchioEmail::COLORE_EASYLAB;

        return view('livewire.anagrafica.marchio-ente', [
            'coloreScelto' => $scelto,
            'inchiostro' => MarchioEmail::inchiostroSu($scelto),
        ]);
    }
}
