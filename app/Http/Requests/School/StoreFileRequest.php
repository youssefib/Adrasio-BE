<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class StoreFileRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'file'         => 'required|file|max:102400',
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'grade_id'     => 'nullable|exists:grades,id',
            'classroom_id' => 'nullable|exists:classrooms,id',
            'visibility'   => 'sometimes|in:class,grade,school',
        ];
    }
}
