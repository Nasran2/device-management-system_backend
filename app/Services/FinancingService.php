<?php
namespace App\Services;
use App\Models\Device;
use App\Models\DeviceFinancing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinancingService {
    public function create(Device $device, array $data): DeviceFinancing {
        return DB::transaction(function() use($device,$data){
            $selling=$this->money($data['selling_price']); $first=$this->money($data['first_payment']);
            if($first>$selling) throw \Illuminate\Validation\ValidationException::withMessages(['first_payment'=>'First payment cannot exceed the selling price.']);
            $balance=$selling-$first; $count=(int)$data['number_of_installments'];
            $suggested=round($balance/$count,2); $chosen=isset($data['installment_amount'])?$this->money($data['installment_amount']):$suggested;
            $adjustment=round($balance-($chosen*$count),2);
            $finance=DeviceFinancing::create(['shop_id'=>$device->shop_id,'device_id'=>$device->id,'customer_id'=>$device->customer_id,'selling_price'=>$selling,'first_payment'=>$first,'financed_balance'=>$balance,'number_of_installments'=>$count,'payment_frequency'=>$data['payment_frequency'],'custom_frequency_days'=>$data['custom_frequency_days']??null,'first_due_date'=>$data['first_due_date'],'installment_amount'=>$chosen,'suggested_installment_amount'=>$suggested,'final_installment_adjustment'=>$adjustment,'total_paid'=>$first,'remaining_balance'=>$balance]);
            $due=CarbonImmutable::parse($data['first_due_date']);
            for($i=1;$i<=$count;$i++){
                $amount=$i===$count?round($balance-($chosen*($count-1)),2):$chosen;
                $finance->installments()->create(['shop_id'=>$device->shop_id,'device_id'=>$device->id,'installment_number'=>$i,'due_date'=>$due,'expected_amount'=>$amount,'remaining_amount'=>$amount,'status'=>$due->isPast()?'overdue':($due->isToday()?'due_today':'upcoming')]);
                $due=match($data['payment_frequency']){'weekly'=>$due->addWeek(),'custom'=>$due->addDays((int)$data['custom_frequency_days']),default=>$due->addMonthNoOverflow()};
            }
            return $finance->load('installments');
        });
    }
    private function money(mixed $value): float{return round((float)$value,2);}
}
