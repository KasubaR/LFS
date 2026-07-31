<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class InitiateMembershipPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'in:airtel,mtn'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.in' => 'Please select a valid provider: airtel or mtn.',
            'phone.regex' => 'Please enter a valid mobile money phone number (e.g. +260971234567).',
        ];
    }
}
