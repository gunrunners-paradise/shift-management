<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelDeskReservationRequest;
use App\Http\Requests\DeskPageRequest;
use App\Http\Requests\StoreDeskReservationRequest;
use App\Models\DeskReservation;
use App\Services\DeskAvailabilityService;
use App\Services\DeskReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DeskReservationController extends Controller
{
    public function index(DeskPageRequest $request, DeskAvailabilityService $availability): View
    {
        $date = $request->validated('date') ?: CarbonImmutable::today(config('app.timezone'))->toDateString();
        $deskId = $request->validated('desk_id');

        return view('worker.desks', $availability->forDate($request->user(), $date, $deskId === null ? null : (int) $deskId));
    }

    public function store(StoreDeskReservationRequest $request, DeskReservationService $reservations): RedirectResponse
    {
        $data = $request->validated();
        $reservation = $reservations->book($request->user(), (int) $data['desk_id'], $data['reservation_date'], $data['start_time'], $data['end_time']);

        return $this->redirectToReservation($reservation, 'הזמנת העמדה נשמרה.');
    }

    public function update(StoreDeskReservationRequest $request, DeskReservation $reservation, DeskReservationService $reservations): RedirectResponse
    {
        $data = $request->validated();
        $updatedReservation = $reservations->update($request->user(), $reservation, (int) $data['desk_id'], $data['start_time'], $data['end_time']);

        return $this->redirectToReservation($updatedReservation, 'ההזמנה עודכנה. פרטי ההזמנה הקודמת נשמרו בהיסטוריה.');
    }

    public function destroy(CancelDeskReservationRequest $request, DeskReservation $reservation, DeskReservationService $reservations): RedirectResponse
    {
        $reservations->cancel($request->user(), $reservation);

        return $this->redirectToReservation($reservation, 'ההזמנה בוטלה ונשמרה בהיסטוריה.');
    }

    private function redirectToReservation(DeskReservation $reservation, string $message): RedirectResponse
    {
        return redirect()->to(route('worker.desks.index', [
            'date' => $reservation->reservation_date->toDateString(),
        ]).'#my-desk-reservations')->with('status', $message);
    }
}
