<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191', Rule::unique('services', 'name')],
            'abreviation' => ['nullable', 'string', 'max:32'],
            'type' => ['required', Rule::in(['INGRESO', 'ENTREGA'])],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del servicio es obligatorio.',
            'name.unique' => 'Ya existe un servicio con ese nombre.',
            'type.required' => 'El tipo es obligatorio.',
            'type.in' => 'Tipo inválido (INGRESO o ENTREGA).',
        ];
    }
}
