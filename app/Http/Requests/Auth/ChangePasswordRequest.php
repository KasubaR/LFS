<?php

namespace App\Http\Requests\Auth;

use App\Rules\NotSharedTempPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'current_password'],
            // 'different:current_password' catches re-submitting whatever they're
            // currently authenticated with; NotSharedTempPassword catches the
            // temp password specifically even if it somehow isn't their current
            // one (e.g. an admin manually reset must_change_password).
            'password' => ['required', 'string', 'confirmed', 'different:current_password', new NotSharedTempPassword, Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.different' => 'Your new password must be different from your current password.',
        ];
    }
}
