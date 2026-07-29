<?php

namespace App\Services\Sms;

use App\Models\SystemSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class TextItSmsService
{
    public function normalizeNumber(string $number): ?string
    {
        $number = preg_replace('/[^\d+]/', '', trim($number));
        if (str_starts_with($number, '+')) {
            $number = substr($number, 1);
        }
        $country = preg_replace('/\D/', '', (string) SystemSetting::value('sms_default_country_code', '94'));
        if (str_starts_with($number, '0')) {
            $number = $country.substr($number, 1);
        } elseif (! str_starts_with($number, $country) && strlen($number) === 9) {
            $number = $country.$number;
        }

        return preg_match('/^94\d{9}$/', $number) ? $number : null;
    }

    public function send(string $recipient, string $message): array
    {
        $to = $this->normalizeNumber($recipient);
        if (! $to) {
            return $this->failure('invalid_recipient', 'The recipient is not a valid Sri Lankan mobile number.');
        }
        if (! SystemSetting::value('sms_enabled', true)) {
            return $this->failure('disabled', 'SMS delivery is disabled.');
        }

        $endpoint = rtrim((string) SystemSetting::value('sms_api_url', 'https://api.textit.biz'), '/');
        $version = (string) SystemSetting::value('sms_api_version', 'v1');
        $timeout = (int) SystemSetting::value('sms_request_timeout', 20);
        $retries = (int) SystemSetting::value('sms_retry_count', 2);
        $encrypted = SystemSetting::value('sms_api_key_encrypted');
        if (! $encrypted) {
            return $this->failure('not_configured', 'Textit.biz API key is not configured.');
        }

        try {
            $key = Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return $this->failure('invalid_configuration', 'The encrypted Textit.biz API key could not be read.');
        }

        $attempts = 0;
        do {
            $attempts++;
            try {
                $response = Http::timeout($timeout)->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => '*/*',
                    'X-API-VERSION' => $version,
                    'Authorization' => 'Basic '.$key,
                ])->post($endpoint, ['to' => $to, 'text' => $message]);

                $safeResponse = substr($response->body(), 0, 4000);
                if ($response->successful()) {
                    return [
                        'success' => true,
                        'status' => 'sent',
                        'recipient' => $to,
                        'message_id' => (string) ($response->json('message_id') ?? $response->json('id') ?? ''),
                        'provider_response' => $safeResponse,
                        'failure_reason' => null,
                        'attempts' => $attempts,
                    ];
                }
                $reason = match ($response->status()) {
                    401, 403 => 'Textit.biz rejected the API key.',
                    402 => 'The Textit.biz account has insufficient balance.',
                    422 => 'Textit.biz rejected the recipient or message.',
                    429 => 'Textit.biz rate limit reached.',
                    default => "Textit.biz returned HTTP {$response->status()}.",
                };
                if ($response->status() < 500 && $response->status() !== 429) {
                    return $this->failure('provider_error', $reason, $to, $safeResponse, $attempts);
                }
            } catch (ConnectionException $exception) {
                $reason = 'Textit.biz timed out or could not be reached: '.$exception->getMessage();
            } catch (\Throwable $exception) {
                return $this->failure('unexpected_response', $exception->getMessage(), $to, null, $attempts);
            }
        } while ($attempts <= $retries);

        return $this->failure('network_failure', $reason ?? 'Textit.biz could not be reached.', $to, null, $attempts);
    }

    public function sendPaymentReceived(string $to, string $message): array { return $this->send($to, $message); }
    public function sendNewDeviceNotification(string $to, string $message): array { return $this->send($to, $message); }
    public function sendDueReminder(string $to, string $message): array { return $this->send($to, $message); }
    public function sendOverdueReminder(string $to, string $message): array { return $this->send($to, $message); }
    public function sendCommissionPaymentConfirmation(string $to, string $message): array { return $this->send($to, $message); }
    public function sendTestSms(string $to, string $message): array { return $this->send($to, $message); }

    private function failure(string $status, string $reason, ?string $recipient = null, ?string $response = null, int $attempts = 0): array
    {
        return ['success' => false, 'status' => $status, 'recipient' => $recipient, 'message_id' => null, 'provider_response' => $response, 'failure_reason' => substr($reason, 0, 1000), 'attempts' => $attempts];
    }
}
