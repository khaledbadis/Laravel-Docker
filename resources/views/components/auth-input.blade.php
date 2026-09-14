@props(['name', 'label', 'type' => 'text', 'autocomplete' => null, 'hint' => null])
<div>
    <label for="{{ $name }}" class="mb-2 block text-sm font-medium">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" value="{{ $type === 'password' ? '' : old($name) }}" required
        @if($errors->has($name)) aria-invalid="true" @endif
        aria-describedby="{{ $name }}-feedback"
        class="w-full rounded-xl border border-stone-300 bg-white px-4 py-3 focus:border-emerald-700 focus:outline-2 focus:outline-emerald-700" {{ $attributes }}>
    <div id="{{ $name }}-feedback" class="mt-2 text-sm">
        @error($name)<p role="alert" class="text-red-700">{{ $message }}</p>@else @if($hint)<p class="text-stone-500">{{ $hint }}</p>@endif @enderror
    </div>
</div>
