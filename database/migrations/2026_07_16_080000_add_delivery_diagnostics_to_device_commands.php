<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->dateTime('dispatched_at')->nullable()->after('expires_at');
            $table->dateTime('executing_at')->nullable()->after('delivered_at');
            $table->string('firebase_message_id')->nullable()->after('retry_count');
            $table->text('firebase_error')->nullable()->after('firebase_message_id');
            $table->dateTime('firebase_attempted_at')->nullable()->after('firebase_error');
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', fn (Blueprint $table) => $table->dropColumn(['dispatched_at','executing_at','firebase_message_id','firebase_error','firebase_attempted_at']));
    }
};
