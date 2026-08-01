<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OfflineProtectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class OfflineProtectionController extends Controller
{
    public function acknowledge(Request $request, OfflineProtectionService $service)
    {
        $device = $request->attributes->get('device');
        $context = $this->acknowledgementLogContext($request);

        Log::info('Offline policy acknowledgement request received.', $context);

        $validator = Validator::make($request->all(), [
            'policy_version' => ['required', 'integer', 'min:1'],
            'nonce' => ['required', 'string', 'max:100'],
            'signature_verified' => ['required', 'accepted'],
            'stored_successfully' => ['required', 'accepted'],
            'local_deadline_at' => ['nullable', 'date'],
            'last_trusted_server_time' => ['nullable', 'date'],
            'local_locked' => ['nullable', 'boolean'],
            'network_status' => ['nullable', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            Log::warning('Offline policy acknowledgement validation failed.', $context + [
                'failed_fields' => array_keys($validator->errors()->messages()),
                'validation_errors' => $validator->errors()->messages(),
            ]);

            throw new ValidationException($validator);
        }

        try {
            $policy = $service->acknowledge($device, $validator->validated());
        } catch (ValidationException $exception) {
            Log::warning('Offline policy acknowledgement was rejected.', $context + [
                'failed_fields' => array_keys($exception->errors()),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Offline policy acknowledgement failed unexpectedly.', $context + [
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
            ]);

            throw $exception;
        }

        Log::info('Offline policy acknowledgement completed successfully.', $context + [
            'policy_id' => $policy->id,
            'policy_acknowledged_at' => $policy->policy_acknowledged_at?->toIso8601String(),
            'last_verified_at' => $policy->last_verified_at?->toIso8601String(),
            'offline_deadline_at' => $policy->offline_deadline_at?->toIso8601String(),
        ]);

        return response()->json(['message' => 'Signed policy verified and acknowledged.', 'data' => ['last_verified_at' => $policy->last_verified_at?->toIso8601String(), 'offline_deadline_at' => $policy->offline_deadline_at?->toIso8601String()]]);
    }

    private function acknowledgementLogContext(Request $request): array
    {
        $device = $request->attributes->get('device');
        $nonce = $request->input('nonce');

        return [
            'device_id' => $device?->id,
            'device_uuid' => $device?->uuid,
            'policy_version' => $request->input('policy_version'),
            'provided_fields' => array_values(array_intersect([
                'policy_version',
                'nonce',
                'signature_verified',
                'stored_successfully',
                'local_deadline_at',
                'last_trusted_server_time',
                'local_locked',
                'network_status',
            ], array_keys($request->all()))),
            'nonce_fingerprint' => is_string($nonce) && $nonce !== '' ? hash('sha256', $nonce) : null,
            'signature_verified_reported' => $request->has('signature_verified') ? $request->boolean('signature_verified') : null,
            'stored_successfully_reported' => $request->has('stored_successfully') ? $request->boolean('stored_successfully') : null,
            'php_sapi' => PHP_SAPI,
        ];
    }

    public function events(Request $request, OfflineProtectionService $service)
    {
        $data = $request->validate(['events' => ['required', 'array', 'max:100'], 'events.*.type' => ['required', 'in:WARNING_SHOWN,OFFLINE_LOCK_TRIGGERED,OFFLINE_LOCK_FAILED,INTERNET_RESTORED,OFFLINE_LOCK_CLEARED,CLOCK_TAMPERING'], 'events.*.occurred_at' => ['required', 'date'], 'events.*.metadata' => ['nullable', 'array']]);
        $device = $request->attributes->get('device');
        $policy = $service->policyFor($device);
        foreach ($data['events'] as $event) {
            $service->audit($device, $event['type'], $policy, null, ($event['metadata'] ?? []) + ['phone_occurred_at' => $event['occurred_at']]);
            if ($event['type'] === 'OFFLINE_LOCK_TRIGGERED') $policy->update(['last_offline_lock_at' => now(), 'last_offline_lock_result' => 'success', 'phone_local_locked' => true]);
            if ($event['type'] === 'OFFLINE_LOCK_CLEARED') $policy->update(['last_offline_lock_result' => 'recovered', 'phone_local_locked' => false]);
        }
        return response()->json(['message' => 'Offline events uploaded.']);
    }
}
