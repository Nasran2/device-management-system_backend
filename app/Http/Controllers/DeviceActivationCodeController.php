<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\ActivationService;
use Illuminate\Http\Request;

class DeviceActivationCodeController extends Controller
{
    public function generate(Request $request, Device $device, ActivationService $activations)
    {
        $this->authorize('generateActivationCode', $device);
        abort_if($device->isReleased(), 422, 'A permanently released device cannot receive an activation code.');
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'confirmed' => ['accepted'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $result = $activations->ensure(
            $device,
            $request->user(),
            null,
            $data['reason'] ?? 'manual_regeneration',
            true,
        );
        $activations->sendSmsIfEnabled($device->loadMissing(['customer', 'shop']), $result['plain'], $request->user());

        return back()->with('success', 'A new activation code was generated successfully.');
    }

    public function revoke(Request $request, Device $device, ActivationService $activations)
    {
        $this->authorize('revokeActivationCode', $device);
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'confirmed' => ['accepted'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $revoked = $activations->revoke($device, $request->user(), $data['reason']);

        return back()->with($revoked ? 'success' : 'warning', $revoked
            ? 'The active activation code was revoked.'
            : 'There is no active activation code to revoke.');
    }
}
