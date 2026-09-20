<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // Per-school portal access switches (default OFF). When disabled,
            // the matching role cannot log in and existing sessions are blocked,
            // and teacher/student records are created without login credentials.
            $table->boolean('students_access_enabled')->default(false)->after('school_type');
            $table->boolean('teachers_access_enabled')->default(false)->after('students_access_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['students_access_enabled', 'teachers_access_enabled']);
        });
    }
};
