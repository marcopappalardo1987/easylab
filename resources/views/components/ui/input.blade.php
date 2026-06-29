@props([
    'label' => null,
    'name',
    'type' => 'text',
])

<div>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-neutral-800">{{ $label }}</label>
    @endif

    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
        {{ $attributes->merge([
            'class' => 'mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 placeholder:text-neutral-400 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none',
        ]) }}>

    @error($name)
        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
    @enderror
</div>
