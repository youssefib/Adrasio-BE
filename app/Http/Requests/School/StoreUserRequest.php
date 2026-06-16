<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'phone'    => 'nullable|string|max:30',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:admin,teacher,student',
            // Student-specific profile fields
            'enrollment_number' => 'required_if:role,student|nullable|string|max:50',
            'date_of_birth'     => 'nullable|date',
            'guardian_name'     => 'nullable|string|max:255',
            'guardian_phone'    => 'nullable|string|max:30',
            'guardian_email'    => 'nullable|email',
            'address'           => 'nullable|string',
        ];
    }
}
