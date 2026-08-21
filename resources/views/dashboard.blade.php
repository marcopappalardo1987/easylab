<x-layouts.app title="Dashboard — Easy Lab">
    <div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">

        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Dashboard</h1>
            <p class="mt-1 text-sm text-neutral-600">Benvenuto in Easy Lab, {{ auth()->user()->name }}.</p>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {{-- Sicurezza / 2FA --}}
            <a href="{{ route('settings.security') }}"
               class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm transition hover:border-primary-600">
                <h2 class="font-semibold text-neutral-900">🛡 Sicurezza</h2>
                <p class="mt-1 text-sm text-neutral-600">Gestisci la verifica in due passaggi (2FA).</p>
                <p class="mt-3 text-sm font-medium text-primary-600">Vai alle impostazioni &rarr;</p>
            </a>

            {{-- 🔴 Cabina di regia (S6), gatata blocco per blocco e non una volta
                 sola in testa alla pagina: chi non ha il permesso non deve
                 nemmeno sapere che la pagina esiste. --}}
            @can('tenants.view_all')
                <a href="{{ route('piattaforma.index') }}"
                   class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm transition hover:border-primary-600">
                    <h2 class="font-semibold text-neutral-900">🏢 Piattaforma</h2>
                    <p class="mt-1 text-sm text-neutral-600">I clienti di EasyLab, le loro sedi e lo stato dei contratti.</p>
                    <p class="mt-3 text-sm font-medium text-primary-600">Vai alla cabina di regia &rarr;</p>
                </a>
            @endcan

            {{-- La card «Prossimamente — anagrafica, strumenti e semaforo
                 arriveranno dagli sprint successivi» è stata tolta il 21 Ago
                 2026: erano arrivati da tre sprint, e la dashboard continuava a
                 dire il contrario a ogni accesso. Le dashboard per ruolo sono
                 un punto successivo di S6; qui è stata rimossa una bugia, non
                 costruita una vista. --}}
        </div>
    </div>
</x-layouts.app>
