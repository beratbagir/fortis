<?php

namespace Database\Factories;

use App\Models\Flow;
use App\Models\FlowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowVersion>
 */
class FlowVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $yaml = "id: example\nnamespace: fortis.chat\ntasks:\n  - id: hello\n    type: io.kestra.plugin.core.log.Log\n    message: Merhaba\n";

        return [
            'flow_id' => Flow::factory(),
            'number' => 1,
            'title' => fake()->sentence(3),
            'summary' => [fake()->sentence()],
            'yaml' => $yaml,
            'yaml_hash' => hash('sha256', $yaml),
            'secrets_used' => [],
            'card' => [],
            'validation' => ['valid' => true, 'errors' => [], 'warnings' => []],
        ];
    }
}
