<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassGroupPayment extends Model
{
    protected $fillable = [
        'school_id', 'class_group_enrollment_id', 'month', 'year',
        'amount', 'status', 'notes', 'paid_at', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'  => 'decimal:2',
            'month'   => 'integer',
            'year'    => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(ClassGroupEnrollment::class, 'class_group_enrollment_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
