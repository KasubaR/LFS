<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAdminUserRequest extends FormRequest
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
        $id = (int) $this->route('id');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('admin_users', 'email')->ignore($id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::in(AdminRole::ALL)],
            'satellite_ids' => [
                Rule::requiredIf(fn () => $this->input('role') === AdminRole::SatelliteAdministrator),
                'nullable',
                'array',
            ],
            'satellite_ids.*' => ['integer', 'exists:satellites,id'],
        ];
    }
}
