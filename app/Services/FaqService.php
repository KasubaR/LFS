<?php

namespace App\Services;

use App\Models\Faq;
use App\Support\Uuid;
use Illuminate\Support\Facades\Schema;

class FaqService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getAll(): array
    {
        $query = Faq::query();

        if ($this->hasSortOrder()) {
            $query->orderBy('sort_order')->orderBy('created_at');
        } else {
            $query->orderBy('created_at');
        }

        return $query->get()->map(fn (Faq $faq) => $this->toFaq($faq))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getByCategory(string $category): array
    {
        $query = Faq::query()->where('category', $category);

        if ($this->hasSortOrder()) {
            $query->orderBy('sort_order')->orderBy('created_at');
        } else {
            $query->orderBy('created_at');
        }

        return $query->get()->map(fn (Faq $faq) => $this->toFaq($faq))->all();
    }

    /**
     * @return list<string>
     */
    public function getCategories(): array
    {
        return Faq::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    public function getById(string $id): ?array
    {
        $faq = Faq::query()->find($id);

        return $faq ? $this->toFaq($faq) : null;
    }

    public function getCount(): int
    {
        return Faq::query()->count();
    }

    public function getNextSortOrder(): int
    {
        if (! $this->hasSortOrder()) {
            return $this->getCount() + 1;
        }

        return (int) (Faq::query()->max('sort_order') ?? 0) + 1;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): string
    {
        $requested = (int) ($data['sort_order'] ?? $data['sortOrder'] ?? 0);
        $count = $this->getCount();

        if ($requested <= 0) {
            $sortOrder = $this->getNextSortOrder();
        } else {
            $sortOrder = min($requested, $count + 1);
            $sortOrder = max(1, $sortOrder);
        }

        $id = Uuid::v4();
        $attributes = [
            'id' => $id,
            'question' => trim($data['question'] ?? ''),
            'answer' => trim($data['answer'] ?? ''),
            'category' => $data['category'] ?? null,
        ];

        if ($this->hasSortOrder()) {
            $attributes['sort_order'] = $sortOrder;
        }

        Faq::query()->create($attributes);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): bool
    {
        $requested = (int) ($data['sort_order'] ?? $data['sortOrder'] ?? 0);
        $count = $this->getCount();

        if ($requested <= 0) {
            $sortOrder = $count > 0 ? $count : 1;
        } else {
            $sortOrder = min($requested, $count);
            $sortOrder = max(1, $sortOrder);
        }

        $updates = [
            'question' => trim($data['question'] ?? ''),
            'answer' => trim($data['answer'] ?? ''),
            'category' => $data['category'] ?? null,
        ];

        if ($this->hasSortOrder()) {
            $updates['sort_order'] = $sortOrder;
        }

        return Faq::query()->whereKey($id)->update($updates) > 0;
    }

    public function delete(string $id): bool
    {
        return Faq::query()->whereKey($id)->delete() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function toFaq(Faq $faq): array
    {
        $row = [
            'id' => $faq->id,
            'question' => $faq->question,
            'answer' => $faq->answer,
            'category' => $faq->category,
            'createdAt' => (string) $faq->created_at,
        ];

        if ($this->hasSortOrder()) {
            $row['sortOrder'] = (int) ($faq->sort_order ?? 0);
        }

        return $row;
    }

    private function hasSortOrder(): bool
    {
        static $hasColumn = null;

        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('faqs', 'sort_order');
        }

        return $hasColumn;
    }
}
