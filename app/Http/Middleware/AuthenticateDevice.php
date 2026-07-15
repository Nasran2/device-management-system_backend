<?php

namespace App\Http\Middleware;

use App\Models\DeviceToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? DeviceToken::with('device')->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first() : null;
        if (! $token || $token->device->isReleased()) {
            return response()->json(['message' => 'Unauthenticated device.'], 401);
        }
        $token->update(['last_used_at' => now()]);
        $request->attributes->set('device', $token->device);

        return $next($request);
    }
}
