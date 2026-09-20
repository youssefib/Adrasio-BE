<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a member-class enrollment to the pack subscription that created it.
 * When set, the enrollment is billed via the pack (not individually) and its
 * per-class revenue is the pack price / number of member classes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->foreignId('class_group_enrollment_id')->nullable()->after('course_class_id')
                ->constrained('class_group_enrollments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_group_enrollment_id');
        });
    }
};
