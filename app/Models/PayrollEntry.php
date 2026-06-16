<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntry extends Model
{
    protected $fillable = [
        'school_id',
        'user_id',
        'month',
        'year',
        'type',
        'base_amount',
        'variable_amount',
        'total_amount',
        'description',
        'status',
        'paid_at',
        'created_by',
    ];

    protected $casts = [
        'month'           => 'integer',
        'year'            => 'integer',
        'base_amount'     => 'decimal:2',
        'variable_amount' => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'paid_at'         => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
