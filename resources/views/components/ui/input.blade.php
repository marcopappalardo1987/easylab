@props([
    'label' => null,
    'name',
    'type' => 'text',
])

{{--
    Campo di testo (Design System §5.6, §8.2 — campione `.el-field` / `.el-in`).

    Solo token **semantici**: il tema si scambia sotto, e per questo qui non
    compare nessuna variante `dark:` (DS §8.1).

    Tre scelte che non si leggono dal codice:

    - 🔴 **Il testo d'errore è `text-bad-soft-ink`, non `text-danger-600`.**
      §5.6 scrive `danger-600`, ma su `--surface` scuro (`#111C2E`) fa 2,64:1:
      illeggibile proprio nel tema in cui un errore conta di più.
      `--bad-soft-ink` fa 8,31:1 in chiaro e 9,00:1 in scuro, ed è ciò che il
      campione monta (`.el-field .err{color:var(--bad-soft-ink)}`). Misure e
      decisione in DS §8.2, nota 4.
    - **Il placeholder è `text-ink-3`**, mai più chiaro: a `neutral-400` faceva
      2,56:1 su bianco, sotto AA (DS §2.2).
    - **`bg-surface` è esplicito** e non ereditato: la preflight di Tailwind
      rende trasparenti i controlli di form, quindi un campo posato su
      `bg-canvas` prenderebbe il fondo della pagina invece della propria
      superficie. Il campione lo dichiara: `.el-in{background:var(--surface)}`.
--}}
<div>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-ink">{{ $label }}</label>
    @endif

    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
        {{ $attributes->merge([
            'class' => 'mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink placeholder:text-ink-3 focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-ink-3',
        ]) }}>

    @error($name)
        <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
    @enderror
</div>
