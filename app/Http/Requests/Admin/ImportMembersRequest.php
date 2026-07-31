<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImportMembersRequest extends FormRequest
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
            'import_file' => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:10240'],
            'send_welcome_email' => ['nullable', 'boolean'],
        ];
    }
}
