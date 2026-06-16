<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_classroom', function (Blueprint $table) {
            $table->foreignId('student_profile_id')->nullable()->after('student_id')->constrained()->nullOnDelete();
            $table->enum('status', ['active', 'dropped', 'completed'])->default('active')->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('student_classroom', function (Blueprint $table) {
            $table->dropForeign(['student_profile_id']);
            $table->dropColumn(['student_profile_id', 'status']);
        });
    }
};
