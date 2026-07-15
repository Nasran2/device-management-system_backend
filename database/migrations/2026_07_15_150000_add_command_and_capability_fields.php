<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('is_admin_active')->default(false)->after('is_device_owner');
            $table->boolean('can_use_lock_task')->default(false)->after('can_full_lock');
            $table->boolean('is_lock_task_permitted')->default(false)->after('can_use_lock_task');
        });
        Schema::table('device_commands', function (Blueprint $table) {
            $table->string('failure_code')->nullable()->after('result_message');
            $table->string('previous_device_status')->nullable()->after('failure_code');
            $table->string('previous_lock_status')->nullable()->after('previous_device_status');
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', fn (Blueprint $table) => $table->dropColumn(['failure_code', 'previous_device_status', 'previous_lock_status']));
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn(['is_admin_active', 'can_use_lock_task', 'is_lock_task_permitted']));
    }
};
