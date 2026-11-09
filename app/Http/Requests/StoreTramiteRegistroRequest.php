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
            'tipoRegistroId' => ['sometimes', 'nullable', 'string', 'exists:tipo_registros,id'],
            'entregaItems' => ['sometimes', 'nullable', 'array'],
            'entregaItems.*' => ['string', 'exists:entrega_items,id'],
            'placa' => ['required', 'string', 'max:10', 'regex:/^(\d{3,4}[A-Za-z]{3}|\d{5,10})$/'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'activo' => ['sometimes', 'boolean'],
            'ticket' => ['sometimes', 'nullable', 'string', 'max:8', 'regex:/^[A-Za-z0-9-]+$/'],
            'estado' => ['sometimes', 'required', 'in:procesado,recepcionado'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'La fecha es obligatoria.',
            'date.date_format' => 'La fecha debe tener formato YYYY-MM-DD.',
            'serviceId.required' => 'El tipo de trámite es obligatorio.',
            'serviceId.exists' => 'El tipo de trámite no existe.',
            'tipoRegistroId.exists' => 'El tipo de registro no existe.',
            'entregaItems.array' => 'Los ítems de entrega deben ser una lista.',
            'entregaItems.*.exists' => 'Uno de los ítems de entrega no existe.',
            'placa.required' => 'El nro. de placa o póliza es obligatorio.',
            'placa.regex' => 'El nro. de placa debe tener 3 o 4 dígitos y 3 letras (ej. 123ABC o 1234ABC); la póliza debe tener de 5 a 10 dígitos.',
            'placa.max' => 'El nro. de placa o póliza no puede superar 10 caracteres.',
            'observaciones.max' => 'Las observaciones no pueden superar 500 caracteres.',
            'ticket.max' => 'El número de ticket no puede superar 8 caracteres.',
            'ticket.regex' => 'El número de ticket solo puede contener letras, números y guion.',
            'estado.in' => 'El estado debe ser procesado o recepcionado.',
        ];
    }
}
