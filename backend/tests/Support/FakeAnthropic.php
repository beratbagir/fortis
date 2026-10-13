<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Anthropic SDK'sına verilen sahte HTTP taşıyıcısı. Gerçek SDK ve ClaudeChat çalışır; ağa çıkılmaz.
 * Her isteğin gövdesi kaydedilir (Claude'a ne gittiğini test etmek için), yanıtlar sıradan SSE olarak üretilir.
 */
class FakeAnthropic implements ClientInterface
{
    /** @var list<array<string, mixed>> Gönderilen istek gövdeleri (JSON çözülmüş) */
    public array $requests = [];

    /** @var list<string> Ham istek gövdeleri */
    public array $rawRequests = [];

    /** @var list<array<string, mixed>> Akışsız kısa istekler (sohbet başlığı); sohbet istekleri arasında sayılmaz. */
    public array $otherRequests = [];

    /** @var list<array{content: list<array<string, mixed>>, stop_reason: string}> */
    private array $queue = [];

    /**
     * Sıradaki yanıt: araç çağrıları.
     *
     * @param  list<array{name: string, input: array<string, mixed>}>  $calls
     */
    public function toolCalls(array $calls): self
    {
        $this->queue[] = [
            'content' => array_map(fn (array $call, int $i) => ['type' => 'tool_use', 'id' => 'toolu_'.count($this->queue).'_'.$i, 'name' => $call['name'], 'input' => $call['input']], $calls, array_keys($calls)),
            'stop_reason' => 'tool_use',
        ];

        return $this;
    }

    /** Sıradaki yanıt: düz metin. */
    public function text(string $text): self
    {
        $this->queue[] = ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn'];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $raw = (string) $request->getBody();
        $decoded = json_decode($raw, true) ?? [];

        // Sohbet istekleri akışlıdır; akışsız kısa istekler (başlık) sabit bir cevap alır. Yapılandırılmış çıktı isteyen
        // akışsız istekler (değerlendirme puanı) sıradaki yanıtı alır.
        if (! ($decoded['stream'] ?? false)) {
            $this->otherRequests[] = $decoded;

            if ((isset($decoded['tool_choice']) || isset($decoded['output_config']['format'])) && $this->queue !== []) {
                $next = array_shift($this->queue);

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'id' => 'msg_eval', 'type' => 'message', 'role' => 'assistant', 'model' => $decoded['model'] ?? 'claude-sonnet-5-5',
                    'content' => $next['content'], 'stop_reason' => $next['stop_reason'], 'stop_sequence' => null,
                    'usage' => ['input_tokens' => 300, 'output_tokens' => 60],
                ]));
            }

            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'id' => 'msg_title', 'type' => 'message', 'role' => 'assistant', 'model' => $decoded['model'] ?? 'claude-sonnet-5-5',
                'content' => [['type' => 'text', 'text' => 'Test sohbeti başlığı']], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
                'usage' => ['input_tokens' => 20, 'output_tokens' => 5],
            ]));
        }

        $this->rawRequests[] = $raw;
        $this->requests[] = json_decode($raw, true) ?? [];

        $next = array_shift($this->queue) ?? throw new RuntimeException('FakeAnthropic: sırada yanıt yok.');
        $model = $this->requests[array_key_last($this->requests)]['model'] ?? 'claude-sonnet-5-5';

        return new Response(200, ['Content-Type' => 'text/event-stream'], $this->sse($next, $model));
    }

    /** @param  array{content: list<array<string, mixed>>, stop_reason: string}  $message */
    private function sse(array $message, string $model): string
    {
        $events = [['message_start', ['type' => 'message_start', 'message' => [
            'id' => 'msg_'.count($this->requests), 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => [],
            'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 100, 'output_tokens' => 1],
        ]]]];

        foreach ($message['content'] as $index => $block) {
            if ($block['type'] === 'text') {
                $events[] = ['content_block_start', ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']]];
                $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]]];
            } else {
                $events[] = ['content_block_start', ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => new \stdClass]]];
                $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'] ?: new \stdClass, JSON_UNESCAPED_UNICODE)]]];
            }
            $events[] = ['content_block_stop', ['type' => 'content_block_stop', 'index' => $index]];
        }

        $events[] = ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => $message['stop_reason'], 'stop_sequence' => null], 'usage' => ['output_tokens' => 20]]];
        $events[] = ['message_stop', ['type' => 'message_stop']];

        return implode('', array_map(fn (array $e) => "event: {$e[0]}\ndata: ".json_encode($e[1], JSON_UNESCAPED_UNICODE)."\n\n", $events));
    }
}
