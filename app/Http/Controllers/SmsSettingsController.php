<?php

namespace App\Http\Controllers;

use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\SystemSetting;
use App\Services\Sms\TextItSmsService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SmsSettingsController extends Controller
{
    private const PLACEHOLDERS = [
        'shop_name', 'customer_name', 'phone_brand', 'phone_model', 'selling_price',
        'first_payment', 'months', 'payment_amount', 'total_paid', 'balance',
        'remaining_balance', 'next_payment_amount', 'next_due_date', 'next_payment_date',
        'commission_percentage', 'commission_amount', 'commission_balance',
        'commission', 'device_reference', 'receipt_number', 'reference', 'support_number',
        'device_count', 'shop_count',
    ];

    private function super(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }

    public function edit(Request $request)
    {
        $this->super($request);
        $mask = null;
        if ($encrypted = SystemSetting::value('sms_api_key_encrypted')) {
            try {
                $plain = Crypt::decryptString($encrypted);
                $mask = '********'.substr($plain, -4);
            } catch (\Throwable) {
                $mask = '********';
            }
        }

        return view('settings.sms', [
            'templates' => SmsTemplate::whereNull('shop_id')->get(),
            'configured' => (bool) $encrypted,
            'apiKeyMask' => $mask,
        ]);
    }

    public function logs(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->shop_id, 403);
        $logs = SmsLog::with('shop')
            ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('shop_id', $request->user()->shop_id))
            ->when($request->status, fn ($query, $status) => $query->where('sent_status', $status))
            ->when($request->search, fn ($query, $term) => $query->where(fn ($inner) => $inner
                ->where('recipient_number', 'like', "%{$term}%")
                ->orWhere('template', 'like', "%{$term}%")))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('settings.sms-logs', compact('logs'));
    }

    public function update(Request $request, AuditService $audit)
    {
        $this->super($request);
        $configured = (bool) SystemSetting::value('sms_api_key_encrypted');
        $data = $request->validate([
            'sms_enabled' => ['nullable', 'boolean'],
            'sms_api_url' => ['required', 'url', 'starts_with:https://'],
            'sms_api_key' => [Rule::requiredIf(! $configured), 'nullable', 'string', 'min:8'],
            'sms_api_version' => ['required', 'string', 'max:20'],
            'sms_sender_id' => ['nullable', 'string', 'max:30'],
            'sms_default_country_code' => ['required', 'digits_between:1,4'],
            'sms_request_timeout' => ['required', 'integer', 'between:1,60'],
            'sms_retry_count' => ['required', 'integer', 'between:0,5'],
            'new_device_sms_recipients' => ['nullable', 'string'],
            'new_device_notification_mode' => ['required', 'in:disabled,immediate,daily,both'],
            'payment_sms_enabled' => ['nullable', 'boolean'],
            'due_reminder_enabled' => ['nullable', 'boolean'],
            'overdue_reminder_enabled' => ['nullable', 'boolean'],
            'settlement_sms_enabled' => ['nullable', 'boolean'],
            'payment_template' => ['required', 'string', 'max:1000'],
            'new_device_template' => ['required', 'string', 'max:1000'],
            'commission_template' => ['required', 'string', 'max:1000'],
            'due_template' => ['required', 'string', 'max:1000'],
            'overdue_template' => ['required', 'string', 'max:1000'],
            'balance_completed_template' => ['required', 'string', 'max:1000'],
            'new_device_daily_template' => ['required', 'string', 'max:1000'],
            'password' => ['required', 'current_password'],
        ]);

        foreach (['payment_template', 'new_device_template', 'commission_template', 'due_template', 'overdue_template', 'balance_completed_template', 'new_device_daily_template'] as $field) {
            $this->validatePlaceholders($field, $data[$field]);
        }

        foreach (['sms_api_url', 'sms_api_version', 'sms_sender_id', 'sms_default_country_code', 'sms_request_timeout', 'sms_retry_count', 'new_device_sms_recipients', 'new_device_notification_mode'] as $key) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => (string) ($data[$key] ?? ''), 'type' => in_array($key, ['sms_request_timeout', 'sms_retry_count'], true) ? 'integer' : 'string']);
        }
        SystemSetting::updateOrCreate(['key' => 'sms_provider'], ['value' => 'textit.biz', 'type' => 'string']);
        foreach (['sms_enabled', 'payment_sms_enabled', 'due_reminder_enabled', 'overdue_reminder_enabled', 'settlement_sms_enabled'] as $flag) {
            SystemSetting::updateOrCreate(['key' => $flag], ['value' => $request->boolean($flag) ? 'true' : 'false', 'type' => 'boolean']);
        }
        SystemSetting::updateOrCreate(['key' => 'new_device_sms_enabled'], ['value' => $data['new_device_notification_mode'] !== 'disabled' ? 'true' : 'false', 'type' => 'boolean']);
        if (filled($data['sms_api_key'])) {
            SystemSetting::updateOrCreate(['key' => 'sms_api_key_encrypted'], ['value' => Crypt::encryptString($data['sms_api_key']), 'type' => 'encrypted']);
        }

        foreach ([
            'payment_received' => $data['payment_template'],
            'new_device' => $data['new_device_template'],
            'platform_settlement' => $data['commission_template'],
            'installment_due_today' => $data['due_template'],
            'payment_overdue' => $data['overdue_template'],
            'balance_completed' => $data['balance_completed_template'],
            'new_device_daily_summary' => $data['new_device_daily_template'],
        ] as $event => $body) {
            SmsTemplate::updateOrCreate(
                ['shop_id' => null, 'event' => $event],
                ['name' => ucwords(str_replace('_', ' ', $event)), 'body' => $body, 'is_global' => true, 'enabled' => true]
            );
        }
        $audit->record('SMS_SETTINGS_UPDATED', 'Textit.biz gateway settings and templates updated', $request->user(), null, [], [
            'provider' => 'textit.biz',
            'endpoint' => $data['sms_api_url'],
            'api_version' => $data['sms_api_version'],
            'api_key_changed' => filled($data['sms_api_key']),
            'sms_enabled' => $request->boolean('sms_enabled'),
            'notification_mode' => $data['new_device_notification_mode'],
        ]);

        return back()->with('success', 'Textit.biz settings, encrypted API key, and templates saved.');
    }

    public function connection(Request $request)
    {
        $this->super($request);
        $request->validate(['password' => ['required', 'current_password']]);
        abort_unless(str_starts_with((string) SystemSetting::value('sms_api_url'), 'https://'), 422, 'A valid HTTPS endpoint is required.');
        abort_unless(SystemSetting::value('sms_api_key_encrypted'), 422, 'The Textit.biz API key is not configured.');

        return back()->with('success', 'Textit.biz configuration is present and the encrypted API key can be loaded. Use Send Test SMS to verify live delivery.');
    }

    public function test(Request $request, TextItSmsService $sms)
    {
        $this->super($request);
        $data = $request->validate([
            'recipient' => ['required', 'string'],
            'message' => ['required', 'string', 'max:500'],
            'password' => ['required', 'current_password'],
        ]);
        $result = $sms->sendTestSms($data['recipient'], $data['message']);
        SmsLog::create([
            'recipient_number' => $result['recipient'] ?? $data['recipient'],
            'template' => 'test',
            'message' => $data['message'],
            'provider' => 'textit.biz',
            'provider_message_id' => $result['message_id'],
            'provider_response' => $result['provider_response'],
            'attempts' => $result['attempts'],
            'sent_status' => $result['success'] ? 'sent' : 'failed',
            'delivery_status' => $result['success'] ? 'submitted' : null,
            'failure_reason' => $result['failure_reason'],
            'sent_by' => $request->user()->id,
            'sent_at' => $result['success'] ? now() : null,
        ]);

        return back()->with($result['success'] ? 'success' : 'warning', $result['success'] ? 'Test SMS submitted to Textit.biz.' : 'Test SMS failed: '.$result['failure_reason']);
    }

    public function retry(Request $request, SmsLog $smsLog, TextItSmsService $sms)
    {
        abort_unless($request->user()->isSuperAdmin() || $smsLog->shop_id === $request->user()->shop_id, 403);
        abort_unless($smsLog->sent_status === 'failed', 422);
        $result = $sms->send($smsLog->recipient_number, (string) $smsLog->message);
        $smsLog->update([
            'sent_status' => $result['success'] ? 'sent' : 'failed',
            'delivery_status' => $result['success'] ? 'submitted' : null,
            'provider_message_id' => $result['message_id'],
            'provider_response' => $result['provider_response'],
            'attempts' => (int) $smsLog->attempts + $result['attempts'],
            'failure_reason' => $result['failure_reason'],
            'sent_at' => $result['success'] ? now() : null,
        ]);

        return back()->with($result['success'] ? 'success' : 'warning', $result['success'] ? 'SMS retry submitted.' : 'SMS retry failed.');
    }

    private function validatePlaceholders(string $field, string $body): void
    {
        preg_match_all('/\{([a-z_]+)\}/', $body, $matches);
        $unknown = array_diff(array_unique($matches[1]), self::PLACEHOLDERS);
        if ($unknown) {
            throw ValidationException::withMessages([$field => 'Unknown placeholders: '.implode(', ', $unknown)]);
        }
    }
}
