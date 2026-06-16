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
        Schema::table('files', function (Blueprint $table) {
            $table->enum('visibility', ['class', 'grade', 'school'])->default('class')->after('classroom_id');
            $table->string('file_type')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'file_type']);
        });
    }
};
