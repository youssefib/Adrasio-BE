<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimetableSlotRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'classroom_id' => 'required|exists:classrooms,id',
            'teacher_id'   => 'required|exists:users,id',
            'room_id'      => 'nullable|exists:rooms,id',
            'subject'      => 'required|string|max:150',
            'day_of_week'  => 'required|integer|between:1,7',
            'start_time'   => 'required|date_format:H:i',
            'end_time'     => 'required|date_format:H:i|after:start_time',
            'valid_from'   => 'nullable|date',
            'valid_to'     => 'nullable|date|after_or_equal:valid_from',
        ];
    }
}
