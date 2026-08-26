<!DOCTYPE html>
<html lang="it" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Easy Lab') }}</title>

    {{-- Il tema dell'ospite (🔗 ADR-034 punto 3 — DS §8.3).

         Qui nessuno è autenticato, quindi la preferenza a database non c'è e la
         sola copia raggiungibile è quella in `localStorage`, scritta l'ultima
         volta che questo browser ha cambiato tema. Il database resta la verità:
         questa è la **cache che evita il lampo**, e al login successivo il
         server la corregge se le due si sono separate.

         ⛔ **Inline e sincrono, e sopra `@vite`.** La posizione è la funzione:
         un `defer`, un file esterno o un `DOMContentLoaded` girerebbero dopo il
         primo layout, cioè **dopo** il lampo bianco che questo script esiste
         per evitare — è il difetto del campione stesso (`design-system.html`),
         il cui toggle vive in fondo al documento. Si copia l'architettura, non
         quel dettaglio. Spostarle sotto il foglio di stile non cambierebbe un
         solo carattere di markup: riporterebbe il **lampo**, che nessuna
         asserzione sul contenuto vede. Per questo `TemaUtenteTest` misura la
         **posizione** dello script rispetto a ciò che Vite emette.

         ⚠️ **Il `try/catch` non è cautela generica**: in navigazione privata, e
         con i cookie di terze parti bloccati, il solo `localStorage.getItem`
         **lancia**. Un'eccezione in uno script bloccante fermerebbe il parsing
         di tutto ciò che segue — cioè la pagina di accesso — per una
         preferenza estetica. Senza valore leggibile non si scrive niente e
         decide il sistema operativo, che è lo stesso esito del default.

         ⚠️ In `localStorage` c'è il valore di **dominio** (`sistema|chiaro|scuro`),
         non quello dell'attributo: la traduzione verso `light`/`dark` — le
         stringhe che `app.css` cerca nei selettori — è la stessa che
         `TemaUtente::attributoHtml()` fa sul server. `sistema`, e qualunque
         valore non riconosciuto, non scrivono l'attributo: l'assenza È la
         regola del sistema operativo, e un valore terzo inciamperebbe nel
         `:not([data-theme="light"])` spegnendo la media query. --}}
    <script>
        try {
            var tema = localStorage.getItem(@json(\App\Enums\TemaUtente::CHIAVE_LOCALSTORAGE));

            if (tema === 'chiaro' || tema === 'scuro') {
                document.documentElement.setAttribute('data-theme', tema === 'scuro' ? 'dark' : 'light');
            }
        } catch (e) {
            // Nessun ripiego: senza preferenza leggibile decide il sistema.
        }
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Il fondo e l'inchiostro vengono dai **semantici** (DS §8.2): `bg-canvas`
     e `text-ink` cambiano col tema senza una sola variante `dark:`, ed è ciò
     che rende utile lo script qui sopra — che l'attributo lo scrive, ma non
     avrebbe niente da colorare se questa riga fosse restata su `bg-neutral-50`.

     ⚠️ **`/bloccato` monta questo layout pur essendo per utenti autenticati**
     (ADR-034): là il tema lo decide `localStorage` e non la colonna. È
     dichiarato, non è un difetto da correggere qui. --}}
<body class="h-full bg-canvas font-sans text-ink antialiased">
    {{ $slot }}
</body>
</html>
