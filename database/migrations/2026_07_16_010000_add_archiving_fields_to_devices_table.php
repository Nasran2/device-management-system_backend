<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('devices', function (Blueprint $table) { $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete(); $table->string('status_before_archive')->nullable(); $table->softDeletes(); }); }
    public function down(): void { Schema::table('devices', function (Blueprint $table) { $table->dropForeign(['archived_by']); $table->dropColumn(['archived_by','status_before_archive','deleted_at']); }); }
};
