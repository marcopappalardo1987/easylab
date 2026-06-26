<div class="mx-auto w-full max-w-2xl px-4 py-10 sm:px-6">

    {{-- Header --}}
    <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Sicurezza</h1>

    <div class="mt-8 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm md:p-8">

        {{-- Titolo + stato --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-neutral-900">Verifica in due passaggi (2FA)</h2>
                <p class="mt-1 text-sm text-neutral-600">Aggiunge un codice temporaneo dall'app di autenticazione al login.</p>
            </div>
            @if ($this->confirmed)
                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success-100 px-2 py-0.5 text-xs font-medium text-success-600">● Attivo</span>
            @else
                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600">○ Non attivo</span>
            @endif
        </div>

        <hr class="my-6 border-neutral-200">

        {{-- Stato: DISABILITATO --}}
        @if (! $this->enabled)
            <button type="button" wire:click="enable"
                    class="inline-flex items-center justify-center rounded-md bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none">
                Abilita 2FA
            </button>

        {{-- Stato: ABILITATO MA NON CONFERMATO → QR + conferma --}}
        @elseif ($qrCode)
            <p class="text-sm text-neutral-800">1. Scansiona il QR con l'app di autenticazione (Google Authenticator, 1Password, …).</p>
            <div class="mt-3 inline-block rounded-lg border border-neutral-200 bg-white p-3">{!! $qrCode !!}</div>
            <p class="mt-2 text-xs text-neutral-600">Oppure inserisci la chiave manualmente:
                <code class="rounded bg-neutral-100 px-1 py-0.5 font-mono text-neutral-800">{{ $setupKey }}</code>
            </p>

            <div class="mt-5 max-w-xs">
                <label for="code" class="block text-sm font-medium text-neutral-800">2. Inserisci il codice generato</label>
                <input id="code" type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456"
                       wire:keydown.enter="confirm"
                       class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-center text-lg tracking-widest tabular-nums text-neutral-900 placeholder:text-neutral-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                @error('code') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 flex gap-3">
                <button type="button" wire:click="confirm"
                        class="inline-flex items-center justify-center rounded-md bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none">
                    Conferma e attiva
                </button>
                <button type="button" wire:click="disable"
                        class="inline-flex items-center justify-center rounded-md border border-neutral-200 bg-white px-4 py-2.5 font-medium text-neutral-800 transition hover:bg-neutral-50">
                    Annulla
                </button>
            </div>

        {{-- Stato: ATTIVO --}}
        @else
            <p class="text-sm text-neutral-600">Il 2FA è attivo sul tuo account.</p>

            @if (! empty($recoveryCodes))
                <div class="mt-4 rounded-md border border-warning-500/30 bg-warning-100 p-4">
                    <p class="text-sm font-medium text-warning-800">Conserva questi codici di recupero in un posto sicuro. Permettono l'accesso se perdi il dispositivo.</p>
                    <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-neutral-800">
                        @foreach ($recoveryCodes as $recoveryCode)
                            <span class="rounded bg-white px-2 py-1">{{ $recoveryCode }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mt-5 flex flex-wrap gap-3">
                <button type="button" wire:click="regenerateRecoveryCodes"
                        class="inline-flex items-center justify-center rounded-md border border-neutral-200 bg-white px-4 py-2.5 font-medium text-neutral-800 transition hover:bg-neutral-50">
                    Rigenera codici di recupero
                </button>
                <button type="button" wire:click="disable"
                        class="inline-flex items-center justify-center rounded-md bg-danger-500 px-4 py-2.5 font-medium text-white transition hover:bg-danger-600 focus:ring-2 focus:ring-danger-500 focus:ring-offset-2 focus:outline-none">
                    Disabilita 2FA
                </button>
            </div>
        @endif
    </div>
</div>
