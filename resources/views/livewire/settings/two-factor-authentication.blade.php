<div class="mx-auto w-full max-w-2xl px-4 py-10 sm:px-6">

    {{-- Header --}}
    <h1 class="text-2xl font-bold tracking-tight text-ink">Sicurezza</h1>

    <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">

        {{-- Titolo + stato --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-ink">Verifica in due passaggi (2FA)</h2>
                <p class="mt-1 text-sm text-ink-2">Aggiunge un codice temporaneo dall'app di autenticazione al login.</p>
            </div>
            @if ($this->confirmed)
                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-ok-soft px-2 py-0.5 text-xs font-medium text-ok-soft-ink">● Attivo</span>
            @else
                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-xs font-medium text-ink-2">○ Non attivo</span>
            @endif
        </div>

        <hr class="my-6 border-border">

        {{-- Stato: DISABILITATO --}}
        @if (! $this->enabled)
            <button type="button" wire:click="enable"
                    class="inline-flex items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                Abilita 2FA
            </button>

        {{-- Stato: ABILITATO MA NON CONFERMATO → QR + conferma --}}
        @elseif ($qrCode)
            <p class="text-sm text-ink">1. Scansiona il QR con l'app di autenticazione (Google Authenticator, 1Password, …).</p>
            <div class="mt-3 inline-block rounded-lg border border-border bg-surface p-3">{!! $qrCode !!}</div>
            {{-- ⚠️ La chiave manuale è una superficie MONOSPAZIO (DS §8.2), non
                 un incasso: `bg-surface-code`, distinto da `bg-surface-sunken`. --}}
            <p class="mt-2 text-xs text-ink-2">Oppure inserisci la chiave manualmente:
                <code class="rounded bg-surface-code px-1 py-0.5 font-mono text-ink">{{ $setupKey }}</code>
            </p>

            <div class="mt-5 max-w-xs">
                <label for="code" class="block text-sm font-medium text-ink">2. Inserisci il codice generato</label>
                {{-- ⚠️ `<input>` nudo: `bg-surface` va dichiarato esplicitamente
                     (preflight, DS §8.5), e il contorno segue quello dei campi
                     (`border-border-strong`, non il semplice `border-border`). --}}
                <input id="code" type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456"
                       wire:keydown.enter="confirm"
                       class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-center text-lg tracking-widest tabular-nums text-ink placeholder:text-ink-3 focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                @error('code') <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 flex gap-3">
                <button type="button" wire:click="confirm"
                        class="inline-flex items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                    Conferma e attiva
                </button>
                <button type="button" wire:click="disable"
                        class="inline-flex items-center justify-center rounded-md border border-border-strong bg-surface px-4 py-2.5 font-medium text-ink transition hover:bg-surface-sunken">
                    Annulla
                </button>
            </div>

        {{-- Stato: ATTIVO --}}
        @else
            <p class="text-sm text-ink-2">Il 2FA è attivo sul tuo account.</p>

            {{-- ⚠️ Bordo transparent: come `a-warn` nel campione, il colore lo fa
                 solo il fondo. --}}
            @if (! empty($recoveryCodes))
                <div class="mt-4 rounded-md border border-transparent bg-warn-soft p-4">
                    <p class="text-sm font-medium text-warn-soft-ink">Conserva questi codici di recupero in un posto sicuro. Permettono l'accesso se perdi il dispositivo.</p>
                    {{-- ⚠️ I codici sono monospazio: `bg-surface-code`, lo stesso
                         token della chiave manuale sopra e non `bg-surface`. --}}
                    <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-ink">
                        @foreach ($recoveryCodes as $recoveryCode)
                            <span class="rounded bg-surface-code px-2 py-1">{{ $recoveryCode }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mt-5 flex flex-wrap gap-3">
                <button type="button" wire:click="regenerateRecoveryCodes"
                        class="inline-flex items-center justify-center rounded-md border border-border-strong bg-surface px-4 py-2.5 font-medium text-ink transition hover:bg-surface-sunken">
                    Rigenera codici di recupero
                </button>
                <button type="button" wire:click="disable"
                        class="inline-flex items-center justify-center rounded-md bg-bad-dot px-4 py-2.5 font-medium text-ink-inverse transition hover:brightness-90 focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                    Disabilita 2FA
                </button>
            </div>
        @endif
    </div>
</div>
