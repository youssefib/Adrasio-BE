<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription Tier Limits
    |--------------------------------------------------------------------------
    | null = unlimited
    */

    'tiers' => [
        'trial' => [
            'max_students'     => 30,
            'max_teachers'     => 5,
            'max_admins'       => 2,
            'max_classes'      => 5,
            'max_storage_mb'   => 256,
            'duration_days'    => 14,
        ],
        'tier1' => [
            'max_students'     => 100,
            'max_teachers'     => 10,
            'max_admins'       => 3,
            'max_classes'      => 10,
            'max_storage_mb'   => 512,
        ],
        'tier2' => [
            'max_students'     => 500,
            'max_teachers'     => 50,
            'max_admins'       => 10,
            'max_classes'      => 50,
            'max_storage_mb'   => 5120,
        ],
        'tier3' => [
            'max_students'     => null,
            'max_teachers'     => null,
            'max_admins'       => null,
            'max_classes'      => null,
            'max_storage_mb'   => 51200,
        ],
    ],

];
