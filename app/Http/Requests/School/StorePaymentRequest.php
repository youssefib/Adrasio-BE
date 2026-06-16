<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'student_profile_id' => 'required|exists:student_profiles,id',
            'year'               => 'required|integer|digits:4',
            'month'              => 'required|integer|between:1,12',
            'amount'             => 'required|numeric|min:0',
            'status'             => 'required|in:paid,unpaid,partial,waived',
            'notes'              => 'nullable|string',
            'paid_at'            => 'nullable|date',
        ];
    }
}
