<?php

namespace Database\Factories;

use App\Models\FeatureRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureRequest>
 */
class FeatureRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request' => fake()->sentence(),
            'missing_capability' => 'Python script',
            'reason' => 'Script görevleri yasak.',
            'status' => 'open',
        ];
    }
}
