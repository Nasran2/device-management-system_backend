<?php
namespace App\Services;
use App\Models\CustomerPayment;
use App\Models\PaymentReversal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class CustomerPaymentService {
    public function __construct(private SmsService $sms){}
    public function receive(array $data, User $user): CustomerPayment {
        $existing=CustomerPayment::where('idempotency_key',$data['idempotency_key'])->first(); if($existing)return $existing;
        $payment=DB::transaction(function()use($data,$user){
            $device=\App\Models\Device::visibleTo($user)->with('financing.installments')->findOrFail($data['device_id']);
            $amount=round((float)$data['amount'],2); if($amount<=0)throw \Illuminate\Validation\ValidationException::withMessages(['amount'=>'Payment must be greater than zero.']);
            $previousPaid=round((float)$device->financing->total_paid,2);
            $previousRemaining=round((float)$device->financing->remaining_balance,2);
            $p=CustomerPayment::create(['uuid'=>(string)Str::uuid(),'idempotency_key'=>$data['idempotency_key'],'receipt_number'=>'RCP-'.now()->format('Ymd').'-'.str_pad((string)(CustomerPayment::max('id')+1),6,'0',STR_PAD_LEFT),'shop_id'=>$device->shop_id,'customer_id'=>$device->customer_id,'device_id'=>$device->id,'amount'=>$amount,'previous_total_paid'=>$previousPaid,'previous_remaining_balance'=>$previousRemaining,'payment_date'=>$data['payment_date'],'payment_method'=>$data['payment_method'],'reference_number'=>$data['reference_number']??null,'collected_by'=>$user->id,'notes'=>$data['notes']??null,'send_sms'=>(bool)($data['send_sms']??false),'status'=>'completed']);
            $remaining=$amount;
            foreach($device->financing->installments->whereIn('status',['upcoming','due_today','partially_paid','overdue'])->sortBy('due_date') as $inst){
                if($remaining<=0)break;$allocation=min($remaining,(float)$inst->remaining_amount);
                $p->allocations()->create(['installment_schedule_id'=>$inst->id,'amount'=>$allocation,'type'=>'installment']);
                $paid=round((float)$inst->amount_paid+$allocation,2);$left=round((float)$inst->expected_amount-$paid,2);
                $inst->update(['amount_paid'=>$paid,'remaining_amount'=>max(0,$left),'paid_at'=>$left<=0?now():null,'status'=>$left<=0?'paid':'partially_paid','receipt_number'=>$p->receipt_number,'late_days'=>max(0,now()->startOfDay()->diffInDays($inst->due_date,false)*-1)]);
                $remaining=round($remaining-$allocation,2);
            }
            if($remaining>0)$p->allocations()->create(['amount'=>$remaining,'type'=>'extra']);
            $finance=$device->financing;$newPaid=round((float)$finance->total_paid+$amount,2);$newRemaining=max(0,round((float)$finance->selling_price-$newPaid,2));
            $finance->update(['total_paid'=>$newPaid,'remaining_balance'=>$newRemaining,'status'=>$newRemaining<=0?'paid':'active']);
            $next=$finance->installments()->whereIn('status',['upcoming','due_today','partially_paid','overdue'])->orderBy('due_date')->first();
            $p->update(['new_total_paid'=>$newPaid,'new_remaining_balance'=>$newRemaining,'next_payment_amount'=>$next?->remaining_amount,'next_payment_date'=>$next?->due_date]);
            return $p->load(['allocations.installment','customer','device.financing.installments']);
        });
        if($payment->send_sms) $this->sms->paymentReceived($payment,$user);
        return $payment;
    }
    public function reverse(CustomerPayment $p, User $user, string $reason): void {
        DB::transaction(function()use($p,$user,$reason){
            if($p->status!=='completed')throw \Illuminate\Validation\ValidationException::withMessages(['payment'=>'Only completed payments can be reversed.']);
            foreach($p->allocations as $a)if($a->installment){$i=$a->installment;$paid=max(0,round((float)$i->amount_paid-(float)$a->amount,2));$left=round((float)$i->expected_amount-$paid,2);$i->update(['amount_paid'=>$paid,'remaining_amount'=>$left,'paid_at'=>null,'receipt_number'=>null,'status'=>$paid>0?'partially_paid':($i->due_date->isPast()?'overdue':'upcoming')]);}
            $f=$p->device->financing;$f->update(['total_paid'=>max(0,round((float)$f->total_paid-(float)$p->amount,2)),'remaining_balance'=>round((float)$f->remaining_balance+(float)$p->amount,2),'status'=>'active']);
            PaymentReversal::create(['customer_payment_id'=>$p->id,'amount'=>$p->amount,'reason'=>$reason,'reversed_by'=>$user->id,'reversed_at'=>now()]);$p->update(['status'=>'reversed']);
        });
    }
}
