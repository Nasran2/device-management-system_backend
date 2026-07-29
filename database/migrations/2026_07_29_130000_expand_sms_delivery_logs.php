<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->text('message')->nullable()->after('template');
            $table->text('provider_response')->nullable()->after('provider_message_id');
            $table->unsignedInteger('attempts')->default(0)->after('provider_response');
            $table->dateTime('delivered_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', fn (Blueprint $table) => $table->dropColumn([
            'message',
            'provider_response',
            'attempts',
            'delivered_at',
        ]));
    }
};
