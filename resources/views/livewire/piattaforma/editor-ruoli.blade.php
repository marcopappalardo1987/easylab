{{--
    L'editor della matrice ruolo→permesso — per ora la sola intestazione.

    Il vuoto è deliberato: questo blocco esiste per rendere il gate
    **dimostrabile prima** che ci sia qualcosa da proteggere (vedi il docblock di
    `EditorRuoli`). La griglia delle 324 celle arriva sopra a un guscio già
    gatato, non viceversa.

    Nessuna classe Tailwind nuova rispetto a `cabina` e `registro-audit`: il
    guscio è lo stesso, e vale la disciplina di non far nascere una variante di
    layout per una pagina che ancora non ha corpo.
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Ruoli e permessi</h1>
        <p class="mt-1 text-sm text-neutral-600">
            Chi può fare cosa, per tutti i clienti insieme.
        </p>
    </div>
</div>
