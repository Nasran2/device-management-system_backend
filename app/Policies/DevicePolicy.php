<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function view(User $user, Device $device): bool
    {
        return $device->admin_id === $user->id;
    }

    public function update(User $user, Device $device): bool
    {
        return $device->admin_id === $user->id && ! $device->isReleased();
    }

    public function control(User $user, Device $device): bool
    {
        return $device->admin_id === $user->id && ! $device->isReleased();
    }

    public function managePin(User $user, Device $device): bool
    {
        return $device->admin_id === $user->id;
    }

    public function resetPinAttempts(User $user, Device $device): bool
    {
        return $user->isSuperAdmin();
    }

    public function viewLocation(User $user, Device $device): bool
    {
        return $device->admin_id === $user->id && $user->can_view_locations;
    }
}
