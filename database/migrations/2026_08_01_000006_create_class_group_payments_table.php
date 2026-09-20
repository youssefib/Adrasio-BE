<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One monthly payment per pack subscription (the student pays the pack price
 * once, not per member class).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_group_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_group_enrollment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'paid', 'waived'])->default('pending');
            $table->string('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['class_group_enrollment_id', 'month', 'year']);
            $table->index(['school_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_group_payments');
    }
};
