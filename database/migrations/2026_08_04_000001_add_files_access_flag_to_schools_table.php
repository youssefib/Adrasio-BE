<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Files module is now opt-in: disabled by default, enabled per school from
 * Settings. Adding the column with a false default turns it off for every
 * existing school too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('files_access_enabled')->default(false)->after('teachers_access_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('files_access_enabled');
        });
    }
};
