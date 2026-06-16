<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $schoolId = $this->route('school')?->id;

        return [
            'name'                 => 'sometimes|string|max:255',
            'email'                => "sometimes|email|unique:schools,email,{$schoolId}",
            'phone'                => 'sometimes|nullable|string|max:30',
            'address'              => 'sometimes|nullable|string|max:500',
            'status'               => 'sometimes|in:active,suspended,trial,cancelled',
            'subscription_tier'    => 'sometimes|in:tier1,tier2,tier3,trial',
            'subscription_plan_id' => 'sometimes|exists:subscription_plans,id',
            'trial_ends_at'        => 'sometimes|nullable|date',
            'subscription_ends_at' => 'sometimes|nullable|date',
        ];
    }
}
