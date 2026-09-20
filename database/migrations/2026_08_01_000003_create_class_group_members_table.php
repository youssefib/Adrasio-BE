<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which course classes belong to a pack. A class may belong to several packs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_class_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['class_group_id', 'course_class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_group_members');
    }
};
