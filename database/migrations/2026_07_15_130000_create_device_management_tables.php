<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->text('address')->nullable();
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('brand');
            $table->string('model');
            $table->string('imei')->unique();
            $table->string('secondary_imei')->nullable()->unique();
            $table->string('serial_number')->nullable();
            $table->decimal('selling_price', 14, 2);
            $table->string('currency', 3)->default('LKR');
            $table->string('shop_branch')->nullable();
            $table->string('support_phone')->nullable();
            $table->string('device_uuid')->nullable()->unique();
            $table->string('android_id_hash')->nullable();
            $table->text('fcm_token')->nullable();
            $table->string('android_version')->nullable();
            $table->string('app_version')->nullable();
            $table->string('management_mode')->default('standard');
            $table->boolean('is_device_owner')->default(false);
            $table->boolean('can_block_uninstall')->default(false);
            $table->boolean('can_block_reset')->default(false);
            $table->boolean('can_full_lock')->default(false);
            $table->string('status')->default('pending_activation')->index();
            $table->string('lock_status')->default('unlocked')->index();
            $table->string('management_status')->default('setup_required');
            $table->boolean('location_tracking_enabled')->default(false);
            $table->string('tracking_mode')->default('disabled');
            $table->unsignedSmallInteger('tracking_interval_minutes')->default(15);
            $table->string('location_permission_status')->default('not_granted');
            $table->string('background_location_permission_status')->default('not_granted');
            $table->string('notification_permission_status')->default('not_granted');
            $table->string('gps_status')->default('unknown');
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->decimal('last_location_accuracy', 8, 2)->nullable();
            $table->dateTime('last_location_at')->nullable()->index();
            $table->unsignedTinyInteger('battery_percentage')->nullable();
            $table->string('network_status')->nullable();
            $table->dateTime('last_seen_at')->nullable()->index();
            $table->dateTime('last_sync_at')->nullable();
            $table->dateTime('registered_at');
            $table->dateTime('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('device_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->dateTime('expires_at')->index();
            $table->dateTime('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamps();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->index();
            $table->json('payload')->nullable();
            $table->string('signature');
            $table->string('status')->default('pending')->index();
            $table->text('result_message')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->dateTime('expires_at')->index();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_command_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_command_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('device_access_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('code_hash');
            $table->dateTime('expires_at')->index();
            $table->dateTime('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamps();
        });

        Schema::create('device_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->unsignedTinyInteger('battery_percentage')->nullable();
            $table->string('gps_status')->nullable();
            $table->string('network_status')->nullable();
            $table->string('lock_status')->nullable();
            $table->string('tracking_mode')->nullable();
            $table->dateTime('recorded_at')->index();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->text('description');
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'system_settings', 'device_locations', 'device_access_codes', 'device_command_attempts', 'device_commands', 'device_tokens', 'device_activations', 'devices', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
