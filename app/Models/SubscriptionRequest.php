<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionRequest extends Model
{
    protected $fillable = [
        'school_id',
        'plan_id',
        'duration_months',
        'amount',
        'proof_path',
        'status',
        'reviewed_by',
        'admin_notes',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'amount'    => 'decimal:2',
        'starts_at' => 'date',
        'ends_at'   => 'date',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
