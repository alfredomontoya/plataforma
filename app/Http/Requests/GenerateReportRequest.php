<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'templateId' => ['nullable', 'string', 'exists:templates,id'],
            'mode' => ['sometimes', Rule::in(['DAY', 'WEEK'])],
            'date' => ['required_if:mode,DAY', 'nullable', 'date_format:Y-m-d'],
            'weekStart' => ['required_if:mode,WEEK', 'nullable', 'date_format:Y-m-d'],
            'nroCI' => ['required', 'string', 'max:50'],
            'dirigidoA' => ['required', 'string', 'max:200'],
            'puestoDirigidoA' => ['required', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required_if' => 'La fecha es obligatoria en modo día.',
            'weekStart.required_if' => 'La fecha de inicio de semana es obligatoria en modo semana.',
            'nroCI.required' => 'El N° de informe es obligatorio.',
            'nroCI.max' => 'El N° de informe no puede superar 50 caracteres.',
            'dirigidoA.required' => 'El destinatario es obligatorio.',
            'puestoDirigidoA.required' => 'El puesto del destinatario es obligatorio.',
        ];
    }
}
