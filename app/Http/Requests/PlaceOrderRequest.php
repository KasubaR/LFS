<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
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
            'customerInfo.name' => ['required', 'string'],
            'customerInfo.email' => ['required', 'email'],
            'customerInfo.phone' => ['nullable', 'string'],
            'customerInfo.notes' => ['nullable', 'string'],
            'paymentMethod' => ['required', 'in:mobile_money'],
            'provider' => ['required_if:paymentMethod,mobile_money', 'in:airtel,mtn'],
            'customerPhone' => ['required_if:paymentMethod,mobile_money', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.in' => 'Please select a valid provider: airtel or mtn.',
            'customerPhone.regex' => 'Please enter a valid mobile money phone number (e.g. +260971234567).',
        ];
    }
}
