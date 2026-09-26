<div class="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <nav class="flex flex-wrap items-center gap-1 text-sm text-ink-3">
                <a href="{{ route('anagrafica.index') }}" wire:navigate
                    class="rounded px-1 py-0.5 hover:bg-surface-sunken hover:text-ink">Anagrafica</a>
                <span class="text-ink-3">›</span>
                <span class="font-medium text-ink">{{ $ente->nome }}</span>
            </nav>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink">Marchio email</h1>
            <p class="mt-2 text-sm text-ink-2">
                Logo e colore con cui escono le email di questo Ente: il riepilogo delle scadenze,
                gli avvisi e l'invito a un nuovo utente.
            </p>
        </div>
    </div>

    @if ($notice)
        <div class="mt-4 rounded-md border border-transparent bg-ok-soft px-3 py-2 text-sm text-ok-soft-ink">
            {{ $notice }}
        </div>
    @endif

    <x-ui.card class="mt-6">
        <form wire:submit="salva" class="space-y-6">

            <div>
                <label for="colore" class="block text-sm font-medium text-ink">Colore del marchio</label>
                <div class="mt-1 flex items-center gap-3">
                    {{-- ⚠️ `<input type="color">` nudo: la preflight rende trasparenti i
                         controlli di form, quindi `bg-surface` va dichiarato (DS §8.5). --}}
                    <input id="colore" type="color" wire:model.live="colore"
                        class="h-11 w-14 shrink-0 cursor-pointer rounded-md border border-border-strong bg-surface p-1">
                    <input type="text" wire:model.live="colore" placeholder="#06589c" maxlength="7"
                        aria-label="Colore del marchio in esadecimale"
                        class="block w-40 rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink placeholder:text-ink-3 focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                </div>
                @error('colore')
                    <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
                @enderror
                <p class="mt-1 text-xs text-ink-3">
                    Lascia vuoto per usare il blu di Easy Lab. Il colore tinge un filetto in testata e
                    il pulsante; il testo sopra il pulsante viene scelto da solo, chiaro o scuro, per
                    restare leggibile.
                </p>
            </div>

            <div>
                <label for="logo" class="block text-sm font-medium text-ink">Logo</label>
                <input id="logo" type="file" wire:model="logo" accept="image/png,image/jpeg"
                    class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-sm text-ink file:mr-3 file:rounded file:border-0 file:bg-surface-sunken file:px-3 file:py-1.5 file:text-sm file:text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                @error('logo')
                    <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
                @enderror
                <p class="mt-1 text-xs text-ink-3">
                    PNG o JPG, fino a 512 KB e 1200×400 pixel.
                    {{-- ⛔ Niente SVG, e la ragione va detta a chi carica: non è un capriccio. --}}
                    Gli SVG non sono ammessi perché Gmail e Outlook non li mostrano.
                </p>
                @if ($ente->marchio_logo_path)
                    <div class="mt-3 flex items-center gap-3">
                        <span class="text-xs text-ink-3">Un logo è già impostato.</span>
                        {{-- ⚠️ Toglie il logo E salva il colore in sospeso: il campo è
                             `wire:model.live`, quindi qui può esserci una scelta che
                             `salva()` non ha ancora visto. --}}
                        <x-ui.button variant="ghost" type="button" wire:click="rimuoviLogo">Rimuovi il logo</x-ui.button>
                    </div>
                @endif
            </div>

            <div class="flex justify-end">
                <x-ui.button type="submit">Salva</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{--
        L'anteprima della TESTATA dell'email.

        ⚠️ **Gli unici `style` inline ammessi in questa vista sono qui**, e sono
        quelli dell'email: il colore va giudicato dove verrà visto, e un'email non
        ha il nostro tema — è sempre chiara (ADR-034). Ricostruirla con i token
        semantici della pagina mostrerebbe un'anteprima che in tema scuro non
        somiglia a niente di ciò che il destinatario riceverà.
    --}}
    <section class="mt-8">
        <h2 class="text-xs font-semibold tracking-wide text-ink-3 uppercase">Anteprima della testata</h2>
        <div class="mt-3 overflow-x-auto rounded-lg border border-border p-4" style="background-color: #f6f8fb;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td align="center" style="border-top: 4px solid {{ $coloreScelto }}; background-color: #ffffff; padding: 24px 16px;">
                        {{-- 🔴 **Il logo VERO, non un segnaposto.** L'anteprima esiste
                             perché il colore si giudichi dove verrà visto, e un logo su
                             fondo bianco sopra un filetto scuro stona in un modo che
                             nessuna scritta «[logo caricato]» può mostrare. È un `data:`
                             URI perché il disco `documenti` è privato: vedi
                             `MarchioEnte::anteprimaLogo()`. --}}
                        @if ($logoAnteprima)
                            <img src="{{ $logoAnteprima }}" alt="{{ $ente->nome }}" width="180"
                                style="display: block; margin: 0 auto; max-width: 180px; height: auto;">
                        @endif
                        <span style="display: block; margin-top: 8px; color: #0f172a; font-size: 18px; font-weight: bold;">{{ $ente->nome }}</span>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="background-color: #ffffff; padding: 0 16px 24px;">
                        <span style="display: inline-block; border-radius: 6px; padding: 10px 20px; font-weight: bold; background-color: {{ $coloreScelto }}; color: {{ $inchiostro }};">Apri Easy Lab</span>
                    </td>
                </tr>
            </table>
        </div>
        <p class="mt-2 text-xs text-ink-3">
            Il piè di pagina resta sempre di Easy Lab: è il mittente reale delle email, e non cambia
            per Ente.
        </p>
    </section>
</div>
