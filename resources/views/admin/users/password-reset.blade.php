@php
    // Only the submitted account reopens and displays this named validation bag.
    // The route identifies the account; this hidden value only restores its UI.
    $passwordErrors = $errors->getBag('passwordReset');
    $isFailedPasswordForm = $passwordErrors->any()
        && is_scalar(old('password_form_id'))
        && (string) old('password_form_id') === (string) $account->id;
@endphp

<details id="account-password-reset-{{ $account->id }}" class="account-password-reset" @if ($isFailedPasswordForm) open @endif>
    <summary>שינוי סיסמה <span class="screen-reader-only">של {{ $account->fullName() }}</span></summary>

    <form id="account-password-reset-form-{{ $account->id }}" class="account-password-reset-form" method="POST" action="{{ route('admin.users.password.update', ['user' => $account, 'page' => $users->currentPage()]) }}" aria-describedby="account-password-reset-note-{{ $account->id }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="password_form_id" value="{{ $account->id }}">

        <p id="account-password-reset-note-{{ $account->id }}" class="account-password-reset-note">הסיסמה של {{ $account->fullName() }} תוחלף והכניסות הקיימות לחשבון יסתיימו. יש למסור את הסיסמה החדשה באופן פרטי.</p>
        @if (! $account->is_active)
            <p class="account-password-reset-note">החשבון יישאר לא פעיל עד להפעלתו מחדש.</p>
        @endif

        @if ($isFailedPasswordForm)
            <div class="message message--error" role="alert" tabindex="-1" data-error-summary>
                <p><strong>לא ניתן לשנות את הסיסמה. יש לבדוק את הפרטים:</strong></p>
                <ul>
                    @foreach ($passwordErrors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="field-row">
            <x-field :id="'account-password-'.$account->id" name="password" label="סיסמה חדשה" type="password" error-bag="passwordReset" :show-errors="$isFailedPasswordForm" minlength="12" maxlength="72" autocomplete="new-password" hint="לפחות 12 תווים, עד 72 בתים. אות בעברית תופסת יותר מבית אחד." required />
            <x-field :id="'account-password-confirmation-'.$account->id" name="password_confirmation" label="אימות סיסמה" type="password" error-bag="passwordReset" :show-errors="$isFailedPasswordForm" minlength="12" maxlength="72" autocomplete="new-password" required />
        </div>

        <x-button class="button--small">שמירת סיסמה</x-button>
    </form>
</details>
