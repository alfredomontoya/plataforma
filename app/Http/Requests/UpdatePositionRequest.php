<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', Rule::in(['NONE', 'LIC', 'ABG', 'ING'])],
            'position' => ['required', 'string', 'max:191'],
            'department' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function messages(): array
    {
        return [
            'position.required' => 'El cargo es obligatorio.',
        ];
    }
}
