<div id="calendar-today-information" class="today-information" data-today-information data-refresh-url="{{ route('worker.today') }}">
    <section id="announcements" class="today-announcements" aria-labelledby="announcements-heading" @if (count($todayInformation['announcements']) === 0) hidden @endif>
        <h2 id="announcements-heading">אירועים וחגים היום</h2>
        <p class="today-announcements-date">הודעות ל־<time data-today-date datetime="{{ $todayInformation['date'] }}">{{ $todayInformation['display_date'] }}</time></p>
        <ul class="today-announcements-list" data-announcements-list aria-live="polite">
            @foreach ($todayInformation['announcements'] as $announcement)
                <li class="today-announcement">
                    <div class="today-announcement-heading">
                        <h3>{{ $announcement['title'] }}</h3>
                        <span class="today-announcement-kind">{{ $announcement['kind'] === 'holiday' ? 'חג' : 'אירוע' }}</span>
                    </div>
                    @if ($announcement['description'])
                        <p>{{ $announcement['description'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
        <p class="today-announcements-note">ההודעות לידיעה בלבד. אירוע או חג אינם חוסמים הרשמה למשמרות; חסימת תאריך נעשית בנפרד.</p>
    </section>

    <section id="birthdays" class="today-birthdays" aria-labelledby="birthdays-heading" @if (count($todayInformation['birthdays']) === 0) hidden @endif>
        <h2 id="birthdays-heading">ימי הולדת היום</h2>
        <p class="today-birthday-description">מזל טוב לחברי הצוות שחוגגים היום!</p>
        <ul class="today-birthday-list" data-birthday-list aria-live="polite">
            @foreach ($todayInformation['birthdays'] as $person)
                <li class="today-birthday-person">
                    <img class="today-person-avatar" src="{{ $person['avatar_url'] }}" alt="" width="44" height="44" loading="lazy">
                    <div class="today-person-details">
                        <strong>{{ $person['name'] }}</strong>
                        <span>מזל טוב ליום ההולדת!</span>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <section id="coworkers" class="panel today-coworkers" aria-labelledby="coworkers-heading">
        <div class="today-coworkers-heading">
            <div>
                <h2 id="coworkers-heading">מי עובד איתי היום?</h2>
                <p>משמרות מתוכננות ל־<time data-today-date datetime="{{ $todayInformation['date'] }}">{{ $todayInformation['display_date'] }}</time></p>
            </div>
            <span class="today-coworkers-count" data-coworkers-count>{{ count($todayInformation['coworkers']) }} עובדים</span>
        </div>

        <ul class="today-coworkers-list" data-coworkers-list aria-live="polite">
            @forelse ($todayInformation['coworkers'] as $coworker)
                @php
                    // The isolated time range stays readable in Hebrew without repeating it in the title.
                    $timeRange = $coworker['start'].'–'.$coworker['end'];
                    $periodTitle = trim(str_replace($timeRange, '', $coworker['period_label']));
                @endphp
                <li class="today-coworker">
                    <img class="today-person-avatar" src="{{ $coworker['avatar_url'] }}" alt="" width="46" height="46" loading="lazy">
                    <div class="today-person-details">
                        <strong>{{ $coworker['name'] }}</strong>
                        <span class="today-person-period" title="{{ $coworker['period_label'] }}">
                            <span>{{ $periodTitle }}</span>
                            <bdi dir="ltr">{{ $timeRange }}</bdi>
                        </span>
                    </div>
                </li>
            @empty
                <li class="today-coworkers-empty">אין עובדים רשומים למשמרת היום.</li>
            @endforelse
        </ul>

        <p class="today-refresh-status" data-today-refresh-status role="status" hidden></p>
    </section>
</div>
