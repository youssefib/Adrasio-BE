<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add rent / utilities / internet / printing to the staff_expenses category enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE staff_expenses MODIFY COLUMN category ENUM('salary','salary_advance','transport','supplies','equipment','maintenance','rent','utilities','internet','printing','other') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE staff_expenses MODIFY COLUMN category ENUM('salary','salary_advance','transport','supplies','equipment','maintenance','other') NOT NULL");
    }
};
