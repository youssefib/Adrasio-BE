<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name'                 => 'sometimes|string|max:255',
            'email'                => "sometimes|email|unique:users,email,{$userId}",
            'phone'                => 'sometimes|nullable|string|max:30',
            'password'             => 'sometimes|string|min:8',
            'is_active'            => 'sometimes|boolean',
            'role'                 => 'sometimes|in:admin,teacher,student',
            // Salary fields
            'base_salary'          => 'sometimes|nullable|numeric|min:0',
            'salary_type'          => 'sometimes|in:fixed,base_plus_per_class,base_plus_per_student',
            'salary_variable_rate'      => 'sometimes|nullable|numeric|min:0',
            'salary_rate_is_percentage' => 'sometimes|boolean',
        ];
    }
}
