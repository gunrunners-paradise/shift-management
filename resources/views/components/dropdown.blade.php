@props(['id', 'label', 'align' => 'start', 'triggerClass' => ''])

{{-- Native details keeps navigation and logout available when JavaScript is disabled. --}}
<details class="dropdown-container" data-dropdown name="header-dropdowns">
    <summary class="header-action {{ $triggerClass }}" aria-label="{{ $label }}" aria-controls="{{ $id }}">
        {{ $trigger }}
    </summary>
    <div id="{{ $id }}" class="dropdown dropdown--{{ $align }}">
        {{ $slot }}
    </div>
</details>
