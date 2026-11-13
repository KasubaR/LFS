<?php

namespace App\Http\Requests;

use App\Enums\PromotionDiscountType;
use App\Models\ProductCategory;
use App\Services\ShopPromotionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShopPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = trim((string) $this->input('code', ''));
        $this->merge(['code' => $code === '' ? null : strtoupper($code)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = (int) $this->route('id');
        $isCode = $this->input('code') !== null;
        // Automatic sales are percentage-only; only codes may be a fixed amount.
        $type = $isCode ? $this->input('discount_type') : PromotionDiscountType::Percentage;
        $max = $type === PromotionDiscountType::Percentage ? ShopPromotionService::MAX_PERCENTAGE : 1000000;

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'nullable', 'string', 'regex:/^[A-Z0-9-]{3,30}$/',
                Rule::unique('shop_promotions', 'code')->ignore($id ?: null),
            ],
            'discount_type' => [$isCode ? 'required' : 'nullable', Rule::in(PromotionDiscountType::ALL)],
            'discount_value' => ['required', 'numeric', 'min:0.01', 'max:'.$max],
            'category' => ['nullable', 'string', Rule::exists(ProductCategory::class, 'slug')],
            'members_only' => ['nullable', 'boolean'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
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
            'code.regex' => 'Use 3 to 30 letters, numbers or dashes for the code, with no spaces.',
            'code.unique' => 'That code is already in use.',
            'discount_value.max' => $this->input('code') !== null && $this->input('discount_type') === PromotionDiscountType::Fixed
                ? 'That amount is too large.'
                : 'A percentage discount cannot be more than '.ShopPromotionService::MAX_PERCENTAGE.'%, so the shop is never given away free.',
        ];
    }
}
