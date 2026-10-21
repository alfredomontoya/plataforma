<?php

namespace App\Http\Requests\Rrhh;

use App\Models\Area;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAreaRequest extends FormRequest
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
                $creating ? 'required' : 'sometimes', 'string', 'max:191',
                Rule::unique('areas', 'nombre')->ignore($this->route('id')),
            ],
            'codigo' => [
                'nullable', 'string', 'max:30',
                Rule::unique('areas', 'codigo')->ignore($this->route('id')),
            ],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'padreId' => ['nullable', 'string', 'exists:areas,id'],
        ];
    }

    /**
     * Evita ciclos en la jerarquía: un área no puede ser su propio padre ni
     * colgar de uno de sus descendientes.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $id = $this->route('id');
                $padreId = $this->input('padreId');

                if (! $id || ! $padreId) {
                    return;
                }

                if ($padreId === $id) {
                    $validator->errors()->add('padreId', 'Un área no puede ser su propio padre.');

                    return;
                }

                $actual = Area::find($padreId);
                $visitados = [];

                while ($actual) {
                    if ($actual->id === $id) {
                        $validator->errors()->add('padreId', 'El área seleccionada es descendiente de esta área (ciclo).');

                        return;
                    }
                    if (isset($visitados[$actual->id])) {
                        break;
                    }
                    $visitados[$actual->id] = true;
                    $actual = $actual->padre;
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del área es obligatorio.',
            'nombre.unique' => 'Ya existe un área con ese nombre.',
            'codigo.unique' => 'Ya existe un área con ese código.',
            'padreId.exists' => 'El área padre seleccionada no existe.',
        ];
    }
}
