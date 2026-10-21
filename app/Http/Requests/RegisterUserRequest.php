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
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['ADMIN', 'OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'JEFE'])],
            // Si hay empleado vinculado los nombres se copian de la ficha de
            // RRHH; sin empleado, los nombres de la cuenta son obligatorios.
            'firstName' => ['required_without:empleadoId', 'nullable', 'string', 'max:100'],
            'lastName' => ['required_without:empleadoId', 'nullable', 'string', 'max:100'],
            'paternalSurname' => ['nullable', 'string', 'max:100'],
            'maternalSurname' => ['nullable', 'string', 'max:100'],
            'empleadoId' => ['nullable', 'string', 'exists:empleados,id', Rule::unique('users', 'empleadoId')],
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
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no tiene un formato válido.',
            'email.unique' => 'El correo ya está registrado.',
            'role.required' => 'El rol es obligatorio.',
            'role.in' => 'Rol inválido.',
            'firstName.required_without' => 'El nombre es obligatorio (o vincule un empleado).',
            'lastName.required_without' => 'El apellido es obligatorio (o vincule un empleado).',
            'empleadoId.exists' => 'El empleado seleccionado no existe.',
            'empleadoId.unique' => 'Ese empleado ya tiene una cuenta de usuario.',
        ];
    }
}
