<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function messages(): array
    {
        return [
            'from.required' => 'La fecha inicial es obligatoria.',
            'to.after_or_equal' => 'La fecha final debe ser posterior a la inicial.',
        ];
    }
}
