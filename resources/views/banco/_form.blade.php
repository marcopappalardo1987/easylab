{{-- Campi — 🔗 DS §5.6, §8.2. --}}

@php
    // ⚠️ **L'errore va MESSO NEL BAG CONDIVISO, e non c'è una via più corta.**
    // `@error($name)` dentro `x-ui.input` legge `$errors` dai dati *condivisi*
    // della vista (li mette `ShareErrorsFromSession`), non da una variabile
    // locale: una `$errors` dichiarata qui non arriverebbe mai dentro il
    // componente, e il pezzo «campo con errore» mostrerebbe un campo normale —
    // cioè un banco che dice che lo stato d'errore non esiste.
    //
    // Le chiavi sono nomi che nessun altro campo del banco usa: la condivisione
    // è di pagina, quindi un nome ripetuto accenderebbe l'errore anche sul
    // campo che deve restare pulito.
    view()->share('errors', (new Illuminate\Support\ViewErrorBag)->put('default', new Illuminate\Support\MessageBag([
        'campo_in_errore' => ['La data di scadenza non può essere nel passato.'],
        'nota_in_errore' => ['La nota non può superare i 500 caratteri.'],
        'ricambio_in_errore' => ['Scegli un pezzo a catalogo o creane uno nuovo.'],
    ])));
@endphp

@component('banco.pezzo', [
    'titolo' => 'x-ui.input — normale e col placeholder',
    'token' => 'border-border-strong · bg-surface · text-ink · placeholder:text-ink-3 · label text-ink',
    'nota' => "Il placeholder è `text-ink-3` e mai più chiaro: a `neutral-400` faceva 2,56:1 su bianco, sotto AA (DS §2.2). `bg-surface` è dichiarato e non ereditato — la preflight di Tailwind rende trasparenti i controlli di form, e un campo posato su `bg-canvas` prenderebbe il fondo della pagina invece della propria superficie.",
])
    <div class="w-full max-w-xs">
        <x-ui.input name="matricola" label="Matricola" value="SC-200/2019" />
    </div>

    <div class="w-full max-w-xs">
        <x-ui.input name="cerca" label="Ricerca" placeholder="Cerca per matricola o modello…" />
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.input — con errore, e disabilitato',
    'token' => 'errore: text-bad-soft-ink (e NON il gradino danger-600) · disabled:bg-surface-sunken · disabled:text-ink-3',
    'nota' => "🔴 Il testo d'errore è `text-bad-soft-ink`: §5.6 scriveva `danger-600`, che su `--surface` scuro fa 2,64:1 — illeggibile proprio nel tema in cui un errore conta di più (DS §8.2, nota 4). Il campo disabilitato affonda su `surface-sunken`, che è il ruolo della superficie incassata.",
])
    <div class="w-full max-w-xs">
        <x-ui.input name="campo_in_errore" label="Data di scadenza" value="01/01/2020" />
    </div>

    <div class="w-full max-w-xs">
        <x-ui.input name="matricola_bloccata" label="Matricola (non modificabile)" value="SC-200/2019" disabled />
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.input — un controllo nativo (type="date")',
    'token' => 'color-scheme (app.css) · focus:border-brand focus:ring-ring',
    'nota' => "⚠️ È il pezzo che verifica una riga che non è un colore: `color-scheme` in `app.css`. Senza, in tema scuro l'icona del calendario, il selettore nativo e le scrollbar resterebbero chiari sul fondo scuro — è l'unica dichiarazione che parla al browser invece che alla pagina. Va guardato aperto, non solo chiuso.",
])
    <div class="w-full max-w-xs">
        <x-ui.input name="data_prevista" label="Data prevista" type="date" value="2026-09-15" />
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.textarea — normale, col placeholder, con errore, disabilitata',
    'token' => 'stessi token di x-ui.input · rows',
    'nota' => "Stessi token e stesse tre scelte di `x-ui.input`: se una delle due cambiasse da sola, la differenza si vedrebbe qui accanto invece che in due pagine diverse dell'applicazione.",
])
    <div class="w-full max-w-xs">
        <x-ui.textarea name="note" label="Note">Sostituita la guarnizione della testa.</x-ui.textarea>
    </div>

    <div class="w-full max-w-xs">
        <x-ui.textarea name="note_vuote" label="Note" placeholder="Che cosa è stato fatto…" />
    </div>

    <div class="w-full max-w-xs">
        <x-ui.textarea name="nota_in_errore" label="Nota">Testo troppo lungo…</x-ui.textarea>
    </div>

    <div class="w-full max-w-xs">
        <x-ui.textarea name="note_bloccate" label="Note (intervento chiuso)" disabled>Intervento chiuso il 12/08/2026.</x-ui.textarea>
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.combobox — chiuso, aperto, «nuovo», in errore',
    'token' => 'border-border-strong · bg-surface · dropdown bg-surface+border-border+shadow-md · opzione hover:bg-brand-soft · min-h-[44px]',
    'nota' => "⛔ Qui si guardano solo i colori: gli `aria-*`, il percorso di selezione via `wire:click` e il fallback senza JavaScript sono decisioni già prese e verificate. ⚠️ Il dropdown esiste se e solo se il server ha dei suggerimenti — non dipende da uno stato Alpine — quindi sul banco è aperto davvero, senza simularlo. Le due voci sono `<button wire:click>` veri: qui non c'è Livewire, quindi non selezionano niente.",
])
    <div class="w-full max-w-xs">
        <x-ui.combobox name="ricambio_chiuso" label="Pezzo" onSelect="scegli" :index="0"
                       placeholder="Cerca o crea un pezzo…" />
    </div>

    {{-- ⚠️ Il margine in basso è per il banco e non per il componente: il
         dropdown è `absolute`, quindi senza spazio riservato coprirebbe il
         pezzo che gli sta sotto invece di mostrarsi accanto. --}}
    <div class="mb-28 w-full max-w-xs">
        <x-ui.combobox name="ricambio_aperto" label="Pezzo" onSelect="scegli" :index="0" value="Guar"
                       :suggerimenti="[['nome' => 'Guarnizione testa 40mm'], ['nome' => 'Guarnizione OR 12x2']]" />
    </div>

    <div class="w-full max-w-xs">
        <x-ui.combobox name="ricambio_nuovo" label="Pezzo" onSelect="scegli" :index="0"
                       value="Filtro aria HX-9" stato="nuovo" />
    </div>

    <div class="w-full max-w-xs">
        <x-ui.combobox name="ricambio_in_errore" label="Pezzo" onSelect="scegli" :index="0" value="???" />
    </div>
@endcomponent
