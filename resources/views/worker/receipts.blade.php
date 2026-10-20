@php
    // A failed form submitted before midnight must not carry last month's choice into a new month.
    $receiptIsChecked = $hasReceipts;

    if (old('month') === $receiptMonth) {
        $receiptIsChecked = in_array(old('has_receipts'), ['1', 1, true], true);
    }
@endphp

<x-panel id="worker-receipts" class="worker-receipts" title="קבלות — החודש הנוכחי" heading-id="worker-receipts-heading">
    <div class="worker-receipts-layout">
        <div>
            <p class="worker-receipts-month"><time datetime="{{ $receiptMonth }}">{{ $receiptMonthLabel }}</time></p>
            <p class="hint" id="worker-receipts-hint">סמנו אם יש לכם קבלות להעביר למנהל/ת החודש, ושמרו את הבחירה. אפשר לערוך רק את החודש הנוכחי, גם כאשר מוצג שבוע אחר בלוח.</p>
            <p class="worker-receipts-saved-state">מצב שמור: <strong>{{ $hasReceipts ? 'יש קבלות' : 'אין קבלות' }}</strong></p>
        </div>

        <form method="POST" action="{{ route('worker.receipts.update') }}" class="worker-receipts-form">
            @csrf
            @method('PUT')
            <input type="hidden" name="month" value="{{ $receiptMonth }}">
            <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
            <input type="hidden" name="has_receipts" value="0">
            <label class="worker-receipts-choice" for="has-receipts">
                <input id="has-receipts" type="checkbox" name="has_receipts" value="1" @checked($receiptIsChecked) aria-describedby="worker-receipts-hint{{ $errors->has('has_receipts') ? ' has-receipts-error' : '' }}" @if ($errors->has('has_receipts')) aria-invalid="true" @endif>
                <span>יש לי קבלות החודש</span>
            </label>
            @error('has_receipts')
                <p id="has-receipts-error" class="field-error">{{ $message }}</p>
            @enderror
            <x-button>שמירת מצב הקבלות</x-button>
        </form>
    </div>
</x-panel>
