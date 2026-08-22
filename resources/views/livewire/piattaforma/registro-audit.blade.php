<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Registro di audit</h1>
        <p class="mt-1 text-sm text-neutral-600">
            Chi ha fatto cosa, e quando, su tutta la piattaforma.
        </p>
    </div>

    {{-- ⚠️ Non è una nota di colore: è il limite di affidabilità del dato, e
         chi legge un registro deve saperlo prima di trarne conclusioni. Fino al
         22 Ago 2026 le righe scritte durante un'impersonazione nominano
         l'impersonato e non chi stava agendo davvero. Lo storico non si
         riscrive. --}}
    <x-ui.card class="mt-4 border-warning-500 bg-warning-100">
        <p class="text-sm text-warning-800">
            <span aria-hidden="true">⚠️</span>
            Le azioni compiute <strong>durante un'impersonazione</strong> portano il nome di chi è stato
            impersonato. Dal <strong>{{ App\Support\AuditLog::ATTRIBUZIONE_AFFIDABILE_DA }}</strong> le righe
            scritte <strong>dentro una richiesta</strong> dicono anche chi c'era dietro; per quelle precedenti
            quell'informazione non esiste.
        </p>
        <p class="mt-2 text-xs text-warning-800">
            Restano senza attribuzione le scritture <strong>differite</strong> — code, comandi di console,
            webhook — perché lì non c'è una sessione da interrogare.
        </p>
    </x-ui.card>

    <x-ui.card class="mt-6">
        <p class="text-sm text-neutral-600">La tabella arriva col prossimo blocco.</p>
    </x-ui.card>

</div>
