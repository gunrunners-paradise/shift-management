<x-panel id="receipts" class="receipt-report" title="מעקב קבלות חודשי" heading-id="receipt-report-heading" hint="צפייה במצב הקבלות של כל חשבונות העובדים בחודש שנבחר. חשבון שלא סימן קבלות מופיע כ״אין קבלות״.">
    <form class="receipt-month-filter" method="GET" action="{{ route('admin.users.index') }}#receipts">
        {{-- Changing the report month keeps the other dashboard lists on their current pages. --}}
        @foreach (request()->only(['page', 'blocks_page', 'events_page']) as $queryName => $queryValue)
            @if (is_scalar($queryValue))
                <input type="hidden" name="{{ $queryName }}" value="{{ $queryValue }}">
            @endif
        @endforeach
        <x-field name="receipts_month" label="חודש לבדיקה" type="month" :value="$receiptReport['month']" required />
        <x-button variant="secondary">הצגת החודש</x-button>
    </form>

    <h3 class="receipt-report-month">{{ $receiptReport['month_label'] }}</h3>
    <dl class="receipt-report-summary">
        <div><dt>יש קבלות</dt><dd data-receipt-yes-count>{{ $receiptReport['yes_count'] }}</dd></div>
        <div><dt>אין קבלות</dt><dd data-receipt-no-count>{{ $receiptReport['no_count'] }}</dd></div>
        <div><dt>סה״כ עובדים</dt><dd data-receipt-total-count>{{ $receiptReport['total_count'] }}</dd></div>
    </dl>

    <div class="receipt-report-scroll" role="region" aria-label="מצב קבלות לעובדים בחודש {{ $receiptReport['month_label'] }}" tabindex="0">
        <table class="receipt-report-table">
            <caption class="screen-reader-only">מצב קבלות בחודש {{ $receiptReport['month_label'] }}</caption>
            <thead>
                <tr><th scope="col">שם העובד/ת</th><th scope="col">סטטוס קבלות</th></tr>
            </thead>
            <tbody>
                @forelse ($receiptReport['workers'] as $receiptWorker)
                    <tr data-receipt-state="{{ $receiptWorker['has_receipts'] ? 'yes' : 'no' }}">
                        <th scope="row">
                            <span>{{ $receiptWorker['name'] }}</span>
                            @if (! $receiptWorker['is_active'])
                                <span class="receipt-inactive-badge">חשבון לא פעיל</span>
                            @endif
                        </th>
                        <td @class(['receipt-status', 'receipt-status--yes' => $receiptWorker['has_receipts']])>{{ $receiptWorker['has_receipts'] ? 'יש קבלות' : 'אין קבלות' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="receipt-report-empty">אין חשבונות עובדים להצגה.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="receipt-report-note">הנתונים נשמרים בנפרד לכל חודש. עובדים יכולים לשנות את הסימון של החודש הנוכחי בלבד.</p>
</x-panel>
