<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->decimal('previous_total_paid', 14, 2)->default(0)->after('amount');
            $table->decimal('new_total_paid', 14, 2)->default(0)->after('previous_total_paid');
            $table->decimal('previous_remaining_balance', 14, 2)->default(0)->after('new_total_paid');
            $table->decimal('new_remaining_balance', 14, 2)->default(0)->after('previous_remaining_balance');
            $table->decimal('next_payment_amount', 14, 2)->nullable()->after('new_remaining_balance');
            $table->date('next_payment_date')->nullable()->after('next_payment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropColumn([
                'previous_total_paid',
                'new_total_paid',
                'previous_remaining_balance',
                'new_remaining_balance',
                'next_payment_amount',
                'next_payment_date',
            ]);
        });
    }
};
