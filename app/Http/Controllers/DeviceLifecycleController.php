<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DeviceLifecycleController extends Controller
{
    public function archive(Request $request, Device $device, AuditService $audit)
    {
        $this->authorize('archive', $device);
        $this->password($request);
        DB::transaction(function () use ($device, $request, $audit) {
            $device->commands()->whereIn('status', ['pending','queued','sent'])->update(['status' => 'cancelled', 'result_message' => 'Device archived']);
            $device->update(['archived_by' => $request->user()->id, 'status_before_archive' => $device->status]);
            $audit->record('DEVICE_ARCHIVED', 'Device archived', $request->user(), $device, [], $this->snapshot($device));
            $device->delete();
        });
        return redirect()->route('devices.index')->with('success', 'Device archived successfully.');
    }

    public function archived(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $devices = Device::onlyTrashed()->with(['customer','admin','archivedBy'])->latest('deleted_at')->paginate(20);
        return view('devices.archived', compact('devices'));
    }

    public function restore(Request $request, int $device, AuditService $audit)
    {
        $item = Device::onlyTrashed()->findOrFail($device);
        $this->authorize('restore', $item);
        $this->password($request);
        DB::transaction(function () use ($item, $request, $audit) {
            $item->restore();
            $item->update(['archived_by' => null, 'status' => $item->status_before_archive ?: $item->status]);
            $audit->record('DEVICE_RESTORED', 'Archived device restored without changing confirmed state', $request->user(), $item, [], $this->snapshot($item));
        });
        return redirect()->route('devices.archived')->with('success', 'Device restored.');
    }

    public function destroy(Request $request, int $device, AuditService $audit)
    {
        return $this->permanentlyDelete($request, Device::withTrashed()->findOrFail($device), $audit, false);
    }

    public function forceDestroy(Request $request, int $device, AuditService $audit)
    {
        return $this->permanentlyDelete($request, Device::withTrashed()->findOrFail($device), $audit, true);
    }

    public function bulkDelete(Request $request, AuditService $audit)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
            'password' => ['required'],
            'confirmation' => ['required', 'in:DELETE'],
            'confirmed' => ['accepted'],
            'reason' => ['required', 'string', 'max:1000']
        ]);
        
        $this->password($request);
        
        $devices = Device::withTrashed()->whereIn('id', $data['device_ids'])->get();
        
        $deletedCount = 0;
        $skippedCount = 0;
        
        DB::transaction(function () use ($devices, $request, $audit, $data, &$deletedCount, &$skippedCount) {
            foreach ($devices as $device) {
                if (! $this->eligible($device)) {
                    $skippedCount++;
                    continue;
                }
                
                $audit->record(
                    'DEVICES_BULK_DELETED',
                    'Device permanently deleted: ' . $data['reason'],
                    $request->user(),
                    $device,
                    [],
                    $this->snapshot($device) + ['reason' => $data['reason']]
                );
                $device->forceDelete();
                $deletedCount++;
            }
        });
        
        $message = "{$deletedCount} device(s) permanently deleted.";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} device(s) were skipped because they are still actively managed.";
        }
        
        return redirect()->route('devices.index')->with('success', $message);
    }

    private function permanentlyDelete(Request $request, Device $device, AuditService $audit, bool $force)
    {
        $this->authorize($force ? 'forceDelete' : 'delete', $device);
        $data = $request->validate(['password'=>['required'],'reason'=>['required','string','max:1000'],'confirmation'=>['required','in:DELETE'],'confirmed'=>['accepted'],'force_confirmed'=>[$force?'accepted':'nullable']]);
        $this->password($request);
        if (! $force && ! $this->eligible($device)) throw ValidationException::withMessages(['device' => 'This device is still actively managed. Permanently release the physical phone before deleting its server record.']);
        DB::transaction(function () use ($device,$request,$audit,$data,$force) {
            $action=$force?'DEVICE_FORCE_DELETED':'DEVICE_PERMANENTLY_DELETED';
            $description=($force?'Server record force deleted: ':'Device permanently deleted: ').$data['reason'];
            $audit->record($action,$description,$request->user(),$device,[],$this->snapshot($device)+['reason'=>$data['reason'],'deleted_at'=>now()->toIso8601String()]);
            $device->forceDelete();
        });
        return redirect()->route('devices.index')->with('success', 'Device server record permanently deleted.');
    }

    private function eligible(Device $device): bool
    {
        if ($device->is_device_owner || $device->lock_status==='locked' || $device->location_tracking_enabled) return false;
        return in_array($device->status,['pending_activation','demo','permanently_released','provisioning_failed'],true) || ($device->device_uuid===null && $device->last_seen_at===null);
    }

    private function password(Request $request): void
    {
        $request->validate(['password'=>['required','string']]);
        if (! Hash::check($request->password,$request->user()->password)) throw ValidationException::withMessages(['password'=>'The account password is incorrect.']);
    }

    private function snapshot(Device $device): array
    {
        return ['deleted_device_uuid'=>$device->uuid,'device_reference'=>$device->uuid,'brand'=>$device->brand,'model'=>$device->model,'status'=>$device->status,'lock_status'=>$device->lock_status];
    }
}
