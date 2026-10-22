<?php

namespace App\Http\Requests;

use App\Models\DeskReservation;
use Illuminate\Foundation\Http\FormRequest;

class CancelDeskReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $worker = $this->user();
        $reservation = $this->route('reservation');

        return $worker?->is_active && $worker->role === 'worker'
            && $reservation instanceof DeskReservation && $reservation->worker_id === $worker->getKey();
    }

    public function rules(): array
    {
        return [
            'confirm_cancellation' => ['required', 'accepted'],
            'worker_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
