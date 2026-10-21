<?php

namespace App\Http\Requests\Rrhh;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTratamientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->route('id') === null;

        return [
            'nombre' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:50',
                Rule::unique('tratamientos', 'nombre')->ignore($this->route('id')),
            ],
            'abreviatura' => [$creating ? 'required' : 'sometimes', 'string', 'max:10'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del tratamiento es obligatorio.',
            'nombre.unique' => 'Ya existe un tratamiento con ese nombre.',
            'abreviatura.required' => 'La abreviatura es obligatoria.',
        ];
    }
}
