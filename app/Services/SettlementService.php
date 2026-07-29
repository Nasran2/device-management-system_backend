<?php
namespace App\Services;
use App\Models\PlatformSettlement;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
class SettlementService {
    public function __construct(private CommissionService $commissions){}
    public function record(Shop $shop,array $data,User $user):PlatformSettlement{
        return DB::transaction(function()use($shop,$data,$user){$amount=round((float)$data['amount'],2);$method=$data['allocation_method']??'oldest_first';$s=PlatformSettlement::create(['settlement_number'=>'SET-'.now()->format('Ymd').'-'.str_pad((string)(PlatformSettlement::max('id')+1),6,'0',STR_PAD_LEFT),'shop_id'=>$shop->id,'amount'=>$amount,'payment_date'=>$data['payment_date'],'payment_method'=>$data['payment_method'],'reference_number'=>$data['reference_number']??null,'received_by'=>$user->id,'allocation_method'=>$method,'notes'=>$data['notes']??null,'status'=>'completed']);$remaining=$amount;
            if($method==='unallocated_credit'){$s->update(['unallocated_credit'=>$remaining]);return $s->load('allocations');}
            $commissions=$method==='manual'
                ? $shop->commissions()->whereKey($data['device_commission_id'])->where('outstanding_amount','>',0)->lockForUpdate()->get()
                : $shop->commissions()->where('outstanding_amount','>',0)->oldest()->lockForUpdate()->get();
            if($method==='manual'&&$commissions->isEmpty())throw \Illuminate\Validation\ValidationException::withMessages(['device_commission_id'=>'The selected commission does not belong to this shop or has no outstanding balance.']);
            foreach($commissions as $c){if($remaining<=0)break;$a=min($remaining,(float)$c->outstanding_amount);$s->allocations()->create(['device_commission_id'=>$c->id,'amount'=>$a]);$c->increment('paid_amount',$a);$this->commissions->refresh($c->fresh());$remaining=round($remaining-$a,2);}
            $s->update(['unallocated_credit'=>$remaining]);return $s->load('allocations');});
    }
    public function reverse(PlatformSettlement $settlement,User $user,string $reason):void{
        DB::transaction(function()use($settlement,$user,$reason){if($settlement->status!=='completed')throw \Illuminate\Validation\ValidationException::withMessages(['settlement'=>'Only completed settlements can be reversed.']);foreach($settlement->allocations as $a){$c=\App\Models\DeviceCommission::lockForUpdate()->findOrFail($a->device_commission_id);$c->update(['paid_amount'=>max(0,round((float)$c->paid_amount-(float)$a->amount,2))]);$this->commissions->refresh($c->fresh());}\App\Models\PlatformSettlementReversal::create(['platform_settlement_id'=>$settlement->id,'amount'=>$settlement->amount,'reason'=>$reason,'reversed_by'=>$user->id,'reversed_at'=>now()]);$settlement->update(['status'=>'reversed']);});
    }
}
