# Questions for Reut / שאלות לראות

2026-11-17 — prepared for the demonstration. The Polygon demo uses fictional
accounts. Existing learners have not been migrated.

1. **Where is the current learner database, and who will provide its export?**
   We need the database and private profile pictures from the installation the
   students actually use. We can then compare accounts and history before
   switching them over.
   **איפה נמצאת המערכת הפעילה, ומי יספק ייצוא של הנתונים ותמונות הפרופיל?**

2. **Should workers also see everyone's weekly schedule?**
   The new manager view shows everyone. Workers retain the fork's own-shifts
   view and today's coworkers. A shared weekly worker view would change that
   visibility rule.
   **האם גם המשתתפים צריכים לראות את הלוח השבועי של כולם, או רק מנהלים?**

3. **Which extra tasks should Itzik handle next?**
   He currently proposes hourly shifts and opens the weekly schedule. Possible
   next tasks are desk availability or explaining an existing reservation.
   **במה עוד איציק צריך לעזור — עמדות פנויות, הסבר על הרשמה קיימת, או משהו אחר?**

4. **Is a one-date closure enough for normal operation?**
   Closing each date is implemented. Repeating weekly closures or multi-date
   closures would need an explicit rule, especially for existing bookings.
   **האם סגירה לפי תאריך מספיקה, או שצריך גם סגירה חוזרת/טווח תאריכים?**

## Confirmed behavior to demonstrate

- Exactly five hourly periods, with a weekly manager overview.
- Changes to today's shift through 23:59; CSV counts remaining scheduled days.
- Desk reservations independent of shifts.
- Closing a day cancels shifts and desks; reopening does not restore them.
- Itzik's original portrait and explicit confirmation before saving a shift.

These are already implemented decisions, not questions awaiting approval.
