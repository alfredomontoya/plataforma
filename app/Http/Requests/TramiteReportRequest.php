<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TramiteReportRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'from.required' => 'La fecha inicial es obligatoria.',
            'from.date_format' => 'La fecha inicial debe tener formato YYYY-MM-DD.',
            'to.required' => 'La fecha final es obligatoria.',
            'to.date_format' => 'La fecha final debe tener formato YYYY-MM-DD.',
            'to.after_or_equal' => 'La fecha final debe ser posterior o igual a la inicial.',
        ];
    }
}
