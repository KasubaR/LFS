<?php

namespace App\Http\Requests;

use App\Enums\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
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
        $isUpdate = $this->isMethod('POST')
            && str_contains((string) $this->path(), '/edit');

        return [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(DocumentCategory::ALL)],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'file' => [
                $isUpdate ? 'nullable' : 'required',
                'file',
                'mimes:pdf,doc,docx',
                'max:20480',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please upload a document file.',
            'file.mimes' => 'Documents must be PDF, DOC, or DOCX.',
            'file.max' => 'Documents may not be larger than 20 MB.',
        ];
    }
}
