<?php

use App\Models\DeviceCommand;
use App\Models\DeviceLocation;
use App\Models\SystemSetting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    $days = SystemSetting::value('location_retention_days', 30);
    if ($days > 0) {
        DeviceLocation::where('recorded_at', '<', now()->subDays($days))->delete();
    }
})->daily()->name('prune-device-locations')->withoutOverlapping();

Schedule::call(function () {
    DeviceCommand::with('device')->whereIn('status', ['pending', 'dispatched', 'delivered', 'executing'])->where('expires_at', '<=', now())->each(function ($command) {
        $command->update(['status' => 'failed', 'failure_code' => 'COMMAND_EXPIRED', 'result_message' => 'Command expired before execution.', 'executed_at' => now()]);
        $command->device->update([
            'status' => $command->previous_device_status ?: ($command->device->lock_status === 'locked' ? 'locked' : 'active_unlocked'),
            'lock_status' => $command->previous_lock_status ?: $command->device->lock_status,
        ]);
        Log::warning('Device command expired', ['command_uuid' => $command->uuid, 'device_uuid' => $command->device->uuid]);
    });
})->everyFiveMinutes()->name('expire-device-commands')->withoutOverlapping();
