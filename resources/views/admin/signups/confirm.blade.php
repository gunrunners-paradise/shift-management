@extends('layouts.app')

@php
    $isApproval = $review['action'] === 'approve';
    $signupManagementErrors = $errors->getBag('signupManagement');
@endphp

@section('title', $isApproval ? 'אישור בקשות הצטרפות' : 'מחיקת בקשות הצטרפות')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/signup.css') }}">
@endpush

@section('content')
    <x-panel class="signup-confirm-panel" :title="$isApproval ? 'אישור בקשות הצטרפות' : 'מחיקת בקשות הצטרפות'" heading-id="signup-confirm-heading">
        @if ($signupManagementErrors->any())
            <div class="message message--error" role="alert" tabindex="-1" data-error-summary>
                <ul>
                    @foreach ($signupManagementErrors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($review['scope'] === 'single')
            <p class="signup-confirm-identity">בקשה של <strong>{{ $review['name'] }}</strong></p>
        @endif

        <p class="signup-confirm-intro">{{ $isApproval ? 'הפעולה תאשר' : 'הפעולה תמחק' }} {{ $review['total'] }} בקשות הצטרפות:</p>
        <dl class="signup-confirm-counts">
            <div><dt>בקשות לחשבון עובד/ת</dt><dd>{{ $review['worker_count'] }}</dd></div>
            <div><dt>בקשות לחשבון מנהל/ת</dt><dd>{{ $review['manager_count'] }}</dd></div>
        </dl>

        @if ($isApproval)
            <p class="hint">לאחר האישור ייווצרו חשבונות פעילים בתפקיד שנקבע לכל בקשה. ניתן יהיה להתחבר עם שם המשתמש והסיסמה שנבחרו בהרשמה.</p>
            @if ($review['manager_count'] > 0)
                <p class="signup-permissions-warning" id="signup-confirm-warning">אישור בקשת מנהל/ת מעניק את כל הרשאות הניהול במערכת, כולל מחיקת חשבונות ושינוי סיסמאות של משתמשים אחרים.</p>
            @else
                <p class="hint" id="signup-confirm-warning">יש לוודא שהבקשות שייכות לאנשים המורשים להשתמש במערכת.</p>
            @endif
            @if ($review['scope'] === 'all')
                <p class="hint">בקשות תקינות יאושרו. בקשות שיש בהן בעיה יישארו ברשימה עם הסבר.</p>
            @endif
        @else
            <p class="signup-permissions-warning" id="signup-confirm-warning">הפעולה תמחק את הבקשות ואת הפרטים שנשלחו בהן. לא ניתן לבטל את המחיקה.</p>
        @endif

        <p class="hint">הפעולה תטפל רק בבקשות שנכללו באישור הזה. בקשות חדשות שיישלחו לאחר מכן יישארו לבדיקה.</p>
        <div class="signup-confirm-actions">
            <form method="POST" action="{{ route('admin.signups.process') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $review['token'] }}">
                <x-button :variant="$isApproval ? 'primary' : 'danger'" aria-describedby="signup-confirm-warning">{{ $isApproval ? 'אישור ויצירת חשבונות' : 'מחיקת הבקשות' }}</x-button>
            </form>
            <a class="button button--secondary" href="{{ route('admin.users.index') }}#signup-requests">ביטול וחזרה</a>
        </div>
    </x-panel>
@endsection
