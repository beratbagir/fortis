<?php

namespace App\Services\Flows\Blocks;

/**
 * FlowValidator hatalarını tuvaldeki bloklara bağlar. Doğrulayıcı hataları yolla başlar
 * (ör. "tasks[1].tasks[0].uri: ..."); yol boyunca id'si olan en derin görev hatanın bloğudur.
 */
class ErrorLocator
{
    private const PATH = '/^((?:tasks|errors|finally|triggers)(?:\[\d+\]|\.[A-Za-z_][A-Za-z0-9_]*)*)/';

    /**
     * @param  array<string, mixed>|null  $flow
     * @param  list<string>  $errors
     * @return list<array{message: string, task_id: string|null}>
     */
    public static function locate(?array $flow, array $errors): array
    {
        return array_map(fn (string $message) => [
            'message' => $message,
            'task_id' => $flow !== null && preg_match(self::PATH, $message, $m) ? self::taskAt($flow, $m[1]) : null,
        ], $errors);
    }

    /** @param  array<string, mixed>  $flow */
    private static function taskAt(array $flow, string $path): ?string
    {
        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)|\[(\d+)\]/', $path, $segments, PREG_SET_ORDER);

        $node = $flow;
        $found = null;

        foreach ($segments as $segment) {
            $key = ($segment[2] ?? '') !== '' ? (int) $segment[2] : $segment[1];

            if (! is_array($node) || ! array_key_exists($key, $node)) {
                break;
            }

            $node = $node[$key];

            if (is_array($node) && is_string($node['id'] ?? null) && is_string($node['type'] ?? null) && str_contains($node['type'], '.')) {
                $found = $node['id'];
            }
        }

        return $found;
    }
}
