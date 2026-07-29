<?php
namespace App\Http\Controllers;
use App\Models\Shop;use App\Models\User;use App\Services\AuditService;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Hash;use Illuminate\Support\Str;
class ShopController extends Controller{
    private function super(Request $r){abort_unless($r->user()->isSuperAdmin(),403);}
    public function index(Request $r){$this->super($r);$base=$r->status==='archived'?Shop::onlyTrashed():Shop::query();$shops=$base->withCount(['devices','customers'])->when($r->status&&$r->status!=='archived',fn($q)=>$q->where('status',$r->status))->withSum('commissions','commission_amount')->withSum('commissions','outstanding_amount')->latest()->paginate(20);return view('shops.index',compact('shops'));}
    public function create(Request $r){$this->super($r);return view('shops.create');}
    public function store(Request $r,AuditService $audit){$this->super($r);$d=$this->validateData($r,true);[$shop,$user]=DB::transaction(function()use($d,$r){$shopData=collect($d)->except('temporary_password')->all();$shopData['reference_code']=($d['reference_code']??null)?:'SHOP-'.strtoupper(Str::random(8));$shopData['created_by']=$r->user()->id;$shop=Shop::create($shopData);$user=User::create(['shop_id'=>$shop->id,'name'=>$d['owner_name'],'email'=>$d['email'],'phone'=>$d['mobile'],'business_name'=>$d['name'],'address'=>$d['address'],'password'=>$d['temporary_password'],'role'=>'shop_owner','is_active'=>$d['status']==='active']);return[$shop,$user];});$audit->record('SHOP_CREATED','Shop owner account created',$r->user(),null,[],['shop_id'=>$shop->id]);return redirect()->route('shops.show',$shop)->with('success','Shop and separate Shop Owner login created.');}
    public function show(Request $r,Shop $shop){$this->super($r);return view('shops.show',['shop'=>$shop->load(['users','devices.customer','commissions.device','settlements'])->loadCount(['customers','devices'])]);}
    public function edit(Request $r,Shop $shop){$this->super($r);return view('shops.create',compact('shop'));}
    public function profile(Request $r){abort_unless($r->user()->shop&&$r->user()->canShop('shop.settings'),403);return view('shops.profile',['shop'=>$r->user()->shop]);}
    public function updateProfile(Request $r,AuditService $audit){abort_unless($r->user()->shop&&$r->user()->canShop('shop.settings'),403);$shop=$r->user()->shop;$data=$r->validate(['name'=>['required','string','max:160'],'owner_name'=>['required','string','max:120'],'mobile'=>['required','string','max:30'],'alternative_mobile'=>['nullable','string','max:30'],'address'=>['required','string'],'city'=>['nullable','string','max:100'],'district'=>['nullable','string','max:100'],'reminders_enabled'=>['nullable','boolean'],'notes'=>['nullable','string','max:2000']]);$data['reminders_enabled']=$r->boolean('reminders_enabled');$before=$shop->only(array_keys($data));$shop->update($data);$audit->record('SHOP_PROFILE_EDITED','Shop profile updated by authorized shop user',$r->user(),null,$before,$data);return back()->with('success','Shop profile updated. Platform commission settings are controlled by the Super Admin.');}
    public function update(Request $r,Shop $shop,AuditService $audit){$this->super($r);$d=$this->validateData($r,false);$before=$shop->toArray();$shop->update(collect($d)->except('temporary_password')->all());$owner=$shop->users()->where('role','shop_owner')->first();$owner?->update(array_filter(['name'=>$d['owner_name'],'email'=>$d['email'],'phone'=>$d['mobile'],'business_name'=>$d['name'],'address'=>$d['address'],'is_active'=>$shop->status==='active','password'=>$d['temporary_password']??null],fn($v)=>$v!==null));$audit->record('SHOP_EDITED','Shop profile or commission changed',$r->user(),null,$before,$shop->fresh()->toArray());return back()->with('success','Shop updated. Existing device commission snapshots were not changed.');}
    public function archive(Request $r,Shop $shop){$this->super($r);$r->validate(['password'=>['required','current_password'],'reason'=>['required']]);$shop->users()->update(['is_active'=>false]);$shop->delete();return redirect()->route('shops.index')->with('success','Shop archived. Financial history was preserved.');}
    public function status(Request $r,Shop $shop,AuditService $audit){$this->super($r);$d=$r->validate(['status'=>['required','in:active,inactive'],'password'=>['required','current_password'],'reason'=>['required','string','max:1000']]);$before=$shop->status;$shop->update(['status'=>$d['status']]);$shop->users()->where('role','shop_owner')->update(['is_active'=>$d['status']==='active']);$audit->record('SHOP_STATUS_CHANGED',"Shop changed from {$before} to {$d['status']}: {$d['reason']}",$r->user(),null,['shop_id'=>$shop->id,'status'=>$before],['shop_id'=>$shop->id,'status'=>$d['status']]);return back()->with('success',"Shop account {$d['status']}. Existing managed devices and financial records were preserved.");}
    public function resetPassword(Request $r,Shop $shop,AuditService $audit){$this->super($r);$d=$r->validate(['password'=>['required','current_password'],'temporary_password'=>['required','string','min:10','confirmed'],'reason'=>['required','string','max:1000']]);$owner=$shop->users()->where('role','shop_owner')->firstOrFail();$owner->update(['password'=>$d['temporary_password']]);$audit->record('SHOP_PASSWORD_RESET','Shop Owner password reset: '.$d['reason'],$r->user(),null,[],['shop_id'=>$shop->id,'owner_user_id'=>$owner->id]);return back()->with('success','Shop Owner password reset. Share it securely and require an immediate change.');}
    public function restore(Request $r,int $shop){$this->super($r);$s=Shop::onlyTrashed()->findOrFail($shop);$s->restore();return back()->with('success','Shop restored.');}
    public function destroy(Request $r,int $shop){$this->super($r);$s=Shop::onlyTrashed()->findOrFail($shop);$r->validate(['password'=>['required','current_password'],'confirmation'=>['required','in:DELETE'],'reason'=>['required']]);abort_if($s->devices()->exists()||$s->settlements()->exists(),422,'A shop with device or settlement history cannot be permanently deleted.');$s->users()->delete();$s->forceDelete();return redirect()->route('shops.index')->with('success','Eligible archived shop permanently deleted.');}
    public function recalculate(Request $r,Shop $shop,AuditService $audit){$this->super($r);$d=$r->validate(['password'=>['required','current_password'],'reason'=>['required'],'confirmed'=>['accepted']]);$before=$shop->commissions()->sum('commission_amount');$count=0;DB::transaction(function()use($shop,$r,$d,&$count){foreach($shop->commissions()->with('device.financing')->lockForUpdate()->get() as $c){$base=match($shop->commission_basis){'financed_balance_percentage'=>(float)($c->device->financing?->financed_balance??0),default=>(float)$c->device->selling_price};$new=match($shop->commission_basis){'fixed_per_device'=>(float)$shop->fixed_commission_amount,'custom_per_device'=>(float)$c->commission_amount,default=>round($base*(float)$shop->commission_percentage/100,2)};$delta=round($new-(float)$c->commission_amount,2);\App\Models\CommissionAdjustment::create(['shop_id'=>$shop->id,'device_commission_id'=>$c->id,'type'=>'recalculation','amount'=>$delta,'reason'=>$d['reason'],'created_by'=>$r->user()->id]);$paid=(float)$c->paid_amount;$c->update(['captured_percentage'=>$shop->commission_percentage,'calculation_basis'=>$shop->commission_basis,'base_amount'=>$base,'commission_amount'=>$new,'adjustment_amount'=>0,'outstanding_amount'=>max(0,$new-$paid),'status'=>$new-$paid<=0?'paid':($paid>0?'partially_paid':'outstanding')]);$count++;}});$audit->record('COMMISSION_RECALCULATED',"Recalculated $count device commissions: ".$d['reason'],$r->user(),null,['old_total'=>$before],['new_total'=>$shop->commissions()->sum('commission_amount'),'shop_id'=>$shop->id]);return back()->with('success',"$count historical device commissions recalculated after explicit confirmation.");}
    private function validateData(Request $r,bool $create){
        $shop=$r->route('shop');
        $owner=$shop instanceof Shop?$shop->users()->where('role','shop_owner')->first():null;
        $data=$r->validate([
            'name'=>['required','string','max:160'],
            'owner_name'=>['required','string','max:120'],
            'email'=>['required','email',\Illuminate\Validation\Rule::unique('shops')->ignore($shop),\Illuminate\Validation\Rule::unique('users')->ignore($owner)],
            'mobile'=>['required','string','max:30'],
            'alternative_mobile'=>['nullable','string','max:30'],
            'address'=>['required','string'],
            'city'=>['nullable'],
            'district'=>['nullable'],
            'business_registration_number'=>['nullable'],
            'reference_code'=>['nullable','string','max:50'],
            'commission_percentage'=>['required','numeric','min:0','max:100'],
            'commission_basis'=>['required','in:selling_price_percentage,financed_balance_percentage,fixed_per_device,custom_per_device'],
            'fixed_commission_amount'=>['nullable','numeric','min:0'],
            'status'=>['required','in:active,inactive'],
            'sms_enabled'=>['nullable','boolean'],
            'device_registration_enabled'=>['nullable','boolean'],
            'lock_unlock_enabled'=>['nullable','boolean'],
            'staff_accounts_enabled'=>['nullable','boolean'],
            'notes'=>['nullable'],
            'temporary_password'=>[$create?'required':'nullable','string','min:10'],
        ]);
        foreach(['sms_enabled','device_registration_enabled','lock_unlock_enabled','staff_accounts_enabled'] as $flag){
            $data[$flag]=$r->boolean($flag);
        }
        return $data;
    }
}
