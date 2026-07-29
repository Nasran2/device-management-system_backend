<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('owner_name'); $table->string('email')->unique();
            $table->string('mobile'); $table->string('alternative_mobile')->nullable(); $table->text('address');
            $table->string('city')->nullable(); $table->string('district')->nullable(); $table->string('business_registration_number')->nullable();
            $table->string('reference_code')->unique(); $table->decimal('commission_percentage', 7, 4)->default(5);
            $table->string('commission_basis')->default('selling_price_percentage'); $table->decimal('fixed_commission_amount', 14, 2)->default(0);
            $table->string('status')->default('active')->index(); $table->boolean('sms_enabled')->default(true);
            $table->boolean('device_registration_enabled')->default(true); $table->boolean('lock_unlock_enabled')->default(true);
            $table->boolean('staff_accounts_enabled')->default(false); $table->boolean('reminders_enabled')->default(true);
            $table->json('admin_override_permissions')->nullable(); $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->softDeletes(); $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('staff_role')->nullable()->after('role'); $table->json('shop_permissions')->nullable();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('nic')->nullable()->index(); $table->string('alternative_phone')->nullable(); $table->string('city')->nullable();
            $table->string('district')->nullable(); $table->string('email')->nullable(); $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->softDeletes();
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('storage')->nullable(); $table->string('colour')->nullable(); $table->string('invoice_number')->nullable()->index();
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->after('device_id')->constrained()->nullOnDelete();
        });

        Schema::create('device_financing', function (Blueprint $table) {
            $table->id(); $table->foreignId('shop_id')->constrained()->cascadeOnDelete(); $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete(); $table->decimal('selling_price', 14, 2); $table->decimal('first_payment', 14, 2)->default(0);
            $table->decimal('financed_balance', 14, 2); $table->unsignedInteger('number_of_installments'); $table->string('payment_frequency')->default('monthly');
            $table->unsignedInteger('custom_frequency_days')->nullable(); $table->date('first_due_date'); $table->decimal('installment_amount', 14, 2);
            $table->decimal('suggested_installment_amount', 14, 2); $table->decimal('final_installment_adjustment', 14, 2)->default(0);
            $table->decimal('total_paid', 14, 2)->default(0); $table->decimal('remaining_balance', 14, 2); $table->string('status')->default('active')->index();
            $table->timestamps();
        });
        Schema::create('installment_schedules', function (Blueprint $table) {
            $table->id(); $table->foreignId('shop_id')->constrained()->cascadeOnDelete(); $table->foreignId('device_financing_id')->constrained('device_financing')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete(); $table->unsignedInteger('installment_number'); $table->date('due_date')->index();
            $table->decimal('expected_amount', 14, 2); $table->decimal('amount_paid', 14, 2)->default(0); $table->decimal('remaining_amount', 14, 2);
            $table->dateTime('paid_at')->nullable(); $table->string('status')->default('upcoming')->index(); $table->string('receipt_number')->nullable();
            $table->integer('late_days')->default(0); $table->text('notes')->nullable(); $table->timestamps();
            $table->unique(['device_financing_id', 'installment_number'], 'financing_installment_number_unique');
        });
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->string('idempotency_key')->unique(); $table->string('receipt_number')->unique();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete(); $table->foreignId('customer_id')->constrained()->restrictOnDelete(); $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2); $table->date('payment_date')->index(); $table->string('payment_method'); $table->string('reference_number')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete(); $table->text('notes')->nullable(); $table->string('status')->default('completed')->index();
            $table->boolean('send_sms')->default(true); $table->timestamps();
        });
        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id(); $table->foreignId('customer_payment_id')->constrained()->restrictOnDelete(); $table->foreignId('installment_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2); $table->string('type')->default('installment'); $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->id(); $table->foreignId('customer_payment_id')->unique()->constrained()->restrictOnDelete(); $table->decimal('amount', 14, 2);
            $table->text('reason'); $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete(); $table->dateTime('reversed_at'); $table->timestamps();
        });
        Schema::create('device_commissions', function (Blueprint $table) {
            $table->id(); $table->foreignId('shop_id')->constrained()->cascadeOnDelete(); $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('captured_percentage', 7, 4)->default(0); $table->string('calculation_basis'); $table->decimal('base_amount', 14, 2);
            $table->decimal('commission_amount', 14, 2); $table->decimal('paid_amount', 14, 2)->default(0); $table->decimal('waived_amount', 14, 2)->default(0);
            $table->decimal('adjustment_amount', 14, 2)->default(0); $table->decimal('outstanding_amount', 14, 2); $table->string('status')->default('outstanding')->index(); $table->timestamps();
        });
        Schema::create('platform_settlements', function (Blueprint $table) {
            $table->id(); $table->string('settlement_number')->unique(); $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2); $table->date('payment_date')->index(); $table->string('payment_method'); $table->string('reference_number')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete(); $table->string('allocation_method')->default('oldest_first');
            $table->decimal('unallocated_credit', 14, 2)->default(0); $table->string('attachment_path')->nullable(); $table->text('notes')->nullable(); $table->string('status')->default('completed'); $table->timestamps();
        });
        Schema::create('platform_settlement_allocations', function (Blueprint $table) {
            $table->id(); $table->foreignId('platform_settlement_id')->constrained()->restrictOnDelete(); $table->foreignId('device_commission_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2); $table->timestamps();
        });
        Schema::create('platform_settlement_reversals', function (Blueprint $table) {
            $table->id(); $table->foreignId('platform_settlement_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount',14,2); $table->text('reason'); $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reversed_at'); $table->timestamps();
        });
        Schema::create('commission_adjustments', function (Blueprint $table) {
            $table->id(); $table->foreignId('shop_id')->constrained()->restrictOnDelete(); $table->foreignId('device_commission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); $table->decimal('amount', 14, 2); $table->text('reason'); $table->string('status')->default('approved');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id(); $table->foreignId('shop_id')->nullable()->constrained()->cascadeOnDelete(); $table->string('event'); $table->string('name');
            $table->text('body'); $table->boolean('enabled')->default(true); $table->boolean('is_global')->default(false); $table->timestamps(); $table->unique(['shop_id', 'event']);
        });
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete(); $table->string('recipient_number'); $table->string('template');
            $table->string('provider')->nullable(); $table->string('provider_message_id')->nullable(); $table->string('sent_status')->index();
            $table->string('delivery_status')->nullable(); $table->text('failure_reason')->nullable(); $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sent_at')->nullable(); $table->json('safe_metadata')->nullable(); $table->timestamps();
        });
        Schema::create('phone_brands', function (Blueprint $table) {
            $table->id(); $table->string('name')->unique(); $table->string('group')->nullable(); $table->string('official_driver_url')->nullable();
            $table->boolean('active')->default(true); $table->unsignedInteger('sort_order')->default(0); $table->timestamps();
        });
        Schema::create('device_setup_sessions', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('shop_id')->constrained()->cascadeOnDelete(); $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete(); $table->string('computer_os'); $table->string('brand_group');
            $table->unsignedInteger('current_step')->default(1); $table->string('status')->default('in_progress')->index(); $table->json('checklist')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete(); $table->dateTime('completed_at')->nullable(); $table->timestamps();
        });
        Schema::create('device_setup_steps', function (Blueprint $table) {
            $table->id(); $table->foreignId('device_setup_session_id')->constrained()->cascadeOnDelete(); $table->string('step_key');
            $table->boolean('completed')->default(false); $table->dateTime('completed_at')->nullable(); $table->text('notes')->nullable(); $table->json('safe_metadata')->nullable(); $table->timestamps();
            $table->unique(['device_setup_session_id', 'step_key']);
        });
        Schema::create('setup_instruction_versions', function (Blueprint $table) {
            $table->id(); $table->string('computer_os'); $table->string('brand_group'); $table->unsignedInteger('version')->default(1);
            $table->json('steps'); $table->boolean('active')->default(true); $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('setup_download_tokens', function (Blueprint $table) {
            $table->id(); $table->uuid('token_hash')->unique(); $table->foreignId('device_setup_session_id')->constrained()->cascadeOnDelete();
            $table->string('os'); $table->dateTime('expires_at')->index(); $table->dateTime('used_at')->nullable(); $table->timestamps();
        });
        Schema::create('apk_versions', function (Blueprint $table) {
            $table->id(); $table->string('version_name'); $table->unsignedBigInteger('version_code'); $table->string('download_url'); $table->string('sha256', 64);
            $table->boolean('active')->default(false); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });

        foreach (['Samsung','Xiaomi','Redmi','POCO','OPPO','Realme','Vivo','OnePlus','Nokia','Motorola','Google Pixel','Tecno','Infinix','Honor','Huawei','Other'] as $i => $brand) {
            DB::table('phone_brands')->insert(['name'=>$brand, 'group'=>match(true){in_array($brand,['Xiaomi','Redmi','POCO'])=>'xiaomi',in_array($brand,['OPPO','Realme','OnePlus'])=>'oppo',in_array($brand,['Vivo'])=>'vivo',in_array($brand,['Google Pixel','Nokia','Motorola'])=>'standard',in_array($brand,['Tecno','Infinix'])=>'transsion',default=>strtolower(str_replace(' ','_',$brand))},'sort_order'=>$i,'created_at'=>now(),'updated_at'=>now()]);
        }

        foreach (DB::table('users')->where('role', 'admin')->get() as $admin) {
            $shopId = DB::table('shops')->insertGetId(['name'=>$admin->business_name ?: $admin->name."'s Shop",'owner_name'=>$admin->name,'email'=>$admin->email,'mobile'=>$admin->phone ?: 'Not provided','address'=>$admin->address ?: 'Not provided','reference_code'=>'SHOP-'.str_pad((string)$admin->id,6,'0',STR_PAD_LEFT),'commission_percentage'=>5,'commission_basis'=>'selling_price_percentage','created_at'=>now(),'updated_at'=>now()]);
            DB::table('users')->where('id',$admin->id)->update(['shop_id'=>$shopId,'role'=>'shop_owner']);
            DB::table('customers')->where('admin_id',$admin->id)->update(['shop_id'=>$shopId,'created_by'=>$admin->id]);
            DB::table('devices')->where('admin_id',$admin->id)->update(['shop_id'=>$shopId]);
        }
    }

    public function down(): void
    {
        foreach (['apk_versions','setup_download_tokens','setup_instruction_versions','device_setup_steps','device_setup_sessions','phone_brands','sms_logs','sms_templates','commission_adjustments','platform_settlement_reversals','platform_settlement_allocations','platform_settlements','device_commissions','payment_reversals','customer_payment_allocations','customer_payments','installment_schedules','device_financing'] as $table) Schema::dropIfExists($table);
        Schema::table('audit_logs', fn(Blueprint $t)=>$t->dropConstrainedForeignId('customer_id'));
        Schema::table('audit_logs', fn(Blueprint $t)=>$t->dropConstrainedForeignId('shop_id'));
        Schema::table('devices', function(Blueprint $t){$t->dropConstrainedForeignId('shop_id');$t->dropColumn(['storage','colour','invoice_number']);});
        Schema::table('customers', function(Blueprint $t){$t->dropConstrainedForeignId('shop_id');$t->dropConstrainedForeignId('created_by');$t->dropSoftDeletes();$t->dropColumn(['nic','alternative_phone','city','district','email','notes']);});
        Schema::table('users', function(Blueprint $t){$t->dropConstrainedForeignId('shop_id');$t->dropColumn(['staff_role','shop_permissions']);});
        Schema::dropIfExists('shops');
    }
};
