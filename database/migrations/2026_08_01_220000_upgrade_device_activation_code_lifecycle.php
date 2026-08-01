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
        Schema::table('device_activations', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->foreignId('setup_session_id')->nullable()->after('device_id')->constrained('device_setup_sessions')->nullOnDelete();
            $table->string('code_fingerprint', 64)->nullable()->index()->after('code_hash');
            $table->text('encrypted_code')->nullable()->after('code_fingerprint');
            $table->foreignId('generated_by')->nullable()->after('encrypted_code')->constrained('users')->nullOnDelete();
            $table->string('generation_reason')->nullable()->after('generated_by');
            $table->dateTime('revoked_at')->nullable()->index()->after('used_at');
            $table->unsignedTinyInteger('failed_attempts')->default(0)->after('attempts');
            $table->dateTime('last_failed_at')->nullable()->after('failed_attempts');
            $table->dateTime('locked_until')->nullable()->after('last_failed_at');
            $table->dateTime('expired_audited_at')->nullable()->after('locked_until');
            $table->index('used_at');
        });

        DB::table('device_activations')->whereNull('uuid')->orderBy('id')->eachById(function ($activation) {
            DB::table('device_activations')->where('id', $activation->id)->update(['uuid' => (string) Str::uuid()]);
        });

        foreach ([
            'device_activation_code_expiry_minutes' => ['1440', 'integer'],
            'send_activation_code_by_sms' => ['false', 'boolean'],
        ] as $key => [$value, $type]) {
            if (! DB::table('system_settings')->where('key', $key)->exists()) {
                DB::table('system_settings')->insert([
                    'key' => $key, 'value' => $value, 'type' => $type, 'is_public' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('device_activations', function (Blueprint $table) {
            $table->dropForeign(['setup_session_id']);
            $table->dropForeign(['generated_by']);
            $table->dropIndex(['code_fingerprint']);
            $table->dropIndex(['revoked_at']);
            $table->dropIndex(['used_at']);
            $table->dropUnique(['uuid']);
            $table->dropColumn([
                'uuid', 'setup_session_id', 'code_fingerprint', 'encrypted_code', 'generated_by',
                'generation_reason', 'revoked_at', 'failed_attempts', 'last_failed_at', 'locked_until',
                'expired_audited_at',
            ]);
        });

        DB::table('system_settings')->whereIn('key', [
            'device_activation_code_expiry_minutes',
            'send_activation_code_by_sms',
        ])->delete();
    }
};
