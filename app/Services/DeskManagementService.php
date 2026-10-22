<?php

namespace App\Services;

use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\User;
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
     * Permanently delete a desk and every reservation belonging to it.
     * The approved deletion includes past, completed and canceled history.
     * Both active and previously deactivated desk identities may be deleted.
     *
     * ManagerTransaction rechecks authority and serializes closures/account
     * changes. Every worker mutation locks the desk before reservation rows,
     * so this lock prevents new bookings even on a first-ever date. Do not
     * acquire date or worker locks afterward: that would reverse booking lock
     * order. Permanent calendar-date keys and unrelated records remain intact.
     */
    public function delete(User $manager, Desk $desk): void
    {
        $this->managerTransaction->run($manager, function () use ($desk): void {
            $current = Desk::query()->lockForUpdate()->findOrFail($desk->getKey());

            // Explicit child-first deletion keeps restrictive foreign keys
            // enabled. Any failure rolls back the desk and its entire history.
            DeskReservation::query()->where('desk_id', $current->getKey())->delete();
            $current->delete();
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
