@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'showErrors' => true,
])

@php
    $fieldId = $attributes->get('id', $name);
    $descriptionIds = [];
    $displayValue = $value;

    // Invalid array input must still render a validation page, rather than fail while escaping.
    if (! is_scalar($displayValue) && ! $displayValue instanceof \Stringable) {
        $displayValue = '';
    }

    if ($hint) {
        $descriptionIds[] = $fieldId.'-hint';
    }

    $hasError = $showErrors && $errors->has($name);

    if ($hasError) {
        $descriptionIds[] = $fieldId.'-error';
    }
@endphp

<div class="field">
    <label for="{{ $fieldId }}">{{ $label }}@if ($required)<span class="required-marker" aria-hidden="true"> *</span>@endif</label>

    @if ($slot->isNotEmpty())
        {{ $slot }}
    @else
        <div @class(['password-wrap' => $type === 'password'])>
            <input
                {{ $attributes->except('id')->class(['form-control', 'is-invalid' => $hasError]) }}
                id="{{ $fieldId }}"
                name="{{ $name }}"
                type="{{ $type }}"
                @if ($type !== 'password') value="{{ $displayValue }}" @endif
                @required($required)
                @if ($hasError) aria-invalid="true" @endif
                @if ($descriptionIds) aria-describedby="{{ implode(' ', $descriptionIds) }}" @endif
            >
            @if ($type === 'password')
                <button class="password-toggle" type="button" data-password-toggle="{{ $fieldId }}" aria-controls="{{ $fieldId }}" aria-pressed="false" aria-label="הצגת {{ $label }}" hidden>הצג</button>
            @endif
        </div>
    @endif

    @if ($hint)
        <span id="{{ $fieldId }}-hint" class="field-hint">{{ $hint }}</span>
    @endif
    @if ($hasError)
        <span id="{{ $fieldId }}-error" class="field-error">{{ $errors->first($name) }}</span>
    @endif
</div>
