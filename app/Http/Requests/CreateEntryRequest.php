<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['INGRESO', 'ENTREGA'])],
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
            'type.in' => 'Tipo inválido (INGRESO o ENTREGA).',
            'items.required' => 'Debe enviar al menos un servicio.',
            'items.min' => 'Debe enviar al menos un servicio.',
            'items.*.serviceId.exists' => 'Un servicio no existe.',
            'items.*.quantity.min' => 'La cantidad no puede ser negativa.',
        ];
    }
}
