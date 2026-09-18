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
            'mode' => ['sometimes', Rule::in(['DAY', 'WEEK', 'RANGE'])],
            'date' => ['required_if:mode,DAY', 'nullable', 'date_format:Y-m-d'],
            'weekStart' => ['required_if:mode,WEEK', 'nullable', 'date_format:Y-m-d'],
            'from' => ['required_if:mode,RANGE', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:mode,RANGE', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'nroCI' => ['required', 'string', 'max:50'],
            'dirigidoA' => ['required', 'string', 'max:200'],
            'puestoDirigidoA' => ['required', 'string', 'max:200'],
            // Gráficos opcionales renderizados por el dashboard como PNG base64.
            'charts' => ['nullable', 'array'],
            'charts.grafico_ingreso' => ['nullable', 'string', 'max:6000000'],
            'charts.grafico_entrega' => ['nullable', 'string', 'max:6000000'],
            'charts.grafico_tendencia' => ['nullable', 'string', 'max:6000000'],
            'charts.grafico_distribucion' => ['nullable', 'string', 'max:6000000'],
            'charts.grafico_barras' => ['nullable', 'string', 'max:6000000'],
            'charts.grafico_tendencia_dia' => ['nullable', 'string', 'max:6000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required_if' => 'La fecha es obligatoria en modo día.',
            'weekStart.required_if' => 'La fecha de inicio de semana es obligatoria en modo semana.',
            'from.required_if' => 'La fecha desde es obligatoria en modo rango.',
            'to.required_if' => 'La fecha hasta es obligatoria en modo rango.',
            'to.after_or_equal' => 'La fecha hasta no puede ser anterior a la fecha desde.',
            'nroCI.required' => 'El N° de informe es obligatorio.',
            'nroCI.max' => 'El N° de informe no puede superar 50 caracteres.',
            'dirigidoA.required' => 'El destinatario es obligatorio.',
            'puestoDirigidoA.required' => 'El puesto del destinatario es obligatorio.',
        ];
    }
}
