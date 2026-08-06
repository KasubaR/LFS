<?php

namespace Database\Factories;

use App\Enums\TShirtSize;
use App\Models\Satellite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * A complete profile by default (see User::hasCompleteProfile()) — tests
     * exercising the "must complete profile" gate opt into an incomplete one
     * explicitly rather than every other test having to opt into a complete
     * one. satellite_id falls back to null when no Satellite rows exist yet
     * (e.g. the test hasn't seeded SatelliteSeeder) since we can't invent one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'last_name' => fake()->lastName(),
            'other_names' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'phone' => fake()->numerify('09#######'),
            'gender' => fake()->randomElement(['male', 'female']),
            't_shirt_size' => fake()->randomElement(TShirtSize::ALL),
            'satellite_id' => Satellite::query()->value('id'),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Missing the fields User::hasCompleteProfile() checks for — for testing
     * the profile-completion gate itself.
     */
    public function incompleteProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'gender' => null,
            'phone' => null,
            't_shirt_size' => null,
            'satellite_id' => null,
            'other_names' => null,
        ]);
    }
}
