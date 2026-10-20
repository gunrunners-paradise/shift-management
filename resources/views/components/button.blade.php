@props(['type' => 'submit', 'variant' => 'primary'])

<button type="{{ $type }}" {{ $attributes->class(['button', 'button--'.$variant]) }}>{{ $slot }}</button>
