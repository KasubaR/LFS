<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SizeGuide;

class SizeGuideService
{
    public const MAX_COLUMNS = 6;

    public const MAX_ROWS = 30;

    /**
     * @return list<array{id: int, name: string, columns: list<string>, rowCount: int, productCount: int}>
     */
    public function getAllWithCounts(): array
    {
        $counts = Product::query()
            ->whereNotNull('size_guide_id')
            ->selectRaw('size_guide_id, COUNT(*) as total')
            ->groupBy('size_guide_id')
            ->pluck('total', 'size_guide_id');

        return SizeGuide::query()
            ->orderBy('name')
            ->get()
            ->map(fn (SizeGuide $g): array => [
                'id' => (int) $g->id,
                'name' => $g->name,
                'columns' => (array) $g->columns,
                'rowCount' => count((array) $g->rows),
                'productCount' => (int) ($counts[$g->id] ?? 0),
            ])
            ->all();
    }

    /**
     * id => name, for the product form.
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        return SizeGuide::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function exists(int $id): bool
    {
        return SizeGuide::query()->whereKey($id)->exists();
    }

    /**
     * @return array{id: int, name: string, note: string, columns: list<string>, rows: list<list<string>>}|null
     */
    public function getById(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $guide = SizeGuide::query()->find($id);
        if ($guide === null) {
            return null;
        }

        return [
            'id' => (int) $guide->id,
            'name' => $guide->name,
            'note' => (string) ($guide->note ?? ''),
            'columns' => array_values((array) $guide->columns),
            'rows' => array_values(array_map('array_values', (array) $guide->rows)),
        ];
    }

    /**
     * Turns the posted editor fields into clean columns/rows: trims cells,
     * drops rows with no size, and pads/truncates each row to the column count.
     *
     * @param  array<int, mixed>  $columns
     * @param  array<int, mixed>  $rows
     * @return array{columns: list<string>, rows: list<list<string>>}
     */
    public function normaliseTable(array $columns, array $rows): array
    {
        $cols = array_values(array_map(fn ($c): string => trim((string) $c), $columns));

        $clean = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = array_values(array_map(fn ($c): string => trim((string) $c), $row));
            if (($cells[0] ?? '') === '') {
                continue;
            }
            $clean[] = array_slice(array_pad($cells, count($cols) + 1, ''), 0, count($cols) + 1);
        }

        return ['columns' => $cols, 'rows' => $clean];
    }

    /**
     * @param  array{name: string, note?: ?string, columns: list<string>, rows: list<list<string>>}  $data
     */
    public function create(array $data): SizeGuide
    {
        return SizeGuide::query()->create($this->payload($data));
    }

    /**
     * @param  array{name: string, note?: ?string, columns: list<string>, rows: list<list<string>>}  $data
     */
    public function update(int $id, array $data): bool
    {
        $guide = SizeGuide::query()->find($id);
        if ($guide === null) {
            return false;
        }

        $guide->update($this->payload($data));

        return true;
    }

    /**
     * @return int|null  null once deleted, otherwise how many products still use it
     */
    public function delete(int $id): ?int
    {
        $guide = SizeGuide::query()->find($id);
        if ($guide === null) {
            return null;
        }

        $inUse = Product::query()->where('size_guide_id', $id)->count();
        if ($inUse > 0) {
            return $inUse;
        }

        $guide->delete();

        return null;
    }

    /**
     * @param  array{name: string, note?: ?string, columns: list<string>, rows: list<list<string>>}  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $note = trim((string) ($data['note'] ?? ''));

        return [
            'name' => trim($data['name']),
            'note' => $note !== '' ? $note : null,
            'columns' => $data['columns'],
            'rows' => $data['rows'],
        ];
    }
}
