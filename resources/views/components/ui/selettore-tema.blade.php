@props([
    // ⚠️ **La verità, e viene dal SERVER.** Chi monta questo componente passa
    // qui `users.tema` letta a ogni render, non una proprietà pubblica che il
    // client possa scrivere: è `aria-pressed` a dire quale dei tre stati è
    // attivo, e deve dire ciò che il DATABASE sa, non ciò che il browser crede.
    'corrente' => null,

    // Il metodo Livewire che scrive la colonna. Il componente non sa **chi** lo
    // ospita: sa solo come chiamarlo — così la stessa forma serve la pagina
    // Preferenze e la scorciatoia della top bar (F3) senza duplicarsi.
    'azione' => 'scegliTema',
])

@php
    // `oSistema()` e non il valore nudo: un `User` costruito in memoria non ha
    // riletto il default dello schema e porta `null`. Segue il sistema, che è
    // il default anche a database — mai un errore per un attributo mancante.
    $corrente = \App\Enums\TemaUtente::oSistema($corrente);

    // ⛔ **Forma + parola, non la sola forma** (DS §4, ADR-005). Una luna e un
    // monitor non dicono niente a chi non li riconosce: l'icona è
    // `aria-hidden`, la parola vive in `sr-only`, e il nome accessibile del
    // bottone non è mai vuoto. È la stessa tripletta del semaforo, un gradino
    // più in basso — qui il colore da solo direbbe ancora meno, perché i tre
    // bottoni hanno lo stesso colore e a distinguerli è la forma.
    //
    // ⚠️ **Perché SVG e non i caratteri ☀ ☾ ⌂ della roadmap.** Misurato, non
    // preferito: la prima stesura li usava, e su macOS `☀` e `☾` hanno
    // **presentazione emoji** — vengono disegnati gialli dal font di sistema,
    // ignorano `currentColor` e quindi **non seguono lo stato premuto**, che è
    // l'unica cosa che quell'icona deve saper dire. `⌂` per giunta manca da
    // molti font e altrove diventerebbe un rettangolo vuoto. Il campione monta
    // infatti dei simboli SVG (`#i-sole`, `#i-luna`), e il resto dell'app usa
    // già icone di tratto: qui si copia l'architettura, non l'abbreviazione con
    // cui i tre stati sono scritti in roadmap.
    //
    // ⚠️ La coppia forma→parola sta **qui e in nessun altro posto**: metterla
    // sull'enum la renderebbe una proprietà di dominio, e `TemaUtente` è già
    // citata dai due layout — un enum che porta anche la grafica finisce
    // importato dove serve solo il valore.
    $stati = [
        [
            'tema' => \App\Enums\TemaUtente::Chiaro,
            'parola' => 'Tema chiaro',
            'icona' => 'M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z',
        ],
        [
            'tema' => \App\Enums\TemaUtente::Scuro,
            'parola' => 'Tema scuro',
            'icona' => 'M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z',
        ],
        [
            // Un monitor, e non una casa: «sistema» qui è **il dispositivo**, ed
            // è la forma che GitHub, VS Code e macOS usano per lo stesso stato.
            // Un'icona che va indovinata non è un'icona.
            'tema' => \App\Enums\TemaUtente::Sistema,
            'parola' => 'Tema di sistema',
            'icona' => 'M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25',
        ],
    ];
@endphp

{{--
    Il selettore di tema a tre stati (🔗 ADR-034 — DS §8.3, DS §5.1).

    **Tre stati e non un interruttore.** «Sistema» non è l'assenza di una
    scelta: è la scelta di farsi decidere dal dispositivo, e senza di essa
    l'interruttore avrebbe una sola direzione — chi passa a chiaro non potrebbe
    più tornare indietro. È la stessa ragione per cui `app.css` porta il
    `:not([data-theme="light"])` sulla media query.

    **Chi scrive cosa, nello stesso click.**

      · **Livewire** scrive `users.tema` — la verità, quella che l'utente
        ritrova da un altro dispositivo (ADR-034 punto 3);
      · **Alpine** scrive `data-theme` sull'`<html>` e `localStorage` — l'effetto
        immediato, e la cache che evita il lampo bianco alla pagina di accesso.

    ⚠️ **Perché Alpine deve scrivere l'attributo, e non è una comodità.** Un
    morph di Livewire ridisegna il componente, **non l'`<html>`**: senza il
    gesto di Alpine il tema cambierebbe solo al ricaricamento successivo, cioè
    l'interruttore sembrerebbe rotto pur avendo salvato.

    ⚠️ **E `aria-pressed` viene comunque dal server.** È la regola già imparata
    dal combobox — *lo stato Alpine può solo NASCONDERE ciò che il server ha
    deciso di mostrare, mai il contrario*: dopo un morph `x-data` si
    reinizializza, e un flag di visibilità tenuto solo in Alpine tornerebbe al
    default a ogni render. Qui Alpine **anticipa** l'attributo con una scrittura
    diretta sul DOM, e il morph successivo lo riporta a ciò che dice il
    database: se il server rifiutasse il valore, l'evidenziazione tornerebbe
    indietro da sé.

    ⛔ **Va montato DENTRO un componente Livewire, e non è una preferenza di
    stile.** `wire:click` fuori da Livewire è un attributo inerte: non dà
    errore, semplicemente non chiama nessuno — la pagina cambierebbe colore
    (Alpine) senza che `users.tema` si muova, e al caricamento successivo
    `riallinea()` riporterebbe `localStorage` al valore del database, cioè
    **annullerebbe la scelta** senza dire perché. Per la scorciatoia in top bar
    (F3) serve quindi un componente Livewire che ospiti questo, come già fanno
    `livewire:notifiche.campanella` e `livewire:tenancy.switcher-ente`.

    ⚠️ Non coperto dai test (i test Livewire non eseguono Alpine): la scrittura
    di `data-theme` e di `localStorage`, il riallineamento all'avvio, la
    tastiera, i 44px resi davvero a schermo, la sopravvivenza al morph. Sono
    elencati per nome nel docblock di `tests/Feature/Settings/SelettoreTemaTest.php`
    e si verificano a mano.
--}}
<div
    role="group"
    aria-label="Tema"
    {{-- ⚠️ La chiave si legge da `TemaUtente::CHIAVE_LOCALSTORAGE`, **mai
         riscritta a mano**: la stessa costante che lo script d'ospite di
         `guest-layout` rende nel `<head>`. Due copie divergono, e il giorno in
         cui divergono il sintomo è un lampo bianco che nessun test vede.

         `@js()` e non `@json()`: qui siamo **dentro un attributo**, e `@json`
         emette virgolette doppie non escapate che chiuderebbero l'attributo a
         metà. `@js()` è lo stesso `json_encode` passato per l'escaping HTML —
         la fonte resta la costante, che è ciò che conta. --}}
    x-data="selettoreTema(@js(\App\Enums\TemaUtente::CHIAVE_LOCALSTORAGE))"
    x-init="riallinea()"
    {{ $attributes->class(['inline-flex items-center gap-1 rounded-full border border-border bg-surface p-1']) }}
>
    @foreach ($stati as $stato)
        <button
            type="button"
            data-tema="{{ $stato['tema']->value }}"
            {{-- Esattamente uno dei tre è `true`, e non per convenzione: i tre
                 stati sono i tre casi dell'enum e il confronto è d'identità. --}}
            aria-pressed="{{ $corrente === $stato['tema'] ? 'true' : 'false' }}"
            title="{{ $stato['parola'] }}"
            wire:click="{{ $azione }}(@js($stato['tema']->value))"
            x-on:click="applica(@js($stato['tema']->value))"
            {{-- `size-11` = **44px esatti** (DS §5.1): è un controllo da usare
                 anche col guanto, in laboratorio, e l'icona è piccola — il
                 bersaglio non lo è.

                 ⚠️ L'anello di fuoco è **senza `ring-offset`**: il colore di
                 stacco di Tailwind è bianco per default, e su fondo scuro
                 disegnerebbe un alone chiaro attorno al bottone. `ring-ring` è
                 già il token che sale a `primary-300` in tema scuro.

                 `aria-pressed:` lega lo stile **all'attributo**, non a una
                 classe calcolata: la stessa riga vale per ciò che rende il
                 server e per ciò che Alpine anticipa, e non esistono due verità
                 da tenere allineate. È anche ciò che fa il campione
                 (`.el-theme button[aria-pressed="true"]`). --}}
            class="grid size-11 place-items-center rounded-full text-ink-2 transition hover:bg-surface-sunken hover:text-ink focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-pressed:bg-brand-soft-strong aria-pressed:text-brand-soft-ink"
        >
            {{-- `stroke="currentColor"`: l'icona **eredita** il colore del
                 bottone, quindi la riga di `aria-pressed:` che tinge il testo
                 tinge anche la forma. È esattamente ciò che i caratteri emoji
                 non sapevano fare. --}}
            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $stato['icona'] }}" />
            </svg>
            <span class="sr-only">{{ $stato['parola'] }}</span>
        </button>
    @endforeach
</div>
