<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffExpense extends Model
{
    protected $fillable = [
        'school_id',
        'user_id',
        'category',
        'description',
        'amount',
        'expense_date',
        'status',
        'receipt_path',
        'notes',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'expense_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
