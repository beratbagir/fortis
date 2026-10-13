<?php

namespace Tests\Unit;

use App\Services\Claude\ConversationHistory;
use App\Services\Claude\CostCalculator;
use PHPUnit\Framework\TestCase;

class ConversationHistoryTest extends TestCase
{
    public function test_replay_keeps_content_unchanged_without_fallback(): void
    {
        $blocks = [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'abc'],
            ['type' => 'text', 'text' => 'Selam'],
        ];

        $this->assertSame($blocks, ConversationHistory::forReplay($blocks));
    }

    public function test_replay_drops_thinking_and_tool_use_before_last_fallback(): void
    {
        $blocks = [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'a'],
            ['type' => 'text', 'text' => 'Yarım'],
            ['type' => 'tool_use', 'id' => 't1', 'name' => 'x', 'input' => []],
            ['type' => 'fallback', 'from' => ['model' => 'claude-sonnet-5-5'], 'to' => ['model' => 'claude-sonnet-5']],
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'b'],
            ['type' => 'text', 'text' => 'Devam'],
        ];

        $this->assertSame(
            ['text', 'fallback', 'thinking', 'text'],
            array_column(ConversationHistory::forReplay($blocks), 'type'),
        );
    }

    public function test_normalize_removes_null_fields_recursively(): void
    {
        $this->assertSame(
            [['type' => 'text', 'text' => 'a', 'meta' => ['b' => 1]]],
            ConversationHistory::normalize([['type' => 'text', 'text' => 'a', 'citations' => null, 'meta' => ['b' => 1, 'c' => null]]]),
        );
    }

    public function test_plain_text_joins_text_blocks_only(): void
    {
        $this->assertSame("Bir\n\nİki", ConversationHistory::plainText([
            ['type' => 'thinking', 'thinking' => 'gizli'],
            ['type' => 'text', 'text' => 'Bir'],
            ['type' => 'text', 'text' => 'İki'],
        ]));
    }

    public function test_cost_includes_cache_reads_and_writes(): void
    {
        $costs = new CostCalculator(['m' => ['input' => 2.0, 'output' => 10.0, 'cache_write' => 2.5, 'cache_read' => 0.2]]);

        $this->assertSame(0.014, $costs->cost('m', 1000, 1000, 400, 5000));
        $this->assertSame(0.0, $costs->cost('bilinmeyen', 1000, 1000));
    }
}
