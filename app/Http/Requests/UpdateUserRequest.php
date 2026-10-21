<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::in(['ADMIN', 'OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'JEFE'])],
            'email' => [
                'sometimes', 'nullable', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($this->route('id')),
            ],
            // PATCH parcial: si no se envían, se conservan los actuales.
            // El empleado vinculado es inmutable: se define al crear la cuenta.
            'isActive' => ['sometimes', 'boolean'],
            'canBackfill' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'Rol inválido.',
            'email.email' => 'El correo no tiene un formato válido.',
            'email.unique' => 'El correo ya está registrado.',
        ];
    }
}
