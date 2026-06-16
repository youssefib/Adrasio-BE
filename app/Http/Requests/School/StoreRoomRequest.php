<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:100',
            'code'         => 'nullable|string|max:20',
            'capacity'     => 'sometimes|integer|min:1',
            'is_available' => 'sometimes|boolean',
        ];
    }
}
