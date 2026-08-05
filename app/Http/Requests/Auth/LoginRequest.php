<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            // Either an email address or an LFS member number — see
            // AuthenticatedSessionController::resolveLoginUser().
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }
}
