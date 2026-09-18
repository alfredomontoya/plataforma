<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTotalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Que sea INGRESO activo lo valida TotalService (400).
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.serviceId' => ['required', 'string', 'exists:services,id'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'La fecha es obligatoria.',
            'date.date_format' => 'La fecha debe tener formato YYYY-MM-DD.',
            'items.required' => 'Debe enviar al menos un trámite.',
            'items.min' => 'Debe enviar al menos un trámite.',
            'items.*.quantity.min' => 'La cantidad no puede ser negativa.',
        ];
    }
}
