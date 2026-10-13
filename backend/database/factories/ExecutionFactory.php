<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\FlowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Execution>
 */
class ExecutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = FlowVersion::factory()->create();

        return [
            'flow_id' => $version->flow_id,
            'flow_version_id' => $version->id,
            'kestra_execution_id' => fake()->unique()->regexify('[A-Za-z0-9]{22}'),
            'state' => 'RUNNING',
        ];
    }
}
