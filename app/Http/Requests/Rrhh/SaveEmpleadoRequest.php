<?php

namespace App\Http\Requests\Rrhh;

use App\Models\Empleado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->route('id') === null;

        return [
            'tratamientoId' => ['nullable', 'string', 'exists:tratamientos,id'],
            'ci' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:20',
                Rule::unique('empleados', 'ci')->ignore($this->route('id')),
            ],
            'nombre' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'apellidos' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'fechaNacimiento' => ['nullable', 'date'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'profesion' => ['nullable', 'string', 'max:100'],
            'estado' => ['sometimes', Rule::in([Empleado::ESTADO_ACTIVO, Empleado::ESTADO_INACTIVO])],
            // Asignación inicial opcional (el formulario de usuario la exige).
            'puestoId' => ['nullable', 'string', 'exists:puestos,id'],
            'fechaInicio' => ['nullable', 'date', 'required_with:puestoId'],
            'supervisorId' => ['nullable', 'string', 'exists:empleados,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'ci.required' => 'La cédula de identidad es obligatoria.',
            'ci.unique' => 'Ya existe un empleado con esa cédula.',
            'nombre.required' => 'El nombre es obligatorio.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'tratamientoId.exists' => 'El tratamiento seleccionado no existe.',
            'puestoId.exists' => 'El puesto seleccionado no existe.',
            'fechaInicio.required_with' => 'La fecha de inicio es obligatoria al asignar un puesto.',
            'supervisorId.exists' => 'El supervisor seleccionado no existe.',
        ];
    }
}
