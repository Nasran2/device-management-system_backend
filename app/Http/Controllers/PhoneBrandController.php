<?php
namespace App\Http\Controllers;
use App\Models\PhoneBrand;use Illuminate\Http\Request;
class PhoneBrandController extends Controller{
 public function index(Request $r){abort_unless($r->user()->isSuperAdmin(),403);return view('settings.phone-brands',['brands'=>PhoneBrand::orderBy('sort_order')->get()]);}
 public function store(Request $r){abort_unless($r->user()->isSuperAdmin(),403);$d=$this->data($r);PhoneBrand::create($d);return back()->with('success','Phone brand added.');}
 public function update(Request $r,PhoneBrand $phoneBrand){abort_unless($r->user()->isSuperAdmin(),403);$phoneBrand->update($this->data($r,$phoneBrand));return back()->with('success','Official driver guidance updated.');}
 public function destroy(Request $r,PhoneBrand $phoneBrand){abort_unless($r->user()->isSuperAdmin(),403);$phoneBrand->update(['active'=>false]);return back()->with('success','Phone brand disabled without changing historical devices.');}
 private function data(Request $r,?PhoneBrand $brand=null){return $r->validate(['name'=>['required','string','max:80',\Illuminate\Validation\Rule::unique('phone_brands')->ignore($brand)],'group'=>['required','in:samsung,xiaomi,oppo,vivo,standard,transsion,other'],'official_driver_url'=>['nullable','url','starts_with:https://'],'active'=>['nullable','boolean'],'sort_order'=>['nullable','integer','min:0']]);}
}
