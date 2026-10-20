@props(['title' => null, 'headingId' => null, 'hint' => null])

<section {{ $attributes->class(['panel']) }} @if ($headingId) aria-labelledby="{{ $headingId }}" @endif>
    @if ($title)
        <h2 @if ($headingId) id="{{ $headingId }}" @endif>{{ $title }}</h2>
    @endif
    @if ($hint)
        <p class="hint">{{ $hint }}</p>
    @endif
    {{ $slot }}
</section>
