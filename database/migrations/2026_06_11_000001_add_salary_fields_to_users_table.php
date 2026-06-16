<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Base salary for all staff/teachers
            $table->decimal('base_salary', 10, 2)->nullable()->after('avatar');

            // For cours de soutien teachers: can be base only, or base + variable
            $table->enum('salary_type', ['fixed', 'base_plus_per_class', 'base_plus_per_student'])
                  ->default('fixed')->after('base_salary');

            // Variable rate (MAD per class or per student) — null means fixed only
            $table->decimal('salary_variable_rate', 10, 2)->nullable()->after('salary_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['base_salary', 'salary_type', 'salary_variable_rate']);
        });
    }
};
