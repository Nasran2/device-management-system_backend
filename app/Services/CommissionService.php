<?php
namespace App\Services;
use App\Models\Device;
use App\Models\DeviceCommission;
use App\Models\Shop;
class CommissionService {
    public function snapshot(Device $device, Shop $shop, float $financedBalance, ?float $custom=null): DeviceCommission {
        $selling=(float)$device->selling_price; $rate=(float)$shop->commission_percentage;
        [$base,$amount]=match($shop->commission_basis){
            'financed_balance_percentage'=>[$financedBalance,round($financedBalance*$rate/100,2)],
            'fixed_per_device'=>[$selling,round((float)$shop->fixed_commission_amount,2)],
            'custom_per_device'=>[$selling,round((float)$custom,2)],
            default=>[$selling,round($selling*$rate/100,2)],
        };
        return DeviceCommission::create(['shop_id'=>$shop->id,'device_id'=>$device->id,'captured_percentage'=>$rate,'calculation_basis'=>$shop->commission_basis,'base_amount'=>$base,'commission_amount'=>$amount,'outstanding_amount'=>$amount,'status'=>$amount>0?'outstanding':'paid']);
    }
    public function refresh(DeviceCommission $c): void {
        $out=round((float)$c->commission_amount-(float)$c->paid_amount-(float)$c->waived_amount+(float)$c->adjustment_amount,2);
        $c->update(['outstanding_amount'=>max(0,$out),'status'=>$out<=0?'paid':((float)$c->paid_amount>0?'partially_paid':'outstanding')]);
    }
}
