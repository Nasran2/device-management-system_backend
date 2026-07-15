<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeviceRequest;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceAccessCode;
use App\Services\ActivationService;
use App\Services\AuditService;
use App\Services\CommandService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $devices = Device::visibleTo($request->user())->with(['customer', 'admin'])
            ->when($request->search, fn ($q, $term) => $q->where(fn ($q) => $q->where('brand', 'like', "%{$term}%")->orWhere('model', 'like', "%{$term}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%"))))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))->latest()->paginate(15)->withQueryString();

        return view('devices.index', compact('devices'));
    }

    public function create()
    {
        return view('devices.create');
    }

    public function store(StoreDeviceRequest $request, ActivationService $activations, AuditService $audit)
    {
        [$device, $code] = DB::transaction(function () use ($request, $activations, $audit) {
            $customer = Customer::create(['admin_id' => $request->user()->id, 'name' => $request->customer_name, 'phone' => $request->customer_phone, 'address' => $request->customer_address]);
            $data = $request->safe()->except(['customer_name', 'customer_phone', 'customer_address', 'management_pin', 'management_pin_confirmation']);
            $data['admin_id'] = $request->user()->id;
            $data['customer_id'] = $customer->id;
            $data['location_tracking_enabled'] = $request->boolean('location_tracking_enabled');
            $data['tracking_mode'] = $data['location_tracking_enabled'] ? 'locked_only' : 'disabled';
            $data['management_pin_hash'] = Hash::make($request->management_pin);
            $data['management_pin_encrypted'] = Crypt::encryptString($request->management_pin);
            $data['management_pin_changed_at'] = now();
            $data['management_pin_changed_by'] = $request->user()->id;
            $device = Device::create($data);
            $code = $activations->issue($device);
            $audit->record('device_registered', 'Device registered', $request->user(), $device);
            $audit->record('MANAGEMENT_PIN_CREATED', 'Device management PIN created', $request->user(), $device);

            return [$device, $code];
        });

        return redirect()->route('devices.show', $device)->with('success', 'Device registered.')->with('activation_code', $code);
    }

    public function show(Device $device)
    {
        $this->authorize('view', $device);

        return view('devices.show', ['device' => $device->load(['customer', 'admin', 'managementPinChangedBy', 'commands.requester', 'locations'])]);
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

    public function release(Request $request, Device $device, CommandService $commands)
    {
        $this->authorize('control', $device);
        $data = $request->validate(['password' => ['required', 'current_password'], 'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['accepted']]);
        $commands->create($device, 'PERMANENT_RELEASE', ['reason' => $data['reason']], $request->user());

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
