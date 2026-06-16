<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->unsignedTinyInteger('day_of_week'); // 1=Mon .. 7=Sun
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            // Prevent double-booking a room at the same time
            $table->unique(['school_id', 'room_id', 'day_of_week', 'start_time'], 'room_no_overlap');
            // Prevent double-booking a teacher at the same time
            $table->unique(['school_id', 'teacher_id', 'day_of_week', 'start_time'], 'teacher_no_overlap');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_slots');
    }
};
