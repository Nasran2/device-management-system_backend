<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::create('device_provisioning_tokens', function (Blueprint $table) { $table->id(); $table->foreignId('device_id')->constrained()->cascadeOnDelete(); $table->string('token_hash')->unique(); $table->string('status')->default('active')->index(); $table->dateTime('expires_at')->index(); $table->dateTime('used_at')->nullable(); $table->dateTime('revoked_at')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->dateTime('provisioning_started_at')->nullable(); $table->dateTime('provisioning_completed_at')->nullable(); $table->string('failure_code')->nullable(); $table->text('failure_message')->nullable(); $table->timestamps(); }); }
    public function down(): void { Schema::dropIfExists('device_provisioning_tokens'); }
};
