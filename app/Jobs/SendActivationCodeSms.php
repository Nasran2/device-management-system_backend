<?php

namespace App\Jobs;

use App\Models\DeviceActivation;
use App\Models\User;
use App\Services\ActivationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendActivationCodeSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $activationId, public ?int $requestedById = null) {}

    public function handle(ActivationService $activations): void
    {
        $activation = DeviceActivation::with(['device.customer', 'device.shop'])->find($this->activationId);
        if (! $activation || ! $activation->isUsable()) {
            return;
        }

        $plain = $activations->plainCode($activation);
        if (! $plain) {
            return;
        }

        $activations->sendSmsIfEnabled(
            $activation->device,
            $plain,
            $this->requestedById ? User::find($this->requestedById) : null,
        );
    }

    public function failed(?\Throwable $exception): void
    {
        try {
            Log::error('Queued activation-code SMS failed.', [
                'activation_id' => $this->activationId,
                'requested_by_id' => $this->requestedById,
                'exception_class' => $exception ? get_class($exception) : null,
                'exception_message' => $exception ? mb_substr($exception->getMessage(), 0, 1000) : null,
            ]);
        } catch (\Throwable) {
            // Queue failure reporting must not expose or rethrow sensitive data.
        }
    }
}
