<?php

namespace App\Services;

use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeskManagementService
{
    public function __construct(private readonly ManagerTransaction $managerTransaction) {}

    /** Add one stable desk identity after rechecking manager authority. */
    public function create(User $manager, string $name): Desk
    {
        $name = $this->validatedName($name);

        return $this->managerTransaction->run($manager, function () use ($name): Desk {
            $this->ensureNameIsAvailable($name);

            return Desk::create(['name' => $name, 'is_active' => true]);
        });
    }

    /** Rename without changing the desk ID or any reservation's relationship. */
    public function rename(User $manager, Desk $desk, string $name): Desk
    {
        $name = $this->validatedName($name);

        return $this->managerTransaction->run($manager, function () use ($desk, $name): Desk {
            $current = Desk::query()->lockForUpdate()->findOrFail($desk->getKey());
            $this->ensureNameIsAvailable($name, $current->getKey());
            $current->name = $name;
            $current->save();

            return $current;
        });
    }

    /**
     * Deactivate and immediately cancel future and running reservations.
     * Completed intervals and the stable desk identity remain available.
     *
     * The shared manager gate serializes this with closures and account changes.
     * Every worker mutation locks the desk before touching reservation rows, so
     * this desk lock also covers bookings on a first-ever date. No date locks
     * are acquired here: desk -> date would invert workers' date -> desk order,
     * and gathering known dates first could miss a concurrent first booking.
     */
    public function deactivate(User $manager, Desk $desk): void
    {
        $this->managerTransaction->run($manager, function () use ($manager, $desk): void {
            $current = Desk::query()->lockForUpdate()->findOrFail($desk->getKey());

            if (! $current->is_active) {
                return;
            }

            $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
            $reservations = DeskReservation::active()->where('desk_id', $current->getKey())
                ->where('reservation_date', '>=', $today)->orderBy('reservation_date')->orderBy('id')
                ->lockForUpdate()->get();
            // Evaluate completion after any reservation-row wait. At exactly
            // the configured end, the reservation is already completed.
            $now = CarbonImmutable::now(config('app.timezone'));

            foreach ($reservations as $reservation) {
                if ($reservation->endsAt()->greaterThan($now)) {
                    $reservation->cancel($manager->getKey(), 'desk_deactivated', $now);
                }
            }

            $current->forceFill([
                'is_active' => false,
                'deactivated_at' => $now,
                'deactivated_by' => $manager->getKey(),
            ])->save();
        });
    }

    private function validatedName(string $name): string
    {
        $name = trim($name);
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:60']])->validate();

        return $name;
    }

    /** ManagerTransaction serializes the name check with desk add/rename. */
    private function ensureNameIsAvailable(string $name, ?int $exceptId = null): void
    {
        $matchingDesk = Desk::active()->where('name', $name);

        if ($exceptId !== null) {
            $matchingDesk->whereKeyNot($exceptId);
        }

        if ($matchingDesk->exists()) {
            throw ValidationException::withMessages(['name' => 'כבר קיימת עמדה פעילה בשם זה.']);
        }
    }
}
