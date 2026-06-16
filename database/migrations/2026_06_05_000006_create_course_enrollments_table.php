<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('course_class_id')->constrained('course_classes')->cascadeOnDelete();
            $table->decimal('monthly_fee_override', 10, 2)->nullable(); // null = use class fee
            $table->date('enrolled_at');
            $table->date('left_at')->nullable();
            $table->string('status')->default('active'); // active | inactive | suspended
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['student_profile_id', 'course_class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollments');
    }
};
