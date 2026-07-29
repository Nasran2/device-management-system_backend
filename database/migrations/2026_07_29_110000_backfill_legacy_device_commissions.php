<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::table('device_commissions',fn(Blueprint $t)=>$t->boolean('legacy_backfill')->default(false)->index());
  DB::table('devices')->whereNotNull('shop_id')->orderBy('id')->chunkById(200,function($devices){
   foreach($devices as $device){if(DB::table('device_commissions')->where('device_id',$device->id)->exists())continue;$shop=DB::table('shops')->find($device->shop_id);if(!$shop)continue;$rate=(float)$shop->commission_percentage;$base=(float)$device->selling_price;$amount=match($shop->commission_basis){'fixed_per_device'=>(float)$shop->fixed_commission_amount,'custom_per_device'=>0.0,default=>round($base*$rate/100,2)};DB::table('device_commissions')->insert(['shop_id'=>$shop->id,'device_id'=>$device->id,'captured_percentage'=>$rate,'calculation_basis'=>$shop->commission_basis,'base_amount'=>$base,'commission_amount'=>$amount,'paid_amount'=>0,'waived_amount'=>0,'adjustment_amount'=>0,'outstanding_amount'=>$amount,'status'=>$amount>0?'outstanding':'paid','legacy_backfill'=>true,'created_at'=>now(),'updated_at'=>now()]);}
  });
 }
 public function down():void{DB::table('device_commissions')->where('legacy_backfill',true)->delete();Schema::table('device_commissions',fn(Blueprint $t)=>$t->dropColumn('legacy_backfill'));}
};
