<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->sentence();

        return [
            'conversation_id' => Conversation::factory(),
            'role' => Message::ROLE_USER,
            'content' => [['type' => 'text', 'text' => $text]],
            'text' => $text,
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn () => [
            'role' => Message::ROLE_ASSISTANT,
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'end_turn',
        ]);
    }
}
