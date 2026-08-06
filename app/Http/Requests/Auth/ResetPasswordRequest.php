<?php

namespace App\Http\Requests\Auth;

use App\Rules\NotSharedTempPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            // "Forgot password" is also how a member recovers once their
            // shared temp password has expired — don't let them set the temp
            // password right back as their new permanent one.
            'password' => ['required', 'string', 'confirmed', new NotSharedTempPassword, Password::defaults()],
        ];
    }
}
