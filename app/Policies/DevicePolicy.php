<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    private function tenant(User $user, Device $device): bool
    {
        return $user->shop_id ? $device->shop_id === $user->shop_id : $device->admin_id === $user->id;
    }
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function view(User $user, Device $device): bool
    {
        return $this->tenant($user, $device);
    }

    public function update(User $user, Device $device): bool
    {
        return $this->tenant($user, $device) && ! $device->isReleased();
    }

    public function control(User $user, Device $device): bool
    {
        return $this->tenant($user, $device) && ! $device->isReleased() && ($user->shop?->lock_unlock_enabled ?? true) && $user->canShop('lock_unlock');
    }

    public function managePin(User $user, Device $device): bool
    {
        return $this->tenant($user, $device) && $user->canShop('devices');
    }

    public function resetPinAttempts(User $user, Device $device): bool
    {
        return $user->isSuperAdmin();
    }

    public function viewLocation(User $user, Device $device): bool
    {
        return $this->tenant($user, $device) && $user->can_view_locations;
    }

    public function archive(User $user, Device $device): bool
    {
        return $this->tenant($user, $device);
    }

    public function restore(User $user, Device $device): bool
    {
        return false;
    }

    public function delete(User $user, Device $device): bool
    {
        return false;
    }

    public function forceDelete(User $user, Device $device): bool
    {
        return false;
    }
}
