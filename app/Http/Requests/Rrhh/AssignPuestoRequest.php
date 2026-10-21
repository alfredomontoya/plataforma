<?php

namespace App\Http\Requests\Rrhh;

use Illuminate\Foundation\Http\FormRequest;

class AssignPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'puestoId' => ['required', 'string', 'exists:puestos,id'],
            'fechaInicio' => ['nullable', 'date'],
            'supervisorId' => ['nullable', 'string', 'exists:empleados,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'puestoId.required' => 'El puesto es obligatorio.',
            'puestoId.exists' => 'El puesto seleccionado no existe.',
            'supervisorId.exists' => 'El supervisor seleccionado no existe.',
        ];
    }
}
