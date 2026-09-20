<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's subscription to a pack. Billed once at the pack price;
 * the member-class CourseEnrollments are linked back via class_group_enrollment_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_group_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->decimal('price_override', 10, 2)->nullable(); // null = use pack price
            $table->date('enrolled_at');
            $table->date('left_at')->nullable();
            $table->string('status')->default('active'); // active | inactive | suspended
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['class_group_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_group_enrollments');
    }
};
