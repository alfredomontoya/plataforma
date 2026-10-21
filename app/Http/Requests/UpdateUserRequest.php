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
            'firstName' => ['required_without:empleadoId', 'nullable', 'string', 'max:100'],
            'lastName' => ['required_without:empleadoId', 'nullable', 'string', 'max:100'],
            'paternalSurname' => ['nullable', 'string', 'max:100'],
            'maternalSurname' => ['nullable', 'string', 'max:100'],
            'empleadoId' => [
                'nullable', 'string', 'exists:empleados,id',
                Rule::unique('users', 'empleadoId')->ignore($this->route('id')),
            ],
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
            'firstName.required_without' => 'El nombre es obligatorio (o vincule un empleado).',
            'lastName.required_without' => 'El apellido es obligatorio (o vincule un empleado).',
            'empleadoId.exists' => 'El empleado seleccionado no existe.',
            'empleadoId.unique' => 'Ese empleado ya tiene una cuenta de usuario.',
        ];
    }
}
