<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The class's teacher takes this percentage of the class's monthly revenue.
 * Replaces the MAD-based commission model for course classes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_classes', function (Blueprint $table) {
            $table->decimal('teacher_share_pct', 5, 2)->default(0)->after('monthly_fee');
        });
    }

    public function down(): void
    {
        Schema::table('course_classes', function (Blueprint $table) {
            $table->dropColumn('teacher_share_pct');
        });
    }
};
