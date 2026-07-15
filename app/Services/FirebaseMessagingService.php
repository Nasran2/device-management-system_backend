<?php

namespace App\Services;

use App\Models\DeviceCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebaseMessagingService
{
    public function configured(): bool
    {
        return filled(config('services.firebase.project_id')) && is_readable((string) config('services.firebase.credentials'));
    }

    public function send(DeviceCommand $command): void
    {
        if (! $this->configured() || blank($command->device->fcm_token)) {
            return;
        }

        $project = config('services.firebase.project_id');
        Http::withToken($this->accessToken())->timeout(15)->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
            'message' => [
                'token' => $command->device->fcm_token,
                'data' => [
                    'command_uuid' => $command->uuid,
                    'device_uuid' => $command->device->uuid,
                    'command_type' => $command->type,
                    'issued_at' => $command->created_at->toISOString(),
                    'expires_at' => $command->expires_at->toISOString(),
                    'sync_required' => 'true',
                ],
                'android' => ['priority' => 'high', 'ttl' => max(0, now()->diffInSeconds($command->expires_at)).'s'],
            ],
        ])->throw();
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase_http_v1_access_token', now()->addMinutes(50), function () {
            $credentials = json_decode(file_get_contents(config('services.firebase.credentials')), true, flags: JSON_THROW_ON_ERROR);
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64Url(json_encode(['iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => $credentials['token_uri'], 'iat' => $now, 'exp' => $now + 3600]));
            if (! openssl_sign("{$header}.{$claims}", $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Unable to sign Firebase service-account assertion.');
            }
            $assertion = "{$header}.{$claims}.".$this->base64Url($signature);

            return Http::asForm()->timeout(15)->post($credentials['token_uri'], ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion])->throw()->json('access_token');
        });
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
