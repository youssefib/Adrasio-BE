<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // Admins always need login credentials. Teachers/students need them only
        // when their portal access is enabled for the school; otherwise they are
        // records without a login (email/password optional).
        $school = current_school();
        $role   = $this->input('role');

        $needsCredentials = $role === 'admin'
            || ($role === 'teacher' && $school?->teachers_access_enabled)
            || ($role === 'student' && $school?->students_access_enabled);

        return [
            'name'     => 'required|string|max:255',
            'email'    => ($needsCredentials ? 'required' : 'nullable') . '|email|unique:users,email',
            'phone'    => 'nullable|string|max:30',
            'password' => ($needsCredentials ? 'required' : 'nullable') . '|string|min:8',
            'role'     => 'required|in:admin,teacher,student',
            // Student-specific profile fields (enrollment auto-generated if blank)
            'enrollment_number' => 'nullable|string|max:50',
            'date_of_birth'     => 'nullable|date',
            'guardian_name'     => 'nullable|string|max:255',
            'guardian_phone'    => 'nullable|string|max:30',
            'guardian_email'    => 'nullable|email',
            'address'           => 'nullable|string',
        ];
    }
}
