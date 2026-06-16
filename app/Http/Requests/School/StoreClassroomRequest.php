<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class StoreClassroomRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'grade_id'      => 'required|exists:grades,id',
            'room_id'       => 'nullable|exists:rooms,id',
            'teacher_id'    => 'nullable|exists:users,id',
            'name'          => 'required|string|max:100',
            'section'       => 'nullable|string|max:10',
            'capacity'      => 'sometimes|integer|min:1',
            'academic_year' => 'required|string|max:20',
        ];
    }
}
