<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTramiteRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'serviceId' => ['required', 'string', 'exists:services,id'],
            'placa' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'La fecha es obligatoria.',
            'date.date_format' => 'La fecha debe tener formato YYYY-MM-DD.',
            'serviceId.required' => 'El tipo de trámite es obligatorio.',
            'serviceId.exists' => 'El tipo de trámite no existe.',
            'placa.required' => 'El nro. de placa o póliza es obligatorio.',
            'placa.regex' => 'El nro. de placa o póliza debe ser alfanumérico, sin espacios.',
            'placa.max' => 'El nro. de placa o póliza no puede superar 30 caracteres.',
            'observaciones.max' => 'Las observaciones no pueden superar 500 caracteres.',
        ];
    }
}
