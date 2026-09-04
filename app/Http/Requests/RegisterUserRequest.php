<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'min:3', 'max:50', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'role' => ['required', Rule::in(['ADMIN', 'OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'JEFE'])],
            'title' => ['required', Rule::in(['NONE', 'LIC', 'ABG', 'ING'])],
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'paternalSurname' => ['nullable', 'string', 'max:100'],
            'maternalSurname' => ['nullable', 'string', 'max:100'],
            'position' => ['required', 'string', 'max:191'],
            'department' => ['required', 'string', 'max:191'],
            'isActive' => ['sometimes', 'boolean'],
            'canBackfill' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'El nombre de usuario es obligatorio.',
            'username.min' => 'El usuario debe tener al menos 3 caracteres.',
            'username.max' => 'El usuario no puede superar 50 caracteres.',
            'username.unique' => 'El usuario ya existe.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'password.max' => 'La contraseña no puede superar 100 caracteres.',
            'role.required' => 'El rol es obligatorio.',
            'role.in' => 'Rol inválido.',
            'title.in' => 'Título inválido.',
            'firstName.required' => 'El nombre es obligatorio.',
            'lastName.required' => 'El apellido es obligatorio.',
            'position.required' => 'El cargo es obligatorio.',
            'department.required' => 'El departamento es obligatorio.',
        ];
    }
}
