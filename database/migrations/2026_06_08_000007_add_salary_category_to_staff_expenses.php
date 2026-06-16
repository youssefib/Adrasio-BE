<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'salary' as a valid category for staff expenses
        DB::statement("ALTER TABLE staff_expenses MODIFY COLUMN category ENUM('salary','salary_advance','transport','supplies','equipment','maintenance','other') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE staff_expenses MODIFY COLUMN category ENUM('salary_advance','transport','supplies','equipment','maintenance','other') NOT NULL");
    }
};
