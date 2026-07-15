<?php

namespace App\Services;

use App\Jobs\SendDeviceCommand;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommandService
{
    private const ALLOWED = ['LOCK_DEVICE', 'UNLOCK_DEVICE', 'REQUEST_LOCATION', 'ENABLE_TRACKING', 'DISABLE_TRACKING', 'UPDATE_TRACKING_INTERVAL', 'UPDATE_LOCK_MESSAGE', 'SYNC_DEVICE', 'PERMANENT_RELEASE'];

    public function __construct(private AuditService $audit) {}

    public function create(Device $device, string $type, array $payload, User $requester): DeviceCommand
    {
        if (! in_array($type, self::ALLOWED, true)) {
            throw ValidationException::withMessages(['type' => 'Unsupported command type.']);
        }
        if ($device->isReleased()) {
            throw ValidationException::withMessages(['device' => 'Released devices cannot receive commands.']);
        }

        $command = DB::transaction(function () use ($device, $type, $payload, $requester) {
            $uuid = (string) Str::uuid();
            $expiresAt = now()->addMinutes((int) config('device.command_expiry_minutes', 15))->startOfSecond();
            $canonical = $uuid.'|'.$device->uuid.'|'.$type.'|'.json_encode($payload, JSON_UNESCAPED_SLASHES).'|'.$expiresAt->toISOString();
            $command = $device->commands()->create([
                'uuid' => $uuid,
                'requested_by' => $requester->id,
                'type' => $type,
                'payload' => $payload,
                'signature' => hash_hmac('sha256', $canonical, $this->deviceKey($device)),
                'expires_at' => $expiresAt,
                'previous_device_status' => $device->status,
                'previous_lock_status' => $device->lock_status,
            ]);

            if ($type === 'LOCK_DEVICE') {
                $device->update(['status' => 'lock_requested']);
            }
            if ($type === 'UNLOCK_DEVICE') {
                $device->update(['status' => 'unlock_requested']);
            }
            $this->audit->record(strtolower($type).'_requested', "{$type} requested", $requester, $device, [], ['command_uuid' => $uuid]);
            Log::info('Device command created', ['command_uuid' => $uuid, 'device_uuid' => $device->uuid, 'command_type' => $type]);

            return $command;
        });
        SendDeviceCommand::dispatch($command->id)->afterCommit();

        return $command->fresh();
    }

    public function verify(DeviceCommand $command): bool
    {
        $canonical = $command->uuid.'|'.$command->device->uuid.'|'.$command->type.'|'.json_encode($command->payload ?? [], JSON_UNESCAPED_SLASHES).'|'.$command->expires_at->toISOString();

        return $command->expires_at->isFuture() && hash_equals($command->signature, hash_hmac('sha256', $canonical, $this->deviceKey($command->device)));
    }

    private function deviceKey(Device $device): string
    {
        return hash_hmac('sha256', $device->uuid, config('device.command_signing_key'));
    }
}
