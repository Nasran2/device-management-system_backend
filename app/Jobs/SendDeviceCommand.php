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
        if (! $command || $command->expires_at->isPast() || ! in_array($command->status, ['pending', 'dispatched'], true)) {
            return;
        }
        $command->update(['firebase_attempted_at' => now(), 'firebase_error' => null]);
        Log::info('Firebase dispatch attempted', ['command_uuid' => $command->uuid, 'device_uuid' => $command->device->uuid]);
        try {
            $messageId = $firebase->send($command);
            $command->update(['status' => 'dispatched', 'dispatched_at' => now(), 'firebase_message_id' => $messageId, 'firebase_error' => null]);
        } catch (\Throwable $error) {
            $command->update(['status' => 'pending', 'firebase_error' => $error->getMessage(), 'result_message' => 'Firebase delivery failed; phone polling will retry delivery.', 'retry_count' => $command->retry_count + 1]);
            Log::warning('Firebase dispatch failed; command retained for polling', ['command_uuid' => $command->uuid, 'device_uuid' => $command->device->uuid, 'exception' => $error->getMessage()]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $command = DeviceCommand::with('device')->find($this->commandId);
        $command?->update(['status' => 'pending', 'firebase_error' => $exception?->getMessage(), 'result_message' => 'Firebase delivery failed; awaiting API polling.', 'retry_count' => $this->tries, 'firebase_attempted_at' => now()]);
        if ($command) {
            Log::warning('Firebase dispatch failed; command retained for polling', ['command_uuid' => $command->uuid, 'device_uuid' => $command->device->uuid, 'exception' => $exception?->getMessage()]);
        }
    }
}
