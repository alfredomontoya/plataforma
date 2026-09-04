<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:6', 'max:100'],
            'confirmPassword' => ['required', 'string', 'same:newPassword'],
        ];
    }

    public function messages(): array
    {
        return [
            'currentPassword.required' => 'La contraseña actual es obligatoria.',
            'newPassword.required' => 'La nueva contraseña es obligatoria.',
            'newPassword.min' => 'La nueva contraseña debe tener al menos 6 caracteres.',
            'confirmPassword.same' => 'La confirmación no coincide con la nueva contraseña.',
        ];
    }
}
