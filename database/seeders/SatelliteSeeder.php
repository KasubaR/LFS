<?php

namespace Database\Seeders;

use App\Models\Satellite;
use Illuminate\Database\Seeder;

class SatelliteSeeder extends Seeder
{
    public function run(): void
    {
        $satellites = [
            ['name' => 'Arcades', 'slug' => 'arcades', 'town' => 'Lusaka'],
            ['name' => 'Avondale', 'slug' => 'avondale', 'town' => 'Lusaka'],
            ['name' => 'Chamba Valley', 'slug' => 'chamba-valley', 'town' => 'Lusaka'],
            ['name' => 'Woodies', 'slug' => 'woodies', 'town' => 'Lusaka'],
            ['name' => 'North Side', 'slug' => 'north-side', 'town' => 'Lusaka'],
            ['name' => 'South Side', 'slug' => 'south-side', 'town' => 'Lusaka'],
            ['name' => 'Virtual', 'slug' => 'virtual', 'town' => 'Virtual'],
        ];

        foreach ($satellites as $satellite) {
            Satellite::query()->updateOrCreate(
                ['slug' => $satellite['slug']],
                [
                    'name' => $satellite['name'],
                    'town' => $satellite['town'],
                    'is_active' => true,
                ]
            );
        }
    }
}
