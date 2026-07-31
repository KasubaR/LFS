<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'productId' => ['required', 'string'],
            'size' => ['required', 'string'],
            'qty' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
