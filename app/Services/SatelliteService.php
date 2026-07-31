<?php

namespace App\Services;

use App\Models\Satellite;

class SatelliteService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getActiveSatellites(): array
    {
        return Satellite::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Satellite $satellite) => $this->toSatellite($satellite))
            ->all();
    }

    public function findBySlug(string $slug): ?array
    {
        $satellite = Satellite::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        return $satellite ? $this->toSatellite($satellite) : null;
    }

    public function findById(int $id): ?array
    {
        $satellite = Satellite::query()->find($id);

        return $satellite ? $this->toSatellite($satellite) : null;
    }

    public function findByName(string $name): ?array
    {
        $normalized = strtolower(trim($name));
        $aliases = [
            'north-side' => 'north side',
            'northside' => 'north side',
            'south-side' => 'south side',
            'southside' => 'south side',
            'chamba valley' => 'chamba valley',
        ];

        $lookup = $aliases[$normalized] ?? trim($name);

        $satellite = Satellite::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($lookup)])
            ->where('is_active', true)
            ->first();

        return $satellite ? $this->toSatellite($satellite) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toSatellite(Satellite $satellite): array
    {
        return [
            'id' => $satellite->id,
            'name' => $satellite->name,
            'town' => $satellite->town,
            'slug' => $satellite->slug,
            'isActive' => (bool) $satellite->is_active,
        ];
    }
}
