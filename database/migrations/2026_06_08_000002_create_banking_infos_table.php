<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banking_infos', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->enum('type', ['bank', 'western_union', 'moneygram', 'cash']);
            $table->json('details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banking_infos');
    }
};
