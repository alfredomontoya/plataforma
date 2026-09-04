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
            'title' => ['sometimes', Rule::in(['NONE', 'LIC', 'ABG', 'ING'])],
            'firstName' => ['sometimes', 'string', 'max:100'],
            'lastName' => ['sometimes', 'string', 'max:100'],
            'paternalSurname' => ['nullable', 'string', 'max:100'],
            'maternalSurname' => ['nullable', 'string', 'max:100'],
            'isActive' => ['sometimes', 'boolean'],
            'canBackfill' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'Rol inválido.',
            'title.in' => 'Título inválido.',
        ];
    }
}
