<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_setup_instructions', function (Blueprint $table) {
            $table->id();
            $table->string('computer_os', 20);
            $table->string('phone_brand', 40);
            $table->string('step_key', 80);
            $table->unsignedInteger('step_number');
            $table->string('title');
            $table->text('short_description');
            $table->text('why_required');
            $table->string('action_location', 80);
            $table->json('numbered_instructions');
            $table->string('shell_type', 40)->nullable();
            $table->text('command')->nullable();
            $table->string('run_from')->nullable();
            $table->text('terminal_help')->nullable();
            $table->text('expected_output');
            $table->json('possible_errors');
            $table->json('troubleshooting');
            $table->json('verification_items');
            $table->json('confirmation_items')->nullable();
            $table->boolean('required')->default(true);
            $table->boolean('auto_verifiable')->default(false);
            $table->string('server_check_key', 80)->nullable();
            $table->string('screenshot_path')->nullable();
            $table->unsignedInteger('display_order');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['computer_os', 'phone_brand', 'step_key'], 'setup_instruction_variant_unique');
            $table->index(['computer_os', 'phone_brand', 'active'], 'setup_instruction_variant_index');
        });

        Schema::table('device_setup_sessions', function (Blueprint $table) {
            $table->string('mode', 30)->default('manual_guided')->after('brand_group');
            $table->json('context')->nullable()->after('checklist');
        });

        Schema::table('device_setup_steps', function (Blueprint $table) {
            $table->foreignId('device_setup_instruction_id')->nullable()->after('device_setup_session_id')->constrained('device_setup_instructions')->nullOnDelete();
            $table->dateTime('started_at')->nullable()->after('completed');
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->string('verification_method', 40)->nullable()->after('notes');
            $table->string('command_result', 80)->nullable()->after('verification_method');
            $table->text('error_encountered')->nullable()->after('command_result');
            $table->text('troubleshooting_used')->nullable()->after('error_encountered');
        });
    }

    public function down(): void
    {
        Schema::table('device_setup_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_setup_instruction_id');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['started_at', 'verification_method', 'command_result', 'error_encountered', 'troubleshooting_used']);
        });
        Schema::table('device_setup_sessions', fn (Blueprint $table) => $table->dropColumn(['mode', 'context']));
        Schema::dropIfExists('device_setup_instructions');
    }
};
