<?php
namespace App\Http\Controllers;
use App\Models\Customer;use Illuminate\Http\Request;
class CustomerController extends Controller{
 private function canManage(Request $r){abort_unless($r->user()->canShop('customers.manage'),403);}
 private function query(Request $r){return Customer::query()->when(!$r->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',$r->user()->shop_id));}
 public function index(Request $r){$customers=$this->query($r)->withCount('devices')->when($r->search,fn($q,$v)=>$q->where(fn($x)=>$x->where('name','like',"%$v%")->orWhere('phone','like',"%$v%")->orWhere('nic','like',"%$v%")->orWhereHas('devices',fn($d)=>$d->where('imei','like',"%$v%")->orWhere('invoice_number','like',"%$v%"))))->paginate(20);return view('customers.index',compact('customers'));}
 public function create(Request $r){$this->canManage($r);abort_if($r->user()->isSuperAdmin(),403,'Create customers from a Shop Owner account.');return view('customers.form');}
 public function store(Request $r){$this->canManage($r);abort_if($r->user()->isSuperAdmin(),403,'Create customers from a Shop Owner account.');$d=$this->data($r);$c=Customer::create($d+['shop_id'=>$r->user()->shop_id,'admin_id'=>$r->user()->id,'created_by'=>$r->user()->id]);return redirect()->route('customers.show',$c)->with('success','Customer created.');}
 public function show(Request $r,Customer $customer){abort_unless($r->user()->isSuperAdmin()||$customer->shop_id===$r->user()->shop_id,403);return view('customers.show',['customer'=>$customer->load(['devices.financing.installments','payments.device','smsLogs'])]);}
 public function edit(Request $r,Customer $customer){$this->canManage($r);abort_unless($customer->shop_id===$r->user()->shop_id,403);return view('customers.form',compact('customer'));}
 public function update(Request $r,Customer $customer){$this->canManage($r);abort_unless($customer->shop_id===$r->user()->shop_id,403);$customer->update($this->data($r));return back()->with('success','Customer updated.');}
 public function destroy(Request $r,Customer $customer){$this->canManage($r);abort_unless($customer->shop_id===$r->user()->shop_id,403);abort_if($customer->devices()->exists(),422,'Customers with devices cannot be archived.');$customer->delete();return back()->with('success','Customer archived.');}
 private function data(Request $r){return $r->validate(['name'=>['required','string','max:120'],'nic'=>['nullable','string','max:50'],'phone'=>['required','string','max:30'],'alternative_phone'=>['nullable'],'address'=>['required','string'],'city'=>['nullable'],'district'=>['nullable'],'email'=>['nullable','email'],'notes'=>['nullable']]);}
}
