<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactMessageRequest extends FormRequest
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
            'firstName' => ['required', 'string', 'max:60'],
            'lastName' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/\d{6,}/'],
            'satellite' => ['nullable', 'string', 'in:,arcades,avondale,chamba-valley,woodies,north-side,south-side'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid phone number.',
            'satellite.in' => 'Please select a valid satellite.',
        ];
    }
}
