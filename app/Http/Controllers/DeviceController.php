<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeviceRequest;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceAccessCode;
use App\Models\OfflineProtectionSetting;
use App\Models\PhoneBrand;
use App\Models\SystemSetting;
use App\Services\ActivationService;
use App\Services\AuditService;
use App\Services\CommandService;
use App\Services\CommissionService;
use App\Services\DeviceGuardApkSettings;
use App\Services\FinancingService;
use App\Services\OfflineProtectionService;
use App\Services\QrProvisioningService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $devices = Device::visibleTo($request->user())->with(['customer', 'admin'])
            ->when($request->search, fn ($q, $term) => $q->where(fn ($q) => $q->where('brand', 'like', "%{$term}%")->orWhere('model', 'like', "%{$term}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%"))))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->offline_filter, function ($q, $filter) {
                match ($filter) {
                    'offline_24h' => $q->where('last_seen_at', '<', now()->subDay()),
                    'deadline_24h' => $q->whereHas('offlinePolicy', fn ($p) => $p->whereBetween('offline_deadline_at', [now(), now()->addDay()])),
                    'deadline_6h' => $q->whereHas('offlinePolicy', fn ($p) => $p->whereBetween('offline_deadline_at', [now(), now()->addHours(6)])),
                    'offline_locked' => $q->whereHas('offlinePolicy', fn ($p) => $p->where('phone_local_locked', true)),
                    'disabled' => $q->whereHas('offlinePolicy', fn ($p) => $p->where('enabled', false)),
                    'global' => $q->whereHas('offlinePolicy', fn ($p) => $p->where('uses_global_default', true)),
                    'override' => $q->whereHas('offlinePolicy', fn ($p) => $p->where('uses_global_default', false)),
                    default => $q,
                };
            })->latest()->paginate(15)->withQueryString();

        return view('devices.index', compact('devices'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->canShop('devices'), 403);
        abort_if($request->user()->shop && $request->user()->shop->status !== 'active', 403, 'This shop account is inactive.');
        abort_if($request->user()->shop && ! $request->user()->shop->device_registration_enabled, 403, 'Device registration is disabled for this shop.');

        return view('devices.create', [
            'customers' => Customer::query()->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('shop_id', $request->user()->shop_id))->orderBy('name')->get(),
            'brands' => PhoneBrand::where('active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(StoreDeviceRequest $request, ActivationService $activations, AuditService $audit, OfflineProtectionService $offline, FinancingService $financing, CommissionService $commissions, SmsService $sms)
    {
        [$device, $code] = DB::transaction(function () use ($request, $activations, $audit, $offline, $financing, $commissions) {
            $customer = $request->customer_id
                ? Customer::query()->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('shop_id', $request->user()->shop_id))->findOrFail($request->customer_id)
                : Customer::create(['shop_id' => $request->user()->shop_id, 'admin_id' => $request->user()->id, 'created_by' => $request->user()->id, 'name' => $request->customer_name, 'phone' => $request->customer_phone, 'address' => $request->customer_address]);
            $data = $request->safe()->except(['customer_id', 'customer_name', 'customer_phone', 'customer_address', 'management_pin', 'management_pin_confirmation', 'first_payment', 'number_of_installments', 'first_due_date', 'payment_frequency', 'custom_frequency_days', 'installment_amount', 'custom_commission_amount']);
            $data['admin_id'] = $request->user()->id;
            $data['shop_id'] = $request->user()->shop_id;
            $data['customer_id'] = $customer->id;
            $data['location_tracking_enabled'] = $request->boolean('location_tracking_enabled');
            $data['tracking_mode'] = $data['location_tracking_enabled'] ? 'locked_only' : 'disabled';
            $data['management_pin_hash'] = Hash::make($request->management_pin);
            $data['management_pin_encrypted'] = Crypt::encryptString($request->management_pin);
            $data['management_pin_changed_at'] = now();
            $data['management_pin_changed_by'] = $request->user()->id;
            $device = Device::create($data);
            $policy = $offline->policyFor($device);
            $offline->audit($device, 'POLICY_CREATED', $policy, $request->user(), ['source' => 'global_default']);
            if ($request->filled('first_payment')) {
                $finance = $financing->create($device, $request->only(['selling_price', 'first_payment', 'number_of_installments', 'first_due_date', 'payment_frequency', 'custom_frequency_days', 'installment_amount']));
                if ($request->user()->shop) {
                    $commissions->snapshot($device, $request->user()->shop, (float) $finance->financed_balance, $request->custom_commission_amount);
                }
                $audit->record('INSTALLMENT_SCHEDULE_CREATED', 'Financing and installment schedule created', $request->user(), $device);
                $audit->record('COMMISSION_GENERATED', 'Device commission snapshot created', $request->user(), $device);
            }
            $code = $activations->issue($device);
            $audit->record('device_registered', 'Device registered', $request->user(), $device);
            $audit->record('MANAGEMENT_PIN_CREATED', 'Device management PIN created', $request->user(), $device);

            return [$device, $code];
        });
        if ($device->shop_id && $request->user()->shop?->sms_enabled && in_array(SystemSetting::value('new_device_notification_mode', 'disabled'), ['immediate', 'both'], true)) {
            $finance = $device->financing;
            $commission = $device->commission;
            foreach (array_filter(array_map('trim', explode(',', (string) SystemSetting::value('new_device_sms_recipients', '')))) as $recipient) {
                $sms->send('new_device', $recipient, ['shop_name' => $device->shop->name, 'customer_name' => $device->customer->name, 'phone_brand' => $device->brand, 'phone_model' => $device->model, 'selling_price' => number_format((float) $device->selling_price, 2), 'first_payment' => number_format((float) $finance?->first_payment, 2), 'months' => $finance?->number_of_installments, 'commission' => number_format((float) $commission?->commission_amount, 2), 'commission_amount' => number_format((float) $commission?->commission_amount, 2), 'commission_percentage' => $commission?->captured_percentage, 'device_reference' => strtoupper(substr($device->uuid, 0, 8))], $device->shop_id, $device->customer_id, $device->id, $request->user());
            }
        }

        return redirect()->route('devices.show', $device)->with('success', 'Device registered.')->with('activation_code', $code);
    }

    public function show(Device $device, QrProvisioningService $qr, OfflineProtectionService $offline)
    {
        $this->authorize('view', $device);

        return view('devices.show', [
            'device' => $device->load(['customer', 'admin', 'shop', 'managementPinChangedBy', 'commands.requester', 'locations', 'offlinePolicy.audits', 'financing.installments', 'commission', 'setupSessions.steps']),
            'offlinePolicy' => $offline->policyFor($device),
            'offlineGlobal' => OfflineProtectionSetting::current(),
            'qrConfigured' => $qr->configured(),
            'qrReadiness' => [
                'QR provisioning enabled' => (bool) SystemSetting::value('qr_provisioning_enabled', false),
                'Production API URL configured' => filled(SystemSetting::value('provisioning_api_url')),
                'Public APK HTTPS URL configured' => filled(SystemSetting::value('provisioning_apk_url')),
                'APK signing checksum configured' => filled(app(DeviceGuardApkSettings::class)->signatureChecksum()),
                'Management PIN configured' => filled($device->management_pin_hash),
            ],
            'queueDiagnostics' => [
                'connection' => config('queue.default'),
                'pending_jobs' => DB::table('jobs')->count(),
                'last_firebase_result' => $device->commands()->whereNotNull('firebase_attempted_at')->latest('firebase_attempted_at')->first(),
            ],
        ]);
    }

    public function edit(Device $device)
    {
        $this->authorize('update', $device);

        return view('devices.edit', ['device' => $device->load('customer')]);
    }

    public function update(Request $request, Device $device, AuditService $audit)
    {
        $this->authorize('update', $device);
        $data = $request->validate(['brand' => ['required', 'string', 'max:80'], 'model' => ['required', 'string', 'max:120'], 'selling_price' => ['required', 'numeric', 'min:0'], 'currency' => ['required', 'string', 'size:3'], 'shop_branch' => ['nullable', 'string', 'max:120'], 'support_phone' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $before = $device->only(array_keys($data));
        $device->update($data);
        $audit->record('DEVICE_UPDATED', 'Device record updated', $request->user(), $device, $before, $data);

        return redirect()->route('devices.show', $device)->with('success', 'Device updated.');
    }

    public function command(Request $request, Device $device, CommandService $commands)
    {
        $this->authorize('control', $device);
        $sensitive = $request->input('type') !== 'SYNC_DEVICE';
        $data = $request->validate([
            'type' => ['required', 'in:LOCK_DEVICE,UNLOCK_DEVICE,REQUEST_LOCATION,ENABLE_TRACKING,DISABLE_TRACKING,SYNC_DEVICE'],
            'password' => [Rule::requiredIf($sensitive), $sensitive ? 'current_password' : 'nullable'],
            'reason' => [$sensitive ? 'required' : 'nullable', 'string', 'max:500'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['type'] === 'LOCK_DEVICE' && ! $device->can_full_lock) {
            return back()->withErrors(['type' => 'This device is registered in Standard Mode. Full lock and uninstall protection require Device Owner provisioning.']);
        }
        $commands->create($device, $data['type'], array_filter(['reason' => $data['reason'] ?? null, 'message' => $data['message'] ?? null, 'support_phone' => $device->support_phone]), $request->user());

        return back()->with('success', 'Command queued. The status will change only after the phone confirms execution.');
    }

    public function release(Request $request, Device $device, CommandService $commands, OfflineProtectionService $offline)
    {
        $this->authorize('control', $device);
        $data = $request->validate(['password' => ['required', 'current_password'], 'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['accepted']]);
        $offline->permanentRelease($device, $request->user(), $data['reason']);
        $commands->create($device, 'PERMANENT_RELEASE', ['reason' => $data['reason'], 'offline_protection_disabled' => true], $request->user());

        return back()->with('success', 'Permanent release has been queued for device confirmation.');
    }

    public function generateUnlockCode(Request $request, Device $device, AuditService $audit)
    {
        $this->authorize('control', $device);
        $request->validate(['password' => ['required', 'current_password']]);
        $plain = strtoupper(Str::random(4).'-'.Str::random(4));
        $device->accessCodes()->where('type', 'temporary_unlock')->whereNull('used_at')->delete();
        DeviceAccessCode::create([
            'device_id' => $device->id,
            'created_by' => $request->user()->id,
            'type' => 'temporary_unlock',
            'code_hash' => Hash::make($plain),
            'expires_at' => now()->addMinutes(30),
            'max_attempts' => 5,
        ]);
        $audit->record('unlock_code_generated', 'One-time temporary unlock code generated', $request->user(), $device);

        return back()->with('success', 'Temporary unlock code generated.')->with('unlock_code', $plain);
    }
}
