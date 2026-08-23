{{--
    L'error tracker interno — il guscio.

    ⚠️ **Qui sotto non c'è niente, e non è un lavoro lasciato a metà**: la
    cattura arriva nel blocco successivo, la lista in quello dopo. Una pagina
    vuota ma già gatata rende il gate dimostrabile prima che ci sia qualcosa da
    proteggere; l'ordine opposto mette la guardia addosso a una vista già
    scritta e la prova diventa «non sembra rotto».

    ⚠️ Il sottotitolo non è decorazione: è la **stringa del corpo** su cui il
    positivo di `AccessoErroriTest` distingue questa pagina dalla cabina.
    Asserire su «Errori» non basterebbe — la sub-nav stampa quella parola su
    tutte e quattro le pagine, quindi un test verde direbbe soltanto che si è
    atterrati da qualche parte dentro la piattaforma.
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Errori</h1>
        <p class="mt-1 text-sm text-neutral-600">
            Cosa si è rotto, quante volte, e con quale contesto.
        </p>
    </div>

</div>
