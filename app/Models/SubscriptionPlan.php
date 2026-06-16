<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'max_students',
        'max_teachers',
        'max_classes',
        'storage_limit_mb',
        'price_monthly',
        'price_yearly',
        'price_3months',
        'price_6months',
        'allows_both_types',
        'allows_file_upload',
        'allows_teacher_portal',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'max_students'          => 'integer',
            'max_teachers'          => 'integer',
            'max_classes'           => 'integer',
            'storage_limit_mb'      => 'integer',
            'price_monthly'         => 'decimal:2',
            'price_yearly'          => 'decimal:2',
            'price_3months'         => 'decimal:2',
            'price_6months'         => 'decimal:2',
            'allows_both_types'     => 'boolean',
            'allows_file_upload'    => 'boolean',
            'allows_teacher_portal' => 'boolean',
            'is_active'             => 'boolean',
        ];
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    public function withinLimit(string $resource, int $current): bool
    {
        $limit = $this->{"max_{$resource}"};
        return $limit === null || $current < $limit;
    }
}
