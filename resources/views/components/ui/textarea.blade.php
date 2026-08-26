@props([
    'label' => null,
    'name',
    'rows' => 3,
])

{{--
    Area di testo (Design System §5.6, §8.2 — campione `.el-field` / `textarea.el-in`).

    Stessi token e stesse tre scelte di `x-ui.input`, che è il file da leggere
    per le motivazioni: errore su `text-bad-soft-ink` e non `text-danger-600`
    (DS §8.2 nota 4), placeholder su `text-ink-3` (DS §2.2), `bg-surface`
    dichiarato invece che ereditato. Nessuna variante `dark:`: il tema si
    scambia sotto (DS §8.1).
--}}
<div>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-ink">{{ $label }}</label>
    @endif

    <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}"
        {{ $attributes->merge([
            'class' => 'mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink placeholder:text-ink-3 focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-ink-3',
        ]) }}>{{ $slot }}</textarea>

    @error($name)
        <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
    @enderror
</div>
