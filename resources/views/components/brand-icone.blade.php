{{--
    L'icona di Easy Lab nella scheda del browser: la «yL» del logo (🔗 ADR-056).

    Tre file, e nessuno è di troppo:
    - **`favicon.svg`** per i browser che la sanno leggere: vettoriale, e su
      tema scuro alza da sé il blu profondo, come il logo (ADR-033/034);
    - **`favicon.ico`** per chi non legge l'SVG (Safari) e per chi la chiede
      alla radice senza guardare la pagina — un PDF, una pagina d'errore;
    - **`apple-touch-icon.png`** per la schermata Home di iOS, su fondo bianco:
      lì la trasparenza diventa nero.

    ⚠️ I tre percorsi si scrivono **per esteso**: `MarchioTest` li estrae dal
    sorgente per verificare che i file esistano davvero, perché un `<link>` a un
    file assente non rompe niente — pagina 200, e la scheda senza icona.

    Il `?v=` cambia quando cambia il disegno: le icone sono fra le cose che un
    browser tiene in memoria più a lungo.
--}}
<link rel="icon" href="{{ asset('favicon.ico') }}?v=1" sizes="48x48">
<link rel="icon" href="{{ asset('favicon.svg') }}?v=1" type="image/svg+xml" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=1">
