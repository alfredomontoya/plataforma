<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEntregaItemRequest extends FormRequest
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
        $id = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('entrega_items', 'name')->ignore($id)],
            'codigo' => ['sometimes', 'string', 'max:16', 'regex:/^[A-Z0-9_]+$/', Rule::unique('entrega_items', 'codigo')->ignore($id)],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un ítem con ese nombre.',
            'codigo.unique' => 'Ya existe un ítem con ese código.',
            'codigo.regex' => 'El código solo puede contener letras, números y guion bajo.',
        ];
    }
}
