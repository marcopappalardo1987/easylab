<x-guest-layout title="Accesso sospeso — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">
            <div class="text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-warning-500/15">
                    <svg class="h-6 w-6 text-warning-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                </span>
                <h1 class="mt-4 text-2xl font-bold tracking-tight text-neutral-900">Accesso sospeso</h1>
                <p class="mt-1 text-sm text-neutral-600">
                    L'accesso a <strong>{{ $nomeEnte }}</strong> è temporaneamente sospeso.
                </p>
            </div>

            <div class="mt-8 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm md:p-8">
                {{-- Il messaggio è GENERICO di proposito: `locked_reason` è
                     un'annotazione operativa interna scritta dallo staff (può
                     citare solleciti e riferimenti amministrativi) e il suo
                     destinatario è la dashboard Superadmin di S6, non questa
                     pagina. Il gesto commerciale passa dal contatto. --}}
                <p class="text-sm text-neutral-600">
                    I dati non sono stati toccati e torneranno accessibili alla
                    regolarizzazione della posizione. Contatta l'amministrazione
                    del tuo Ente o EasyLab per maggiori informazioni.
                </p>

                @if ($sedi->isNotEmpty())
                    <hr class="my-6 border-neutral-200">
                    <p class="text-sm font-medium text-neutral-900">Le tue altre sedi</p>
                    <div class="mt-3 flex flex-col gap-2">
                        @foreach ($sedi as $sede)
                            <form method="POST" action="{{ route('bloccato.passa', $sede->id) }}">
                                @csrf
                                <button type="submit"
                                        class="flex w-full items-center justify-between rounded-md border border-neutral-200 px-4 py-2.5 text-left text-sm font-medium text-neutral-700 transition hover:bg-neutral-50">
                                    <span class="truncate">{{ $sede->nome }}</span>
                                    <svg class="h-4 w-4 shrink-0 text-neutral-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif

                <hr class="my-6 border-neutral-200">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md border border-neutral-200 px-4 py-2.5 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50">
                        Esci
                    </button>
                </form>
            </div>
        </div>
    </main>
</x-guest-layout>
