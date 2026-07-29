<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_protection_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('default_period_value')->default(5);
            $table->string('default_period_unit', 10)->default('days');
            $table->unsignedBigInteger('default_period_seconds')->default(432000);
            $table->boolean('warning_notification_enabled')->default(true);
            $table->unsignedBigInteger('first_warning_seconds')->default(86400);
            $table->unsignedBigInteger('final_warning_seconds')->default(21600);
            $table->boolean('allow_admin_override')->default(true);
            $table->boolean('require_password_confirmation')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('device_offline_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('uses_global_default')->default(true);
            $table->unsignedInteger('period_value')->default(5);
            $table->string('period_unit', 10)->default('days');
            $table->unsignedBigInteger('max_offline_seconds')->default(432000);
            $table->dateTime('last_verified_at')->nullable();
            $table->dateTime('offline_deadline_at')->nullable()->index();
            $table->unsignedBigInteger('policy_version')->default(1)->index();
            $table->dateTime('policy_issued_at')->nullable();
            $table->dateTime('policy_expires_at')->nullable();
            $table->string('last_issued_nonce', 64)->nullable();
            $table->dateTime('policy_acknowledged_at')->nullable();
            $table->dateTime('phone_reported_deadline_at')->nullable();
            $table->dateTime('last_warning_at')->nullable();
            $table->dateTime('last_offline_lock_at')->nullable();
            $table->string('last_offline_lock_result')->nullable();
            $table->boolean('permanent_release')->default(false);
            $table->string('last_network_status', 30)->nullable();
            $table->boolean('phone_local_locked')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offline_protection_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete()->index();
            $table->string('event_type')->index();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->unsignedBigInteger('policy_version')->nullable()->index();
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('occurred_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::table('offline_protection_settings')->insert([
            'enabled' => true,
            'default_period_value' => 5,
            'default_period_unit' => 'days',
            'default_period_seconds' => 432000,
            'warning_notification_enabled' => true,
            'first_warning_seconds' => 86400,
            'final_warning_seconds' => 21600,
            'allow_admin_override' => true,
            'require_password_confirmation' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_protection_audits');
        Schema::dropIfExists('device_offline_policies');
        Schema::dropIfExists('offline_protection_settings');
    }
};
