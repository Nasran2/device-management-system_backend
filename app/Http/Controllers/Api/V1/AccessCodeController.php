<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceAccessCode;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccessCodeController extends Controller
{
    public function redeem(Request $request, AuditService $audit)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $device = $request->attributes->get('device');
        $codes = DeviceAccessCode::where('device_id', $device->id)->where('type', 'temporary_unlock')->whereNull('used_at')->latest()->get();
        $code = $codes->first(fn ($candidate) => Hash::check(strtoupper($data['code']), $candidate->code_hash));
        if (! $code) {
            $codes->each(fn ($candidate) => $candidate->increment('attempts'));

            return response()->json(['message' => 'The unlock code is invalid.', 'error_code' => 'INVALID_UNLOCK_CODE'], 422);
        }
        if ($code->expires_at->isPast()) {
            return response()->json(['message' => 'The unlock code has expired.', 'error_code' => 'UNLOCK_CODE_EXPIRED'], 410);
        }
        if ($code->attempts >= $code->max_attempts) {
            return response()->json(['message' => 'Maximum unlock attempts exceeded.', 'error_code' => 'UNLOCK_ATTEMPTS_EXCEEDED'], 429);
        }
        $code->update(['used_at' => now()]);
        $device->update(['status' => 'active_unlocked', 'lock_status' => 'unlocked', 'last_seen_at' => now()]);
        $audit->record('unlock_code_used', 'Temporary unlock code used on device', null, $device);

        return response()->json(['success' => true, 'message' => 'Temporary unlock approved.']);
    }
}
