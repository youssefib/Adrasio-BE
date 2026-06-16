<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'subscription_tier'    => 'required|in:tier1,tier2,tier3,trial',
            'subscription_plan_id' => 'nullable|exists:subscription_plans,id',
            'subscription_ends_at' => 'nullable|date',
            'trial_ends_at'        => 'nullable|date',
        ];
    }
}
