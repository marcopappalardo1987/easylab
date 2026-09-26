{{-- Superfici e azioni — 🔗 DS §5.1, §5.2, §5.4, §8.2. --}}

@component('banco.pezzo', [
    'titolo' => 'x-ui.button — le quattro varianti',
    'token' => 'bg-brand/text-brand-ink · border-border-strong+bg-surface · bg-bad-dot/text-ink-inverse · text-ink-2 hover:bg-surface-sunken',
    'nota' => 'Il secondario porta il bordo FORTE, non quello dei divisori: è ciò che lo tiene distinto dalla superficie su cui sta, in entrambi i temi. La larghezza del bordo sta nella base e il colore in ogni variante, o una fila «Annulla · Salva» non si allineerebbe.',
])
    <x-ui.button>Salva</x-ui.button>
    <x-ui.button variant="secondary">Annulla</x-ui.button>
    <x-ui.button variant="danger">Elimina</x-ui.button>
    <x-ui.button variant="ghost">Ignora</x-ui.button>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.button — disabilitato',
    'token' => 'disabled:opacity-60 · disabled:cursor-not-allowed',
    'nota' => 'Il disabilitato è la stessa variante a opacità ridotta: nessun token proprio, quindi funziona su qualunque fondo e in entrambi i temi senza una seconda decisione da tenere allineata.',
])
    <x-ui.button disabled>Salva</x-ui.button>
    <x-ui.button variant="secondary" disabled>Annulla</x-ui.button>
    <x-ui.button variant="danger" disabled>Elimina</x-ui.button>
    <x-ui.button variant="ghost" disabled>Ignora</x-ui.button>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.button — con icona, e come link',
    'token' => 'gap-2 · stroke="currentColor" · focus:ring-ring focus:ring-offset-canvas',
    'nota' => "L'icona eredita il colore del bottone (`currentColor`): non porta un colore suo, quindi non ha un tema suo. Con `href` il componente rende un `<a>` e non un `<button>`, con le stesse identiche classi — la navigazione resta un link, cioè apribile in una scheda nuova.",
])
    <x-ui.button>
        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Nuovo intervento
    </x-ui.button>

    <x-ui.button variant="secondary">
        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
        </svg>
        Esporta
    </x-ui.button>

    <x-ui.button variant="ghost" href="#azioni">Vai all'elenco</x-ui.button>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.card',
    'token' => 'bg-surface · border-border · shadow-sm · rounded-lg',
])
    <x-ui.card class="w-full max-w-sm">
        <p class="text-sm font-semibold text-ink">Compressore SC-200</p>
        <p class="mt-1 text-sm text-ink-2">Reparto Stampaggio · installato il 12/03/2019</p>
        <p class="mt-1 text-xs text-ink-3">Ultimo intervento: 4 giorni fa</p>
    </x-ui.card>
@endcomponent

@component('banco.pezzo', [
    'titolo' => "x-ui.card — colorata dall'esterno, e la trappola dell'ordinamento",
    'token' => '!bg-warn-soft · !border-warn-dot — contro bg-surface · border-border interni al componente',
    'nota' => "🔴 Le quattro card montano lo STESSO componente: cambia solo l'`!`. Il componente porta dentro `bg-surface border-border`, e la classe passata da fuori finisce nella stessa `class`: a decidere quale vince non è l'ordine in cui sono scritte, ma l'ordine nel foglio generato — che Tailwind emette in ordine alfabetico del nome della classe. Misurato nel bundle: `bg-warn-soft` viene dopo `bg-surface` e quindi vince anche senza `!`; `bg-bad-soft` viene prima e perde, e la terza card resta della superficie del componente. ⚠️ È la parte scomoda: la regola non è «chi sta fuori perde», è «decide l'alfabeto» — cioè un colore passato dall'esterno funziona o no a seconda di come si chiama, e chi lo scrive non ha modo di saperlo. Da cui l'`!`, sempre. È la stessa trappola già pagata sul bordo del bottone secondario e sul peso del carattere di `x-app.nav-link`.",
])
    <x-ui.card class="w-full max-w-xs !border-warn-dot !bg-warn-soft">
        <p class="text-sm font-semibold text-warn-soft-ink">warn, con <span class="font-mono">!</span></p>
        <p class="mt-1 text-sm text-warn-soft-ink">Colorata: l'importante vince sempre.</p>
    </x-ui.card>

    <x-ui.card class="w-full max-w-xs border-warn-dot bg-warn-soft">
        <p class="text-sm font-semibold text-warn-soft-ink">warn, senza <span class="font-mono">!</span></p>
        <p class="mt-1 text-sm text-warn-soft-ink">Colorata lo stesso — per fortuna, non per progetto.</p>
    </x-ui.card>

    <x-ui.card class="w-full max-w-xs !border-bad-dot !bg-bad-soft">
        <p class="text-sm font-semibold text-bad-soft-ink">bad, con <span class="font-mono">!</span></p>
        <p class="mt-1 text-sm text-bad-soft-ink">Colorata.</p>
    </x-ui.card>

    <x-ui.card class="w-full max-w-xs border-bad-dot bg-bad-soft">
        <p class="text-sm font-semibold text-bad-soft-ink">bad, senza <span class="font-mono">!</span></p>
        <p class="mt-1 text-sm text-bad-soft-ink">Resta la superficie del componente: il colore ha perso.</p>
    </x-ui.card>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.badge — tutte e sei le varianti',
    'token' => 'bg-surface-sunken+inset-ring-border · bg-ok-soft · bg-warn-soft · bg-bad-soft · bg-brand-soft · bg-brand-soft-strong',
    'nota' => "`neutral` porta un filo di bordo INTERNO: su una card la superficie incassata è quasi dello stesso colore del fondo, in entrambi i temi, e senza quel filo la pill smetterebbe di essere una pill. `info` e `primary` sono due gradini dello stesso blu, non due blu (ADR-033).",
])
    <x-ui.badge>Neutro</x-ui.badge>
    <x-ui.badge variant="success">In regola</x-ui.badge>
    <x-ui.badge variant="warning">In scadenza</x-ui.badge>
    <x-ui.badge variant="danger">Scaduto</x-ui.badge>
    <x-ui.badge variant="info">Informativo</x-ui.badge>
    <x-ui.badge variant="primary">Corrente</x-ui.badge>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.modal — aperta, col velo',
    'token' => 'bg-overlay · bg-surface+border-border+shadow-md · chiusura size-11 (44px)',
    'nota' => "⚠️ Due cose sono proprie del banco e non del componente. (1) La modale è `fixed`: qui è contenuta da un antenato con un `transform`, che le fa da blocco di contenimento — nell'app copre la finestra. (2) `close` nomina un metodo Livewire, e qui non c'è un componente Livewire: il bottone non chiude niente, e premere Esc scrive in console un errore di Alpine («Could not find Livewire component»). È atteso, non è un difetto della modale.",
])
    {{-- ⚠️ `transform: translateZ(0)` e non una classe: serve a creare il blocco
         di contenimento per i discendenti `position: fixed`, che è l'unico modo
         di guardare una modale *dentro* la pagina invece che sopra tutto il
         banco. Sta inline perché è una proprietà del ponteggio, non uno stile
         del prodotto — e perché una classe di utility qui direbbe che il
         trucco riguarda la modale. --}}
    <div class="relative h-96 w-full overflow-hidden rounded-lg border border-border bg-canvas"
         style="transform: translateZ(0)">
        <div class="p-4">
            <p class="text-sm text-ink-2">Il contenuto che la modale copre.</p>
        </div>

        <x-ui.modal title="Forza lo stato del semaforo" close="chiudiEsempio">
            <p class="text-sm text-ink-2">
                Lo stato forzato vince su quello calcolato finché non viene rimosso.
                Il motivo è obbligatorio per il rosso.
            </p>

            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <x-ui.button variant="secondary">Annulla</x-ui.button>
                <x-ui.button>Forza lo stato</x-ui.button>
            </div>
        </x-ui.modal>
    </div>
@endcomponent
