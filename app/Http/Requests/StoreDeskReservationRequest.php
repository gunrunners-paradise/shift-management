<?php

namespace App\Http\Requests;

use App\Models\DeskReservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeskReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $worker = $this->user();
        $reservation = $this->route('reservation');

        return $worker?->is_active && $worker->role === 'worker'
            && (! $reservation instanceof DeskReservation || $reservation->worker_id === $worker->getKey());
    }

    /** Editing keeps the original date; moving dates requires canceling and booking anew. */
    public function rules(): array
    {
        $dateRules = ['required', 'date_format:Y-m-d'];
        $reservation = $this->route('reservation');

        if ($reservation instanceof DeskReservation) {
            $dateRules[] = Rule::in([$reservation->reservation_date->toDateString()]);
        }

        return [
            'reservation_date' => $dateRules,
            'desk_id' => ['required', 'integer', Rule::exists('desks', 'id')->where('is_active', true)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            // Ownership and cancellation audit fields belong to the server.
            'worker_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
            'cancellation_reason' => ['prohibited'],
        ];
    }
}
