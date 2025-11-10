<?php

namespace MSNCB\Core;

class ConversationStore
{
    private string $directory;

    private function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    public static function forPath(string $directory): self
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        return new self($directory);
    }

    /**
     * @return array<int,array{role:string,message:string}>
     */
    public function load(string $conversationId): array
    {
        $path = $this->pathFor($conversationId);
        if (!file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return [];
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || !isset($data['history']) || !is_array($data['history'])) {
            return [];
        }

        $history = [];
        foreach ($data['history'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = isset($entry['role']) ? (string)$entry['role'] : null;
            $message = isset($entry['message']) ? (string)$entry['message'] : null;
            if ($role === null || $message === null) {
                continue;
            }
            $history[] = ['role' => $role, 'message' => $message];
        }
        return $history;
    }

    /**
     * @param array<int,array{role:string,message:string}> $history
     */
    public function save(string $conversationId, array $history): void
    {
        $path = $this->pathFor($conversationId);
        $payload = json_encode(['history' => $history], JSON_PRETTY_PRINT);
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode conversation history.');
        }
        file_put_contents($path, $payload);
    }

    private function pathFor(string $conversationId): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '-', $conversationId);
        if ($safeId === null || $safeId === '' || $safeId === '-') {
            $safeId = sha1($conversationId);
        }
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeId . '.json';
    }
}
