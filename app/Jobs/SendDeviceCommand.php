<?php

namespace App\Jobs;

use App\Models\DeviceCommand;
use App\Services\FirebaseMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendDeviceCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $commandId) {}

    public function handle(FirebaseMessagingService $firebase): void
    {
        $command = DeviceCommand::with('device')->find($this->commandId);
        if (! $command || $command->expires_at->isPast() || ! in_array($command->status, ['pending', 'queued'], true)) {
            return;
        }
        $command->update(['status' => 'queued']);
        Log::info('Firebase dispatch attempted', ['command_uuid' => $command->uuid, 'device_uuid' => $command->device->uuid]);
        if (! $firebase->configured() || blank($command->device->fcm_token)) {
            return;
        }
        $firebase->send($command);
        $command->update(['status' => 'sent']);
    }

    public function failed(?\Throwable $exception): void
    {
        $command = DeviceCommand::with('device')->find($this->commandId);
        $command?->update(['status' => 'queued', 'result_message' => 'Firebase delivery failed; awaiting API polling.', 'retry_count' => $this->tries]);
        if ($command) {
            Log::warning('Firebase dispatch failed; command retained for polling', ['command_uuid' => $command->uuid, 'device_uuid' => $command->device->uuid, 'exception' => $exception?->getMessage()]);
        }
    }
}
