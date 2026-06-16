<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('month');  // 1–12
            $table->unsignedSmallInteger('year');

            // salary = auto-calculated from profile
            // advance = free entry (avance sur salaire)
            // bonus   = free entry (prime / gratification)
            $table->enum('type', ['salary', 'advance', 'bonus'])->default('salary');

            // For salary: base_amount = base_salary, variable_amount = calc'd variable part
            // For advance/bonus: base_amount = entered amount, variable = 0
            $table->decimal('base_amount', 10, 2)->default(0);
            $table->decimal('variable_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);

            $table->string('description')->nullable();
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Index for quick lookups; uniqueness on salary type enforced in controller
            $table->index(['school_id', 'user_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
    }
};
