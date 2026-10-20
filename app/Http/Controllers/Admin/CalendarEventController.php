<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteCalendarEventRequest;
use App\Http\Requests\StoreCalendarEventRequest;
use App\Models\CalendarEvent;
use App\Services\CalendarEventService;
use Illuminate\Http\RedirectResponse;

class CalendarEventController extends Controller
{
    public function store(StoreCalendarEventRequest $request, CalendarEventService $events): RedirectResponse
    {
        $events->create($request->user(), $request->validated());

        return redirect()->to(route('admin.users.index').'#events')
            ->with('status', 'האירוע נוסף ללוח. זמינות המשמרות לא השתנתה.');
    }

    public function update(StoreCalendarEventRequest $request, CalendarEvent $calendarEvent, CalendarEventService $events): RedirectResponse
    {
        $events->update($request->user(), $calendarEvent, $request->validated());

        return redirect()->to(route('admin.users.index').'#events')->with('status', 'פרטי האירוע עודכנו.');
    }

    public function destroy(DeleteCalendarEventRequest $request, CalendarEvent $calendarEvent, CalendarEventService $events): RedirectResponse
    {
        $events->delete($request->user(), $calendarEvent);

        return redirect()->to(route('admin.users.index').'#events')->with('status', 'האירוע הוסר מלוח השנה.');
    }
}
