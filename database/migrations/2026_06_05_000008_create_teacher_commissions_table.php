<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_class_id')->nullable()->constrained('course_classes')->nullOnDelete(); // null = default for teacher
            $table->string('commission_type'); // per_student | per_class | fixed_monthly
            $table->decimal('amount', 10, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // null = still active
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_commissions');
    }
};
