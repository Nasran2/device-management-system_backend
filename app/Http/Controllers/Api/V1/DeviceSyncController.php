<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OfflineProtectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DeviceSyncController extends Controller
{
    public function heartbeat(Request $request, OfflineProtectionService $offline)
    {
        $device = $request->attributes->get('device');
        $context = [
            'device_id' => $device?->id,
            'device_uuid' => $device?->uuid,
            'provided_fields' => array_values(array_intersect([
                'battery_percentage', 'gps_status', 'network_status', 'fcm_token', 'app_version', 'local_lock_reason',
            ], array_keys($request->all()))),
            'app_version' => is_string($request->input('app_version')) ? $request->input('app_version') : null,
            'local_lock_reason' => is_string($request->input('local_lock_reason')) ? $request->input('local_lock_reason') : null,
            'php_sapi' => PHP_SAPI,
        ];
        Log::info('Device heartbeat request received.', $context);

        $validator = Validator::make($request->all(), [
            'battery_percentage' => ['nullable', 'integer', 'between:0,100'],
            'gps_status' => ['nullable', 'in:enabled,disabled,unknown'],
            'network_status' => ['nullable', 'string', 'max:30'],
            'fcm_token' => ['nullable', 'string', 'max:4096'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'local_lock_reason' => ['nullable', 'in:NONE,OFFLINE_TIMEOUT,INTEGRITY_FAILURE,SERVER_LOCK,UNKNOWN_PROTECTED_LOCK'],
        ]);
        if ($validator->fails()) {
            Log::warning('Device heartbeat validation failed.', $context + [
                'failed_fields' => array_keys($validator->errors()->messages()),
                'validation_errors' => $validator->errors()->messages(),
            ]);

            throw new ValidationException($validator);
        }

        $data = $validator->validated();
        $localLockReason = $data['local_lock_reason'] ?? 'NONE';
        unset($data['local_lock_reason']);

        $freshDevice = $device->fresh();
        try {
            $signedPolicy = $offline->issue($freshDevice, $localLockReason === 'OFFLINE_TIMEOUT');
        } catch (RuntimeException $exception) {
            Log::error('Device heartbeat failed while issuing the signed offline policy.', [
                'device_id' => $device->id,
                'device_uuid' => $device->uuid,
                'request_path' => $request->path(),
                'php_sapi' => PHP_SAPI,
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Device synchronization is temporarily unavailable.',
                'error_code' => 'OFFLINE_POLICY_SIGNING_FAILED',
            ], 503);
        } catch (Throwable $exception) {
            Log::error('Device heartbeat failed unexpectedly.', $context + [
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
            ]);

            throw $exception;
        }

        $device->update($data + ['last_seen_at' => now(), 'last_sync_at' => now()]);
        $freshDevice = $device->fresh();
        $commands = $device->commands()->whereIn('status', ['pending', 'dispatched'])->where('expires_at', '>', now())->oldest()->get()
            ->map->only(['id', 'uuid', 'type', 'payload', 'signature', 'expires_at', 'status']);

        Log::info('Device heartbeat completed successfully.', $context + [
            'policy_version' => $signedPolicy['payload']['policy_version'],
            'offline_deadline_at' => $signedPolicy['payload']['offline_deadline_at'],
            'signature_bytes' => strlen(base64_decode($signedPolicy['signature'], true) ?: ''),
            'last_sync_at' => $freshDevice->last_sync_at?->toIso8601String(),
        ]);

        return response()->json(['data' => [
            'server_utc_time' => now('UTC')->toIso8601String(),
            'status' => $freshDevice->status,
            'lock_status' => $freshDevice->lock_status,
            'commands' => $commands,
            'offline_policy' => $signedPolicy,
        ]]);
    }

    public function capabilities(Request $request)
    {
        $data = $request->validate([
            'management_mode' => ['required', 'in:standard,managed'],
            'is_device_owner' => ['required', 'boolean'],
            'is_admin_active' => ['required', 'boolean'],
            'can_block_uninstall' => ['required', 'boolean'],
            'can_block_reset' => ['required', 'boolean'],
            'can_full_lock' => ['required', 'boolean'],
            'can_use_lock_task' => ['required', 'boolean'],
            'is_lock_task_permitted' => ['required', 'boolean'],
            'location_permission_status' => ['required', 'string', 'max:30'],
            'background_location_permission_status' => ['required', 'string', 'max:30'],
            'notification_permission_status' => ['required', 'string', 'max:30'],
            'fcm_token' => ['nullable', 'string', 'max:4096'],
        ]);
        $device = $request->attributes->get('device');
        
        $updateData = $data + ['management_status' => $data['is_device_owner'] && $data['is_admin_active'] ? 'active' : 'setup_required', 'last_sync_at' => now()];
        if (isset($data['fcm_token'])) {
            $updateData['fcm_token'] = $data['fcm_token'];
        }
        $device->update($updateData);

        return response()->json(['message' => 'Capabilities updated.']);
    }
}
