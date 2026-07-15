<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('management_pin_hash')->nullable()->after('management_status');
            $table->text('management_pin_encrypted')->nullable()->after('management_pin_hash');
            $table->dateTime('management_pin_changed_at')->nullable()->after('management_pin_encrypted');
            $table->foreignId('management_pin_changed_by')->nullable()->after('management_pin_changed_at')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('management_pin_failed_attempts')->default(0)->after('management_pin_changed_by');
            $table->dateTime('management_pin_locked_until')->nullable()->after('management_pin_failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['management_pin_changed_by']);
            $table->dropColumn([
                'management_pin_hash', 'management_pin_encrypted', 'management_pin_changed_at',
                'management_pin_changed_by', 'management_pin_failed_attempts', 'management_pin_locked_until',
            ]);
        });
    }
};
