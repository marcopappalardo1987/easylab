{{-- Stato — 🔗 DS §4, §5.2, ADR-005/014. --}}

@php
    use App\Enums\StatoSemaforo;

    // ⚠️ **Costruiti in memoria e MAI salvati.** `new Strumento` più
    // un'assegnazione diretta non tocca il database: i cast dei modelli lavorano
    // in memoria, e le due relazioni lette qui (`tenant`, `forcedBy`) non fanno
    // query — la prima perché `tenant_id` è nullo e un `belongsTo` senza chiave
    // restituisce `null` senza interrogare nessuno, la seconda perché la si
    // fornisce già risolta con `setRelation()`. È ciò che permette a questa
    // pagina di esistere senza autenticazione e senza fixture.
    $forzato = new App\Models\Strumento;
    $forzato->forced_state = StatoSemaforo::Rosso;
    $forzato->forced_at = now()->subDays(3);
    $forzato->forced_reason = 'Perdita olio dal riduttore, in attesa del ricambio.';
    // ⚠️ `setRelation()` e non `forced_by = 1`: con la chiave valorizzata
    // `$strumento->forcedBy` andrebbe **a database** a cercare l'utente 1, e il
    // banco smetterebbe di essere una pagina che non tocca un dato. Il nome si
    // assegna a mano invece che con `new User([...])`, che passerebbe dal
    // `$fillable` — se un giorno `name` ne uscisse, qui comparirebbe un «—».
    $autoreForzatura = new App\Models\User;
    $autoreForzatura->name = 'Marco Pappalardo';
    $forzato->setRelation('forcedBy', $autoreForzatura);

    // `sogliaObsolescenza()` senza Ente ricade sul default di dieci anni
    // (ADR-014): dodici anni fa è obsoleto, due no.
    $obsoleto = new App\Models\Strumento;
    $obsoleto->data_installazione = today()->subYears(12);

    $recente = new App\Models\Strumento;
    $recente->data_installazione = today()->subYears(2);
@endphp

@component('banco.pezzo', [
    'titolo' => 'x-ui.semaforo — i tre stati, taglia sm, senza etichetta',
    'token' => 'text-ok-dot ● · text-warn-dot ◐ · text-bad-dot ■ · dot-sm · sr-only',
    'nota' => "⛔ La tripletta è colore + FORMA + etichetta (DS §4, ADR-005). Senza etichetta visibile la parola resta in `sr-only` e nel `title`: il pallino non è mai solo un colore. In tema scuro la coppia verde↔arancione scende a ΔE 6,9 (DS §2.5) — è legittimo solo perché ogni voce porta anche il glifo.",
])
    <x-ui.semaforo :stato="StatoSemaforo::Verde" />
    <x-ui.semaforo :stato="StatoSemaforo::Arancione" />
    <x-ui.semaforo :stato="StatoSemaforo::Rosso" />
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.semaforo — taglia md, con etichetta',
    'token' => 'dot-md (24px) · text-ink per la parola',
    'nota' => "`sm` sta in tabella, `md` in card e nell'intestazione della scheda: la misura del DS è il DIAMETRO del pallino, non la `font-size` — il glifo ● occupa circa metà del suo em.",
])
    <x-ui.semaforo :stato="StatoSemaforo::Verde" size="md" label />
    <x-ui.semaforo :stato="StatoSemaforo::Arancione" size="md" label />
    <x-ui.semaforo :stato="StatoSemaforo::Rosso" size="md" label />
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.semaforo — le quattro combinazioni, sullo stesso stato',
    'token' => 'size=sm|md × label=false|true',
    'nota' => "Le due dimensioni accanto: è l'unico modo di accorgersi se una delle due smette di essere leggibile a 360px.",
])
    <x-ui.semaforo :stato="StatoSemaforo::Arancione" />
    <x-ui.semaforo :stato="StatoSemaforo::Arancione" label />
    <x-ui.semaforo :stato="StatoSemaforo::Arancione" size="md" />
    <x-ui.semaforo :stato="StatoSemaforo::Arancione" size="md" label />
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.semaforo-forzato — la pill ⚑, accanto al semaforo',
    'token' => 'bg-warn-soft · text-warn-soft-ink · glifo ⚑ + sr-only col dettaglio',
    'nota' => "È un elemento SEPARATO dal pallino, non una sua variante: «■ Strumento non idoneo ⚑». Il `title` porta chi, quando e perché (ADR-005). ⚠️ Su uno strumento non forzato il componente non rende NULLA — il secondo esempio è quel caso, ed è vuoto di proposito.",
])
    <span class="inline-flex items-center gap-1.5">
        <x-ui.semaforo :stato="$forzato->statoSemaforoEffettivo()" size="md" label />
        <x-ui.semaforo-forzato :strumento="$forzato" />
    </span>

    <span class="inline-flex items-center gap-1.5 text-sm text-ink-3">
        <x-ui.semaforo-forzato :strumento="$recente" />
        (non forzato: nessun markup)
    </span>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.obsoleto — il badge ⏳',
    'token' => 'bg-obs-soft · text-obs-soft-ink · glifo ⏳ + parola',
    'nota' => "Era una velatura del gradino pieno (`obsolete-500/10`): un'opacità è calcolata sul fondo che ha sotto, e sulla superficie scura un viola al 10% è quasi il fondo stesso — il badge sparirebbe nel tema in cui serve di più. Convive col semaforo e non lo altera (ADR-014). ⚠️ Su uno strumento non obsoleto non rende nulla.",
])
    <span class="inline-flex items-center gap-1.5">
        <x-ui.semaforo :stato="StatoSemaforo::Verde" label />
        <x-ui.obsoleto :strumento="$obsoleto" />
    </span>

    <span class="inline-flex items-center gap-1.5 text-sm text-ink-3">
        <x-ui.obsoleto :strumento="$recente" />
        (installato due anni fa: nessun markup)
    </span>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.stat-tile — con dettaglio, e col semaforo nello slot',
    'token' => 'x-ui.card · label text-ink-2 · numero text-ink · dettaglio text-ink-3',
    'nota' => "⚠️ Il semaforo va nello slot `label`, mai anche come prop: la parola comparirebbe due volte nel testo della pagina — una visibile e una in `sr-only` — e ogni asserzione su quel testo diventerebbe ambigua (DS §5.2). La formattazione degli interi vive nel componente: «5.105» e «7» non possono avere due convenzioni sulla stessa riga.",
])
    <div class="grid w-full gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-tile label="Strumenti" :valore="5105" dettaglio="di cui 2 🔒" />
        <x-ui.stat-tile label="Sedi" :valore="7" />
        <x-ui.stat-tile :valore="128">
            <x-ui.semaforo :stato="StatoSemaforo::Arancione" label />
        </x-ui.stat-tile>
        <x-ui.stat-tile label="Ultimo import" valore="12/08/2026" dettaglio="404 righe, 3 scartate" />
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-errori.cifre — le due cifre di una issue',
    'token' => 'nessun colore proprio: tabular-nums, e la tinta si EREDITA',
    'nota' => "Il componente non porta un `text-*` suo di proposito: ereditare è ciò che lo rende già corretto nei due temi senza possedere un token. Qui è montato dentro due tinte diverse — `text-ink-2` e `text-ink-3` — ed è esattamente il caso che si romperebbe scrivendogli addosso un colore «per coerenza».",
])
    @php
        // Anche questa è un'istanza mai salvata: al componente servono due interi.
        $issue = new App\Models\Errore;
        $issue->occorrenze = 12480;
        $issue->contesti = 20;
    @endphp

    <p class="w-full text-sm text-ink-2"><x-errori.cifre :errore="$issue" /></p>
    <p class="w-full text-xs text-ink-3"><x-errori.cifre :errore="$issue" /></p>
@endcomponent
