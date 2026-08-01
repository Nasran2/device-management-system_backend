<?php

use App\Models\DeviceActivation;
use App\Models\DeviceCommand;
use App\Models\DeviceLocation;
use App\Models\SystemSetting;
use App\Services\ActivationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('deviceguard:replace-legacy-activation-codes', function () {
    $replaced = 0;
    $service = app(ActivationService::class);

    DeviceActivation::query()
        ->with('device')
        ->whereNull('used_at')
        ->whereNull('revoked_at')
        ->where('expires_at', '>', now())
        ->orderBy('id')
        ->chunkById(200, function ($activations) use ($service, &$replaced) {
            foreach ($activations as $activation) {
                if ($service->replaceActiveLegacyNumericCode($activation->device)) {
                    $replaced++;
                }
            }
        });

    $this->info("Legacy activation codes replaced: {$replaced}");
})->purpose('Replace active unused numeric activation codes with installed-app-compatible codes');

Schedule::call(function () {
    \App\Models\InstallmentSchedule::whereIn('status', ['upcoming', 'due_today', 'partially_paid'])
        ->whereDate('due_date', '<', today())
        ->where('remaining_amount', '>', 0)
        ->update(['status' => 'overdue']);
    \App\Models\InstallmentSchedule::where('status', 'upcoming')
        ->whereDate('due_date', today())
        ->update(['status' => 'due_today']);
})->dailyAt('00:05')->name('refresh-installment-statuses')->withoutOverlapping();

Schedule::call(function () {
    \App\Models\InstallmentSchedule::with(['financing.customer','financing.device.shop'])
        ->whereIn('status',['upcoming','due_today','partially_paid','overdue'])
        ->whereDate('due_date','<=',now()->addDays(3))
        ->chunkById(200,function($rows){
            $sms=app(\App\Services\SmsService::class);
            foreach($rows as $installment){
                $device=$installment->financing->device;$shop=$device->shop;$customer=$installment->financing->customer;
                if(!$shop?->sms_enabled||!$shop->reminders_enabled)continue;
                $event=$installment->due_date->isToday()?'installment_due_today':($installment->due_date->isPast()?'payment_overdue':($installment->due_date->isTomorrow()?'installment_due_tomorrow':'installment_due_3_days'));
                if(\App\Models\SmsLog::where('device_id',$device->id)->where('template',$event)->whereDate('created_at',today())->exists())continue;
                $sms->send($event,$customer->phone,['customer_name'=>$customer->name,'shop_name'=>$shop->name,'next_payment_amount'=>number_format($installment->remaining_amount,2),'next_due_date'=>$installment->due_date->format('d M Y'),'next_payment_date'=>$installment->due_date->format('d M Y'),'phone_brand'=>$device->brand,'phone_model'=>$device->model,'device_reference'=>strtoupper(substr($device->uuid,0,8)),'support_number'=>$device->support_phone],$shop->id,$customer->id,$device->id);
            }
        });
})->dailyAt('08:00')->name('installment-reminders')->withoutOverlapping();

Schedule::call(function () {
    $mode=\App\Models\SystemSetting::value('new_device_notification_mode','disabled');
    if(!in_array($mode,['daily','both'],true))return;
    if(\App\Models\SmsLog::where('template','new_device_daily_summary')->whereDate('created_at',today())->exists())return;
    $devices=\App\Models\Device::whereDate('created_at',today())->with('commission')->get();
    if($devices->isEmpty())return;
    $sms=app(\App\Services\SmsService::class);
    foreach(array_filter(array_map('trim',explode(',',(string)\App\Models\SystemSetting::value('new_device_sms_recipients','')))) as $recipient){
        $sms->send('new_device_daily_summary',$recipient,['device_count'=>$devices->count(),'shop_count'=>$devices->pluck('shop_id')->filter()->unique()->count(),'commission'=>number_format($devices->sum(fn($d)=>(float)($d->commission?->commission_amount??0)),2)]);
    }
})->dailyAt('18:00')->name('new-device-daily-summary')->withoutOverlapping();

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
