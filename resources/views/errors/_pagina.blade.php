{{--
    Le pagine d'errore dell'applicazione, in italiano e con la sua grafica
    (7 Nov 2026: erano quelle di Laravel, in inglese e senza stile, e le
    vedevano i clienti — un QR rigenerato, una macchina fuori perimetro).

    ⛔ Mai il messaggio dell'eccezione: `abort(403, '…')` e le firme non valide
    portano testi tecnici, e una pagina d'errore non deve dire più di quanto
    serve a chi la legge per andare avanti.

    Parametri: `codice`, `titolo`, `testo`.
--}}
<x-guest-layout :title="$titolo.' — Easy Lab'">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm text-center" data-errore="{{ $codice }}">
            <p class="text-sm font-semibold text-brand">{{ $codice }}</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-ink">{{ $titolo }}</h1>
            <p class="mt-3 text-sm text-ink-2">{{ $testo }}</p>
            <a href="{{ url('/dashboard') }}"
               class="mt-8 inline-flex items-center justify-center rounded-md bg-brand px-4 py-2.5 text-sm font-medium text-brand-ink hover:bg-brand-hover">
                Torna alla Dashboard
            </a>
        </div>
    </main>
</x-guest-layout>
