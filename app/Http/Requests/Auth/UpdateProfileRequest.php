<?php

namespace App\Http\Requests\Auth;

use App\Enums\TShirtSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/\d{6,}/'],
            'gender' => ['required', 'string', 'in:male,female,other,prefer_not_to_say'],
            'nationality' => ['required', 'string', 'max:100'],
            'satellite_id' => ['required', 'integer', Rule::exists('satellites', 'id')->where('is_active', true)],
            'town' => ['required', 'string', 'max:100'],
            't_shirt_size' => ['required', 'string', Rule::in(TShirtSize::ALL)],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid phone number.',
            'satellite_id.exists' => 'Please select a valid satellite.',
            'avatar.image' => 'Please choose a valid image file.',
            'avatar.mimes' => 'Profile photos must be JPG, PNG, or WebP.',
            'avatar.max' => 'Profile photos must be 2 MB or smaller.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('remove_avatar')) {
            $this->merge([
                'remove_avatar' => filter_var($this->input('remove_avatar'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
