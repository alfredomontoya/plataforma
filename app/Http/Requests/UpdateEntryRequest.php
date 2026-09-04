<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['quantity' => ['required', 'integer', 'min:0']];
    }

    public function messages(): array
    {
        return ['quantity.min' => 'La cantidad no puede ser negativa.'];
    }
}
