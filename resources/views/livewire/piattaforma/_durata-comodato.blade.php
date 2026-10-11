{{--
    La scelta della durata di un comodato d'uso (🔗 ADR-058): 12, 24 o 36 mesi,
    una data precisa, o nessuna scadenza. La usano la nascita del cliente e il
    pannello di un cliente già attivo, con le stesse voci (`DurataComodato`).

    Parametri: `proprieta` e `proprietaData` (le due property Livewire), `scelta`
    (il valore corrente della prima), `id` (prefisso degli id dei campi).

    ⚠️ `wire:model.live` sulla tendina: il campo della data compare solo per
    «una data precisa», e a deciderlo è il server.
--}}
@php use App\Support\Billing\DurataComodato; @endphp
{{-- Una radice sola: `RadiceLivewireGuardrailTest` lo pretende da ogni vista
     di questa cartella, parziali compresi. --}}
<div>
<div class="mt-3">
    <label for="{{ $id }}-durata" class="block text-sm font-medium text-ink">Durata del comodato</label>
    <select id="{{ $id }}-durata" wire:model.live="{{ $proprieta }}"
            class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
        @foreach (DurataComodato::opzioni() as $valore => $etichetta)
            <option value="{{ $valore }}">{{ $etichetta }}</option>
        @endforeach
    </select>
    @error($proprieta)
        <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
    @enderror
</div>

@if ($scelta === DurataComodato::DATA_PRECISA)
    <div class="mt-3" wire:key="{{ $id }}-campo-data">
        <label for="{{ $id }}-data" class="block text-sm font-medium text-ink">Scade il</label>
        <input id="{{ $id }}-data" type="date" wire:model="{{ $proprietaData }}" min="{{ today()->toDateString() }}"
               class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
        @error($proprietaData)
            <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
        @enderror
    </div>
@elseif (in_array((int) $scelta, DurataComodato::MESI, true))
    <p class="mt-2 text-sm text-ink-2" data-anteprima-scadenza>
        Scadrà il <strong class="text-ink">{{ DurataComodato::scadenza((string) $scelta)->format('d/m/Y') }}</strong>.
    </p>
@endif
</div>
