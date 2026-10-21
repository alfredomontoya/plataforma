<?php

namespace App\Http\Requests\Rrhh;

use Illuminate\Foundation\Http\FormRequest;

class SavePuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->route('id') === null;

        return [
            'areaId' => [$creating ? 'required' : 'sometimes', 'string', 'exists:areas,id'],
            'nombre' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'sigla' => ['nullable', 'string', 'max:10'],
            // Escala 1..7: 1 = operativo más bajo, 7 = Jefe de Área.
            'nivel' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1', 'max:7'],
            'esSupervisor' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'areaId.required' => 'El área es obligatoria.',
            'areaId.exists' => 'El área seleccionada no existe.',
            'nombre.required' => 'El nombre del puesto es obligatorio.',
            'nivel.required' => 'El nivel es obligatorio.',
            'nivel.min' => 'El nivel debe estar entre 1 y 7.',
            'nivel.max' => 'El nivel debe estar entre 1 y 7.',
        ];
    }
}
