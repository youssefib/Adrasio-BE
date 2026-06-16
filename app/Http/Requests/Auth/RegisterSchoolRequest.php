<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterSchoolRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'school_name'  => 'required|string|max:255',
            'school_email' => 'required|email|unique:schools,email',
            'owner_name'   => 'required|string|max:255',
            'owner_email'  => 'required|email|unique:users,email',
            'password'     => 'required|string|min:8|confirmed',
            'phone'        => 'nullable|string|max:30',
            'country'      => 'nullable|string|max:100',
        ];
    }
}
