<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipApplicationRequest extends FormRequest
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
            'satellite_id' => ['required', 'integer', Rule::exists('satellites', 'id')->where('is_active', true)],
            'plan_id' => ['required', 'integer', Rule::exists('membership_plans', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'satellite_id.exists' => 'Please select a valid satellite.',
            'plan_id.exists' => 'Please select a valid membership plan.',
        ];
    }
}
