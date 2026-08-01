<?php
namespace App\Services;
use App\Models\CustomerPayment;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Sms\TextItSmsService;
class SmsService {
    public function __construct(private TextItSmsService $textIt){}
    public function paymentReceived(CustomerPayment $p, User $user): SmsLog {
        $next=$p->device->financing->installments->whereIn('status',['upcoming','due_today','partially_paid','overdue'])->sortBy('due_date')->first();
        return $this->send((float)$p->device->financing->remaining_balance<=0?'balance_completed':'payment_received',$p->customer->phone,['customer_name'=>$p->customer->name,'shop_name'=>$p->device->shop?->name,'payment_amount'=>number_format((float)$p->amount,2),'total_paid'=>number_format((float)$p->device->financing->total_paid,2),'balance'=>number_format((float)$p->device->financing->remaining_balance,2),'remaining_balance'=>number_format((float)$p->device->financing->remaining_balance,2),'next_payment_amount'=>$next?number_format((float)$next->remaining_amount,2):'0.00','next_due_date'=>$next?->due_date?->format('d M Y')??'Completed','next_payment_date'=>$next?->due_date?->format('d M Y')??'Completed','receipt_number'=>$p->receipt_number,'phone_brand'=>$p->device->brand,'phone_model'=>$p->device->model,'device_reference'=>strtoupper(substr($p->device->uuid,0,8)),'support_number'=>$p->device->support_phone],$p->shop_id,$p->customer_id,$p->device_id,$user);
    }
    public function send(string $event,string $recipient,array $vars,?int $shopId=null,?int $customerId=null,?int $deviceId=null,?User $user=null): SmsLog {
        $template=SmsTemplate::where('event',$event)->where(fn($q)=>$q->where('shop_id',$shopId)->orWhere(fn($x)=>$x->whereNull('shop_id')->where('is_global',true)))->orderByDesc('shop_id')->first();
        $body=$template?->body??match($event){
            'new_device'=>'New managed device added. Shop: {shop_name}. Customer: {customer_name}. Phone: {phone_model}. Selling price: LKR {selling_price}. First payment: LKR {first_payment}. Months: {months}. Platform commission: LKR {commission}. Device ref: {device_reference}.',
            'installment_due_3_days'=>'Reminder from {shop_name}: LKR {next_payment_amount} for {phone_model} is due on {next_payment_date}. Support: {support_number}.',
            'installment_due_tomorrow'=>'Reminder from {shop_name}: LKR {next_payment_amount} for {phone_model} is due tomorrow, {next_payment_date}.',
            'installment_due_today'=>'Payment due today: LKR {next_payment_amount} for {phone_model}. Please contact {shop_name} at {support_number} if you need help.',
            'payment_overdue'=>'Overdue payment reminder from {shop_name}: LKR {next_payment_amount} for {phone_model} was due on {next_payment_date}.',
            'device_activation_code'=>'Your DeviceGuard activation code is {activation_code}. It is valid for {valid_hours} hours for {phone_model}. Do not share this code.',
            default=>'Payment received. Amount: LKR {payment_amount}. Total paid: LKR {total_paid}. Remaining balance: LKR {remaining_balance}. Next payment: LKR {next_payment_amount} due on {next_payment_date}. Thank you — {shop_name}.'
        };
        foreach($vars as $k=>$v)$body=str_replace('{'.$k.'}',(string)$v,$body);
        $storedBody=$event==='device_activation_code'?'Device activation code sent securely; message content is redacted.':$body;
        $log=SmsLog::create(['shop_id'=>$shopId,'customer_id'=>$customerId,'device_id'=>$deviceId,'recipient_number'=>$recipient,'template'=>$event,'message'=>$storedBody,'provider'=>SystemSetting::value('sms_provider','textit.biz'),'sent_status'=>'pending','sent_by'=>$user?->id]);
        $enabled=match($event){'device_activation_code'=>SystemSetting::value('send_activation_code_by_sms',false),'payment_received','balance_completed'=>SystemSetting::value('payment_sms_enabled',true),'installment_due_3_days','installment_due_tomorrow','installment_due_today'=>SystemSetting::value('due_reminder_enabled',true),'payment_overdue'=>SystemSetting::value('overdue_reminder_enabled',true),'platform_settlement'=>SystemSetting::value('settlement_sms_enabled',true),default=>true};
        if(!$enabled){$log->update(['sent_status'=>'skipped','failure_reason'=>'This notification type is disabled.']);return $log->fresh();}
        $result=$this->textIt->send($recipient,$body);
        $sensitive=$event==='device_activation_code';
        $log->update([
            'recipient_number'=>$result['recipient']??$recipient,
            'sent_status'=>$result['success']?'sent':'failed',
            'delivery_status'=>$result['success']?'submitted':null,
            'provider_message_id'=>$result['message_id'],
            'provider_response'=>$sensitive && $result['provider_response']?'[redacted activation response]':$result['provider_response'],
            'attempts'=>$result['attempts'],
            'failure_reason'=>$sensitive && $result['failure_reason']?'Activation-code SMS delivery failed; sensitive provider details were redacted.':$result['failure_reason'],
            'sent_at'=>$result['success']?now():null,
            'safe_metadata'=>['provider_status'=>$result['status']],
        ]);
        return $log->fresh();
    }
}
