<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portal access is now OFF by default. Ensure the column defaults are false
 * (for DBs where the flags were originally added defaulting true) and disable
 * teacher/student access for every existing school.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('students_access_enabled')->default(false)->change();
            $table->boolean('teachers_access_enabled')->default(false)->change();
        });

        DB::table('schools')->update([
            'students_access_enabled' => false,
            'teachers_access_enabled' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('students_access_enabled')->default(true)->change();
            $table->boolean('teachers_access_enabled')->default(true)->change();
        });
    }
};
