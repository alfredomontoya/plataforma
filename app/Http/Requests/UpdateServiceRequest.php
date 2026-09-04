<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:191', Rule::unique('services', 'name')->ignore($id)],
            'abreviation' => ['nullable', 'string', 'max:32'],
            'type' => ['sometimes', Rule::in(['INGRESO', 'ENTREGA'])],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un servicio con ese nombre.',
            'type.in' => 'Tipo inválido (INGRESO o ENTREGA).',
        ];
    }
}
