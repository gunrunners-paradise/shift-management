@if (session('status'))
    <div class="message message--success" role="status">{{ session('status') }}</div>
@endif

@if (isset($errors) && $errors->any())
    <div class="message message--error" role="alert" tabindex="-1" data-error-summary>
        <p><strong>לא ניתן להשלים את הפעולה. יש לבדוק את הפרטים:</strong></p>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
