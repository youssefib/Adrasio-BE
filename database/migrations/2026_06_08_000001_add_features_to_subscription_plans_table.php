<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('allows_both_types')->default(false)->after('is_active');
            $table->boolean('allows_file_upload')->default(false)->after('allows_both_types');
            $table->boolean('allows_teacher_portal')->default(false)->after('allows_file_upload');
            $table->decimal('price_3months', 10, 2)->default(0)->after('price_yearly');
            $table->decimal('price_6months', 10, 2)->default(0)->after('price_3months');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['allows_both_types', 'allows_file_upload', 'allows_teacher_portal', 'price_3months', 'price_6months']);
        });
    }
};
