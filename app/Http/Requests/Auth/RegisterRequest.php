<?php

namespace App\Http\Requests\Auth;

use App\Enums\TShirtSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'phone' => ['required', 'string', 'max:30', 'regex:/\d{6,}/'],
            'gender' => ['required', 'string', 'in:male,female,other,prefer_not_to_say'],
            'nationality' => ['required', 'string', 'max:100'],
            't_shirt_size' => ['required', 'string', Rule::in(TShirtSize::ALL)],
            'town' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid phone number.',
        ];
    }
}
