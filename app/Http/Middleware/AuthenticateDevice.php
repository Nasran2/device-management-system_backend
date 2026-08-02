<?php

namespace App\Http\Middleware;

use App\Models\DeviceToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? DeviceToken::with('device')->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first() : null;
        $syncRequest = $request->is('api/v1/devices/heartbeat', 'api/v1/devices/offline-policy/acknowledge');
        if (! $token || ! $token->device || $token->device->trashed() || $token->device->isReleased()) {
            if ($syncRequest) {
                Log::warning('Device sync authentication failed.', [
                    'request_path' => $request->path(),
                    'bearer_token_present' => filled($plain),
                    'token_record_found' => $token !== null,
                    'device_record_found' => $token?->device !== null,
                    'device_deleted' => (bool) $token?->device?->trashed(),
                    'device_released' => (bool) $token?->device?->isReleased(),
                    'php_sapi' => PHP_SAPI,
                ]);
            }

            return response()->json(['message' => 'Unauthenticated device.'], 401);
        }
        $token->update(['last_used_at' => now()]);
        $request->attributes->set('device', $token->device);

        if ($syncRequest) {
            Log::info('Device sync request authenticated.', [
                'request_path' => $request->path(),
                'device_id' => $token->device->id,
                'device_uuid' => $token->device->uuid,
                'token_id' => $token->id,
            ]);
        }

        return $next($request);
    }
}
