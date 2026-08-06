<?php

namespace App\Http\Requests;

use App\Enums\PromotionDiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromotionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'plan_id' => ['nullable', 'integer', 'exists:membership_plans,id'],
            'discount_type' => ['required', Rule::in(PromotionDiscountType::ALL)],
            'discount_value' => [
                'required',
                'numeric',
                'min:0.01',
                $this->input('discount_type') === PromotionDiscountType::Percentage ? 'max:100' : 'max:1000000',
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'discount_value.max' => 'A percentage discount cannot exceed 100%.',
        ];
    }
}
