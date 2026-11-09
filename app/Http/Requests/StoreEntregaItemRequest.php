<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEntregaItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', Rule::unique('entrega_items', 'name')],
            'codigo' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/', Rule::unique('entrega_items', 'codigo')],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del ítem es obligatorio.',
            'name.unique' => 'Ya existe un ítem con ese nombre.',
            'codigo.required' => 'El código es obligatorio.',
            'codigo.unique' => 'Ya existe un ítem con ese código.',
            'codigo.regex' => 'El código solo puede contener letras, números y guion bajo.',
        ];
    }
}
