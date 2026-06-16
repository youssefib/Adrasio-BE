<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When true AND salary_type = 'base_plus_per_student':
            //   variable = Σ (class.monthly_fee × rate/100 × active_students) per class
            // When false AND salary_type = 'base_plus_per_student':
            //   variable = Σ active_students × rate  (fixed MAD per student)
            $table->boolean('salary_rate_is_percentage')->default(false)->after('salary_variable_rate');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('salary_rate_is_percentage');
        });
    }
};
