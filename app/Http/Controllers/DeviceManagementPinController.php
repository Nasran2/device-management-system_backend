<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\AuditService;
use App\Services\CommandService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class DeviceManagementPinController extends Controller
{
    public function reveal(Request $request, Device $device, AuditService $audit)
    {
        $this->authorize('managePin', $device);
        $request->validate(['password' => ['required', 'current_password']]);
        abort_unless($device->management_pin_encrypted, 404, 'No management PIN is configured.');
        $audit->record('MANAGEMENT_PIN_REVEALED', 'Device management PIN revealed after account-password confirmation', $request->user(), $device);

        return back()->with('revealed_management_pin', Crypt::decryptString($device->management_pin_encrypted));
    }

    public function change(Request $request, Device $device, AuditService $audit, CommandService $commands)
    {
        $this->authorize('managePin', $device);
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'management_pin' => ['required', 'digits:4', 'confirmed', 'not_in:0000,1111,1234,4321'],
        ]);
        $this->savePin($device, $data['management_pin'], $request->user()->id);
        $audit->record('MANAGEMENT_PIN_CHANGED', 'Device management PIN changed', $request->user(), $device);
        $commands->create($device, 'SYNC_DEVICE', ['reason' => 'Management PIN configuration changed'], $request->user());

        return back()->with('success', 'Management PIN changed.')->with('revealed_management_pin', $data['management_pin']);
    }

    public function generate(Request $request, Device $device, AuditService $audit, CommandService $commands)
    {
        $this->authorize('managePin', $device);
        $request->validate(['password' => ['required', 'current_password']]);
        do {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (in_array($pin, ['0000', '1111', '1234', '4321'], true));
        $this->savePin($device, $pin, $request->user()->id);
        $audit->record('MANAGEMENT_PIN_GENERATED', 'New device management PIN generated', $request->user(), $device);
        $commands->create($device, 'SYNC_DEVICE', ['reason' => 'Management PIN configuration changed'], $request->user());

        return back()->with('success', 'A new management PIN was generated.')->with('revealed_management_pin', $pin);
    }

    public function resetAttempts(Request $request, Device $device, AuditService $audit)
    {
        $this->authorize('resetPinAttempts', $device);
        $request->validate(['password' => ['required', 'current_password']]);
        $device->update(['management_pin_failed_attempts' => 0, 'management_pin_locked_until' => null]);
        $audit->record('MANAGEMENT_PIN_ATTEMPTS_RESET', 'Management PIN failed attempts reset', $request->user(), $device);

        return back()->with('success', 'Failed PIN attempts reset.');
    }

    private function savePin(Device $device, string $pin, int $userId): void
    {
        $device->update([
            'management_pin_hash' => Hash::make($pin),
            'management_pin_encrypted' => Crypt::encryptString($pin),
            'management_pin_changed_at' => now(),
            'management_pin_changed_by' => $userId,
            'management_pin_failed_attempts' => 0,
            'management_pin_locked_until' => null,
        ]);
    }
}
