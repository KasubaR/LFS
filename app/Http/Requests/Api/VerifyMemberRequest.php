<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyMemberRequest extends FormRequest
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
            'membership_number' => ['required', 'string', 'max:40'],
            // Exactly one second factor. Membership numbers are sequential and
            // therefore guessable, so one is always required.
            'surname' => ['required_without:email', 'nullable', 'string', 'max:120'],
            'email' => ['required_without:surname', 'nullable', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'membership_number.required' => 'A membership_number is required.',
            'surname.required_without' => 'Provide either a surname or an email address.',
            'email.required_without' => 'Provide either a surname or an email address.',
        ];
    }

    /**
     * Match the partner API error envelope rather than Laravel's default
     * {message, errors} shape.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'invalid_request',
                'message' => $validator->errors()->first(),
                'fields' => $validator->errors()->toArray(),
            ],
        ], 422));
    }
}
