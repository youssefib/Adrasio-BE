<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1-12
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['paid', 'unpaid', 'partial', 'waived'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->date('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'student_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
