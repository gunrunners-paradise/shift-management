<?php
// Build-time only: php templates/index.php > web/index.html
function field(string $name, string $label, string $type = 'text', bool $required = true): void
{
    $id = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    echo '<div class="field"><label>'.$label.'<input class="form-control" name="'.$id.'" type="'.$type.'"'.($required ? ' required' : '').($type === 'password' ? ' minlength="12" autocomplete="new-password"' : '').'></label></div>';
}
function select(string $name, string $label, array $values): void
{
    echo '<div class="field"><label>'.$label.'<select class="form-control" name="'.$name.'">';
    foreach ($values as $v => $text) {
        echo '<option value="'.$v.'">'.$text.'</option>';
    }
    echo '</select></label></div>';
}
function person(bool $role = false): void
{
    field('login', 'שם משתמש');
    field('first_name', 'שם פרטי');
    field('last_name', 'שם משפחה');
    select('gender', 'מגדר', ['other' => 'אחר', 'male' => 'זכר', 'female' => 'נקבה']);
    field('birth_date', 'תאריך לידה', 'date', false);
    if ($role) {
        select('role', 'תפקיד', ['member' => 'משתתף/ת', 'admin' => 'מנהל/ת']);
    }
    field('password', 'סיסמה — לפחות 12 תווים', 'password');
    field('password_confirmation', 'אימות סיסמה', 'password');
}
function submit(string $text = 'שמירה'): void
{
    echo '<button class="button button--primary" type="submit">'.$text.'</button>';
}
?>
<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>מערכת נוכחות · משמרת</title>
<?php foreach (['app', 'calendar', 'desks', 'profile', 'today', 'receipts', 'events', 'blocked-dates', 'reports', 'signup'] as $css) { ?>
<link rel="stylesheet" href="/css/<?= $css ?>.css"><?php } ?>
<link rel="stylesheet" href="/style.css"><script src="/app.js" defer></script></head><body>
<a class="skip-link" href="#main">דילוג לתוכן</a>
<header class="topbar"><div class="topbar-inner"><div class="header-brand-group"><img class="brand-logo" src="/images/enter_digital_studio.svg" alt="Enter Digital Studio" width="49" height="49"><strong class="brand">מערכת נוכחות</strong></div><div id="account" hidden><button class="header-link" data-view="profile" id="account-name"></button><button class="header-link" id="logout">התנתקות</button></div></div></header>
<div id="message" role="status" aria-live="polite" hidden></div>
<main id="main">
<section id="login-screen" class="guest-main" hidden><div class="login-card"><img class="login-logo" src="/images/enter_digital_studio.svg" alt="Enter Digital Studio" width="200" height="176"><h1>התחברות למערכת</h1><p class="login-subtitle">הזינו את פרטי המשתמש שלכם כדי להמשיך</p><form id="login-form"><div class="field"><label>שם משתמש<input class="form-control" name="login" autocomplete="username" dir="ltr" required></label></div><div class="field"><label>סיסמה<input class="form-control" name="password" type="password" autocomplete="current-password" required></label></div><?php submit('התחברות') ?></form></div></section>
<section id="signup-screen" class="content-shell" hidden><div class="panel"><h1>בקשת הרשמה</h1><p id="signup-message"></p><form id="signup-form" class="form-grid" hidden><?php person();
submit('שליחת בקשה') ?></form></div></section>
<section id="password-screen" class="content-shell" hidden><div class="panel"><h1>בחירת סיסמה אישית</h1><form id="first-password-form"><?php field('old_password', 'סיסמה זמנית', 'password');
field('new_password', 'סיסמה חדשה', 'password');
field('password_confirmation', 'אימות הסיסמה', 'password');
submit() ?></form></div></section>
<div id="application" class="content-shell" hidden><nav id="navigation" class="calendar-navigation" aria-label="דפי המערכת">
<?php foreach (['week' => 'לוח שבועי', 'today' => 'היום במרכז', 'desks' => 'עמדות', 'receipts' => 'קבלות', 'profile' => 'הפרופיל שלי', 'admin' => 'ניהול'] as $key => $label) { ?><button class="button button--secondary" data-view="<?= $key ?>"<?= $key === 'admin' ? ' data-admin' : '' ?><?= in_array($key, ['desks', 'receipts']) ? ' data-worker' : '' ?>><?= $label ?></button><?php } ?></nav>
<section id="view-week" data-page><div class="calendar-week-heading"><h1 id="week-title">לוח משמרות</h1><p id="week-range"></p></div><div class="calendar-navigation"><button id="previous-week" class="button button--secondary">שבוע קודם</button><button id="current-week" class="button button--secondary">השבוע</button><button id="next-week" class="button button--secondary">שבוע הבא</button></div><p id="week-help" class="calendar-help"></p><div id="calendar" class="calendar-scroll" tabindex="0" aria-label="לוח שבועי"></div></section>
<section id="view-receipts" data-page hidden><div class="panel"><h1>קבלות לחודש הנוכחי</h1><form id="receipt-form"><?php select('has_receipts', 'האם יש קבלות?', ['false' => 'אין', 'true' => 'יש']);
submit() ?></form></div></section>
<section id="view-today" data-page hidden><div class="panel"><h1>היום במרכז</h1><div id="today-content"></div></div></section>
<section id="view-desks" data-page hidden><div class="panel"><h1>עמדות עבודה</h1><p>הזמנת עמדה נפרדת מההרשמה למשמרת.</p><label>תאריך<input id="desk-date" class="form-control" type="date"></label><div id="desk-list"></div><form id="desk-form" class="form-grid"><input type="hidden" name="id"><div class="field"><label>עמדה<select class="form-control" name="desk_id" id="desk-select"></select></label></div><?php field('start_time', 'משעה', 'time');
field('end_time', 'עד שעה', 'time');
submit('שמירת עמדה') ?></form></div></section>
<section id="view-profile" data-page hidden><div class="panel"><h1>הפרופיל שלי</h1><img id="profile-avatar" class="avatar" src="/images/default-avatar.svg" alt="תמונת הפרופיל" width="80" height="80"><form id="avatar-form"><label class="field">תמונת פרופיל (JPG, PNG, WebP; עד 2MB ו-2048 פיקסלים)<input name="photo" type="file" accept="image/jpeg,image/png,image/webp" required></label><button class="button button--secondary">עדכון תמונה</button><button class="button button--secondary" type="button" id="avatar-delete">הסרת תמונה</button></form><form id="profile-form" class="form-grid"><?php field('first_name', 'שם פרטי');
field('last_name', 'שם משפחה');
select('gender', 'מגדר', ['other' => 'אחר', 'male' => 'זכר', 'female' => 'נקבה']);
field('birth_date', 'תאריך לידה', 'date', false);
submit() ?></form></div><details class="panel"><summary>שינוי סיסמה</summary><form id="password-form"><?php field('old_password', 'סיסמה נוכחית', 'password');
field('new_password', 'סיסמה חדשה', 'password');
field('password_confirmation', 'אימות סיסמה', 'password');
submit() ?></form></details></section>
<section id="view-admin" data-page hidden><div class="page-title"><h1>ניהול המערכת</h1><p>הלוח השבועי המשותף זמין בתפריט הראשי.</p></div>
<?php foreach (['users' => 'משתמשים', 'closures' => 'ימים סגורים', 'events' => 'אירועים וחגים', 'desks' => 'עמדות', 'signups' => 'בקשות הרשמה', 'receipts' => 'קבלות לפי חודש', 'report' => 'דוח ימי עבודה'] as $key => $label) { ?>
<details class="panel" data-admin-panel="<?= $key ?>"><summary><?= $label ?></summary><div id="admin-<?= $key ?>-list"></div>
<?php if ($key === 'users') { ?><details><summary>הוספת חשבון</summary><form id="user-form" class="form-grid"><?php person(true);
    submit('הוספה') ?></form></details>
<?php } elseif ($key === 'closures') { ?><form id="closure-form" class="form-grid"><?php field('date', 'תאריך', 'date');
    field('reason', 'סיבת סגירה', 'text', false);
    submit('סגירת יום') ?></form><p class="field-hint">סגירה מבטלת את כל המשמרות והעמדות ביום הזה. פתיחה מחדש לא תשחזר אותן.</p>
<?php } elseif ($key === 'events') { ?><form id="event-form" class="form-grid"><input type="hidden" name="id"><?php field('date', 'תאריך', 'date');
    field('title', 'כותרת');
    select('kind', 'סוג', ['event' => 'אירוע', 'holiday' => 'חג']);
    field('description', 'פרטים', 'text', false);
    submit() ?></form>
<?php } elseif ($key === 'desks') { ?><form id="admin-desk-form" class="form-grid"><input type="hidden" name="id"><?php field('name', 'שם העמדה');
    submit() ?></form>
<?php } elseif ($key === 'receipts') { ?><form id="admin-receipts-form" class="form-grid"><?php field('month', 'חודש', 'month');
    submit('הצגה') ?></form>
<?php } elseif ($key === 'report') { ?><form id="report-form" class="form-grid"><?php field('from','מתאריך','date');
    field('to','עד תאריך','date');
    field('month','חודש קבלות','month');
    submit('הורדת CSV') ?></form>
<?php } ?></details><?php } ?></section></div>
</main>
<button id="itzik-toggle" class="itzik-toggle" hidden aria-expanded="false" aria-controls="itzik-chat"><img src="/itzik.svg" alt="איציק" width="54" height="54"><span>איציק כאן</span></button>
<aside id="itzik-chat" class="itzik-chat" hidden aria-label="שיחה עם איציק"><header><img src="/itzik.svg" alt="" width="56" height="56"><div><strong>איציק</strong><p>נעשה סדר בשבוע שלך</p></div><button id="itzik-close" aria-label="סגירת השיחה">×</button></header><div id="itzik-messages" role="log" aria-live="polite"></div><form id="itzik-form"><label class="screen-reader-only" for="itzik-text">הודעה לאיציק</label><input id="itzik-text" class="form-control" name="text" placeholder="למשל: תרשום אותי ביום ראשון מ-9 עד 13" maxlength="240" required><button class="button button--primary">שליחה</button></form></aside>
<dialog id="edit-dialog" class="shift-dialog"><h2 id="dialog-title"></h2><div id="dialog-body"></div><button id="dialog-close" class="button button--secondary">סגירה</button></dialog>
</body></html>
