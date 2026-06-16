<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankingInfo extends Model
{
    protected $fillable = [
        'label',
        'type',
        'details',
        'is_active',
        'order',
    ];

    protected $casts = [
        'details'   => 'array',
        'is_active' => 'boolean',
        'order'     => 'integer',
    ];
}
