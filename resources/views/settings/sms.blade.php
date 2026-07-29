<x-layouts.app title="SMS Gateway Settings">
    <div class="mb-7"><p class="eyebrow">Communication</p><h1 class="page-title">Textit.biz SMS Gateway</h1><p class="page-copy">The API key is encrypted. After saving, only its masked ending is shown.</p></div>

    <form class="panel space-y-6 p-6" method="post" action="{{ route('settings.sms.update') }}">
        @csrf @method('put')
        <section>
            <h2 class="section-title">Gateway</h2>
            <div class="form-grid mt-4">
                <label class="field-label">Provider<input class="field-input bg-slate-100" value="Textit.biz" disabled></label>
                <label class="field-label">SMS enabled<label class="mt-3 flex gap-2 font-normal"><input type="checkbox" name="sms_enabled" value="1" @checked(App\Models\SystemSetting::value('sms_enabled',true))> Enable outbound SMS</label></label>
                <label class="field-label">API endpoint <x-required/><input class="field-input" type="url" name="sms_api_url" value="{{ old('sms_api_url',App\Models\SystemSetting::value('sms_api_url','https://api.textit.biz')) }}" required></label>
                <label class="field-label">API version <x-required/><input class="field-input" name="sms_api_version" value="{{ old('sms_api_version',App\Models\SystemSetting::value('sms_api_version','v1')) }}" required></label>
                <label class="field-label">API key {{ $configured?'(leave blank to retain)':'' }}<input class="field-input" type="password" name="sms_api_key" autocomplete="new-password" @required(!$configured)>@if($apiKeyMask)<span class="mt-1 block text-xs text-slate-500">Saved key: {{ $apiKeyMask }}</span>@endif</label>
                <label class="field-label">Sender ID<input class="field-input" name="sms_sender_id" value="{{ old('sms_sender_id',App\Models\SystemSetting::value('sms_sender_id')) }}"></label>
                <label class="field-label">Default country code <x-required/><input class="field-input" name="sms_default_country_code" value="{{ old('sms_default_country_code',App\Models\SystemSetting::value('sms_default_country_code','94')) }}" required></label>
                <label class="field-label">Request timeout (seconds) <x-required/><input class="field-input" type="number" min="1" max="60" name="sms_request_timeout" value="{{ old('sms_request_timeout',App\Models\SystemSetting::value('sms_request_timeout',20)) }}" required></label>
                <label class="field-label">Retry count <x-required/><input class="field-input" type="number" min="0" max="5" name="sms_retry_count" value="{{ old('sms_retry_count',App\Models\SystemSetting::value('sms_retry_count',2)) }}" required></label>
                <label class="field-label">Super Admin notification numbers<input class="field-input" name="new_device_sms_recipients" value="{{ old('new_device_sms_recipients',App\Models\SystemSetting::value('new_device_sms_recipients')) }}" placeholder="0771234567, +94711234567"></label>
                <label class="field-label">New-device notification mode<select class="field-input" name="new_device_notification_mode">@foreach(['disabled','immediate','daily','both'] as $mode)<option value="{{ $mode }}" @selected(App\Models\SystemSetting::value('new_device_notification_mode','disabled')===$mode)>{{ ucfirst($mode) }}</option>@endforeach</select></label>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <label><input type="checkbox" name="payment_sms_enabled" value="1" @checked(App\Models\SystemSetting::value('payment_sms_enabled',true))> Payment SMS</label>
                <label><input type="checkbox" name="due_reminder_enabled" value="1" @checked(App\Models\SystemSetting::value('due_reminder_enabled',true))> Due reminders</label>
                <label><input type="checkbox" name="overdue_reminder_enabled" value="1" @checked(App\Models\SystemSetting::value('overdue_reminder_enabled',true))> Overdue reminders</label>
                <label><input type="checkbox" name="settlement_sms_enabled" value="1" @checked(App\Models\SystemSetting::value('settlement_sms_enabled',true))> Commission-payment SMS</label>
            </div>
        </section>

        <section id="sms-templates" class="scroll-mt-6 border-t pt-6">
            <h2 class="section-title">Editable templates</h2>
            <p class="section-copy">Unknown placeholders are rejected. Supported values include shop, customer, phone, payment, balance, commission, device reference, receipt, and support fields.</p>
            <div class="mt-4 space-y-4">
                <label class="field-label">Payment received <x-required/><textarea class="field-input" name="payment_template" required>{{ old('payment_template',$templates->firstWhere('event','payment_received')?->body??'Payment received: LKR {payment_amount}. Total paid: LKR {total_paid}. Balance: LKR {balance}. Next payment: {next_payment_amount} on {next_due_date}. Thank you - {shop_name}.') }}</textarea></label>
                <label class="field-label">New device to Super Admin <x-required/><textarea class="field-input" name="new_device_template" required>{{ old('new_device_template',$templates->firstWhere('event','new_device')?->body??'New device added. Shop: {shop_name}, Customer: {customer_name}, Phone: {phone_brand} {phone_model}, Price: LKR {selling_price}, Commission: LKR {commission_amount}, Ref: {device_reference}.') }}</textarea></label>
                <label class="field-label">Commission payment <x-required/><textarea class="field-input" name="commission_template" required>{{ old('commission_template',$templates->firstWhere('event','platform_settlement')?->body??'Commission payment received from {shop_name}. Amount: LKR {payment_amount}. Remaining balance: LKR {commission_balance}. Ref: {reference}.') }}</textarea></label>
                <label class="field-label">Due reminder <x-required/><textarea class="field-input" name="due_template" required>{{ old('due_template',$templates->firstWhere('event','installment_due_today')?->body??'Payment due today: LKR {next_payment_amount} for {phone_model}. Contact {shop_name}: {support_number}.') }}</textarea></label>
                <label class="field-label">Overdue reminder <x-required/><textarea class="field-input" name="overdue_template" required>{{ old('overdue_template',$templates->firstWhere('event','payment_overdue')?->body??'Overdue payment: LKR {next_payment_amount} for {phone_model} was due on {next_due_date}. Contact {shop_name}.') }}</textarea></label>
                <label class="field-label">Balance completed <x-required/><textarea class="field-input" name="balance_completed_template" required>{{ old('balance_completed_template',$templates->firstWhere('event','balance_completed')?->body??'Balance completed for {phone_model}. Total paid: LKR {total_paid}. Thank you - {shop_name}.') }}</textarea></label>
                <label class="field-label">New-device daily summary <x-required/><textarea class="field-input" name="new_device_daily_template" required>{{ old('new_device_daily_template',$templates->firstWhere('event','new_device_daily_summary')?->body??'DeviceGuard daily summary: {device_count} devices from {shop_count} shops. Commission: LKR {commission}.') }}</textarea></label>
            </div>
        </section>
        <label class="field-label">Super Admin password <x-required/><input class="field-input" type="password" name="password" autocomplete="current-password" required></label>
        <button class="primary-button">Save Settings</button>
    </form>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <form class="panel space-y-4 p-6" method="post" action="{{ route('settings.sms.connection') }}">@csrf<h2 class="section-title">Test Connection</h2><p class="section-copy">Checks the saved HTTPS endpoint and encrypted credential configuration.</p><label class="field-label">Super Admin password <x-required/><input class="field-input" type="password" name="password" required></label><button class="secondary-button">Test Connection</button></form>
        <form class="panel space-y-4 p-6" method="post" action="{{ route('settings.sms.test') }}">@csrf<h2 class="section-title">Send Test SMS</h2><label class="field-label">Recipient <x-required/><input class="field-input" name="recipient" placeholder="0771234567" required></label><label class="field-label">Message <x-required/><textarea class="field-input" name="message" required>DeviceGuard Textit.biz test message.</textarea></label><label class="field-label">Super Admin password <x-required/><input class="field-input" type="password" name="password" required></label><button class="secondary-button">Send Test SMS</button></form>
    </div>
</x-layouts.app>
