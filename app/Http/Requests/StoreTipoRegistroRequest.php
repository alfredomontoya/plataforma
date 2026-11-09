<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTipoRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('codigo')) {
            $this->merge(['codigo' => strtoupper(trim((string) $this->input('codigo')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('tipo_registros', 'name')],
            'codigo' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/', Rule::unique('tipo_registros', 'codigo')],
            'requiereItems' => ['sometimes', 'boolean'],
            'requiereTicket' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del tipo de registro es obligatorio.',
            'name.unique' => 'Ya existe un tipo de registro con ese nombre.',
            'codigo.required' => 'El código es obligatorio.',
            'codigo.unique' => 'Ya existe un tipo de registro con ese código.',
            'codigo.regex' => 'El código solo puede contener letras, números y guion bajo.',
        ];
    }
}
