<?php

namespace Database\Factories;

use App\Models\Flow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flow>
 */
class FlowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'namespace' => 'fortis.chat',
            'flow_id' => fake()->unique()->slug(2),
            'title' => fake()->sentence(3),
            'summary' => [fake()->sentence()],
            'status' => Flow::STATUS_DRAFT,
        ];
    }
}
