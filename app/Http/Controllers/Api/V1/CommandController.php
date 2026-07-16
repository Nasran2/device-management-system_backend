<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceCommand;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CommandController extends Controller
{
    public function index(Request $request)
    {
        $device = $request->attributes->get('device');
        $device->commands()->whereIn('status', ['pending','dispatched','delivered','executing'])->where('expires_at', '<=', now())->update(['status'=>'failed','failure_code'=>'COMMAND_EXPIRED','result_message'=>'Command expired before execution.','executed_at'=>now()]);
        $commands = $device->commands()->whereIn('status', ['pending', 'dispatched', 'delivered'])->where('expires_at', '>', now())->oldest()->get();
        Log::info('Pending commands requested', ['device_uuid' => $device->uuid, 'command_count' => $commands->count()]);

        return response()->json(['data' => $commands]);
    }

    public function acknowledge(Request $request, DeviceCommand $command)
    {
        $device = $request->attributes->get('device');
        abort_unless($command->device_id === $device->id, 404);
        $command->update(['status' => 'delivered', 'delivered_at' => now()]);

        return response()->json(['message' => 'Delivery acknowledged.']);
    }

    public function executing(Request $request, DeviceCommand $command)
    {
        $device = $request->attributes->get('device');
        abort_unless($command->device_id === $device->id, 404);
        if (! in_array($command->status, ['completed','failed'], true)) $command->update(['status'=>'executing','executing_at'=>now()]);
        return response()->json(['message'=>'Execution acknowledged.']);
    }

    public function result(Request $request, DeviceCommand $command, AuditService $audit)
    {
        $device = $request->attributes->get('device');
        abort_unless($command->device_id === $device->id, 404);
        $data = $request->validate([
            'success' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:1000'],
            'failure_code' => ['nullable', 'in:NOT_DEVICE_OWNER,ADMIN_NOT_ACTIVE,LOCK_TASK_NOT_PERMITTED,INVALID_SIGNATURE,COMMAND_EXPIRED,DUPLICATE_COMMAND,NETWORK_ERROR,LOCATION_PERMISSION_DENIED,GPS_DISABLED,DEVICE_RELEASED,UNKNOWN_ERROR'],
        ]);
        if (in_array($command->status, ['completed', 'failed'], true)) {
            return response()->json(['message' => 'Result already recorded.'], 409);
        }
        $command->update(['status' => $data['success'] ? 'completed' : 'failed', 'result_message' => $data['message'] ?? null, 'failure_code' => $data['failure_code'] ?? null, 'executed_at' => now()]);
        if ($data['success']) {
            $updates = match ($command->type) {
                'LOCK_DEVICE' => ['status' => 'locked', 'lock_status' => 'locked'],
                'UNLOCK_DEVICE' => ['status' => 'active_unlocked', 'lock_status' => 'unlocked'],
                'ENABLE_TRACKING' => ['location_tracking_enabled' => true],
                'DISABLE_TRACKING' => ['location_tracking_enabled' => false, 'tracking_mode' => 'disabled'],
                'PERMANENT_RELEASE' => ['status' => 'permanently_released', 'lock_status' => 'unlocked', 'released_at' => now(), 'release_reason' => $command->payload['reason'] ?? null, 'location_tracking_enabled' => false, 'tracking_mode' => 'disabled'],
                default => [],
            };
            $device->update($updates + ['last_seen_at' => now()]);
            if ($command->type === 'PERMANENT_RELEASE') {
                $device->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $device->commands()->where('id', '!=', $command->id)->whereIn('status', ['pending', 'dispatched', 'delivered', 'executing'])->update(['status' => 'cancelled']);
            }
        } else {
            $device->update([
                'status' => $command->previous_device_status ?: ($device->lock_status === 'locked' ? 'locked' : 'active_unlocked'),
                'lock_status' => $command->previous_lock_status ?: $device->lock_status,
                'management_status' => ($data['failure_code'] ?? null) === 'NOT_DEVICE_OWNER' ? 'setup_required' : $device->management_status,
                'last_seen_at' => now(),
            ]);
        }
        Log::info('Command execution result uploaded', ['command_uuid' => $command->uuid, 'device_uuid' => $device->uuid, 'success' => $data['success'], 'failure_code' => $data['failure_code'] ?? null]);
        $audit->record($data['success'] ? 'command_completed' : 'command_failed', $data['message'] ?? $command->type, null, $device, [], ['command_uuid' => $command->uuid]);

        return response()->json(['message' => 'Command result recorded.']);
    }
}
