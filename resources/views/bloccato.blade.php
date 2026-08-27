<x-guest-layout title="Accesso sospeso — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">
            <div class="text-center">
                {{-- L'icona era su `warning` (arancione): la pagina intera è
                     ADR-013 (lockout), e il DS riserva a quel concetto una
                     famiglia propria — `lock-dot`/`lock-soft`/`lock-soft-ink` —
                     che prima d'ora nessuna vista montava ancora. Qui è il posto
                     giusto: non un allarme da agire (arancione), ma lo stato
                     stabile «l'accesso è chiuso». --}}
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-lock-soft">
                    <svg class="h-6 w-6 text-lock-soft-ink" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                </span>
                <h1 class="mt-4 text-2xl font-bold tracking-tight text-ink">Accesso sospeso</h1>
                <p class="mt-1 text-sm text-ink-2">
                    L'accesso a <strong>{{ $nomeEnte }}</strong> è temporaneamente sospeso.
                </p>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
                {{-- Il messaggio è GENERICO di proposito: `locked_reason` è
                     un'annotazione operativa interna scritta dallo staff (può
                     citare solleciti e riferimenti amministrativi) e il suo
                     destinatario è la dashboard Superadmin di S6, non questa
                     pagina. Il gesto commerciale passa dal contatto.

                     ⚠️ Questo paragrafo è il MOTIVO (in senso lato: cosa è
                     successo e come si esce) di una pagina che chi la legge non
                     può fare altro che leggere — misurato: `ink-2` su `surface`
                     resta ≥ 7:1 in entrambi i temi (v. rapporto finale). --}}
                <p class="text-sm text-ink-2">
                    I dati non sono stati toccati e torneranno accessibili alla
                    regolarizzazione della posizione. Contatta l'amministrazione
                    del tuo Ente o EasyLab per maggiori informazioni.
                </p>

                @if ($sedi->isNotEmpty())
                    <hr class="my-6 border-border">
                    <p class="text-sm font-medium text-ink">Le tue altre sedi</p>
                    <div class="mt-3 flex flex-col gap-2">
                        @foreach ($sedi as $sede)
                            <form method="POST" action="{{ route('bloccato.passa', $sede->id) }}">
                                @csrf
                                <button type="submit"
                                        class="flex w-full items-center justify-between rounded-md border border-border-strong px-4 py-2.5 text-left text-sm font-medium text-ink transition hover:bg-surface-sunken">
                                    <span class="truncate">{{ $sede->nome }}</span>
                                    <svg class="h-4 w-4 shrink-0 text-ink-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif

                {{-- La VIA D'USCITA: le sedi sane sopra, e qui il logout. Stesso
                     bordo forte e stesso hover incassato dei bottoni "sede", per
                     restare il più leggibile possibile in entrambi i temi. --}}
                <hr class="my-6 border-border">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md border border-border-strong px-4 py-2.5 text-sm font-medium text-ink transition hover:bg-surface-sunken">
                        Esci
                    </button>
                </form>
            </div>
        </div>
    </main>
</x-guest-layout>
