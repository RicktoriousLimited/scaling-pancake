<?php

declare(strict_types=1);

require_once __DIR__ . '/core/Regression.php';
require_once __DIR__ . '/core/Stack.php';
require_once __DIR__ . '/core/MultiModal.php';
require_once __DIR__ . '/core/ConversationStore.php';

use MSNCB\Core\MultiModal;
use MSNCB\Core\ConversationStore;

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input') ?: '[]', true);
$message = $input['message'] ?? '';
$entry = $input['entryStack'] ?? 'language';
$conversationId = isset($input['conversationId']) ? (string)$input['conversationId'] : null;
$seedHistory = normalise_history_seed($input['history'] ?? []);

try {
    if ($conversationId === null && !empty($seedHistory)) {
        try {
            $conversationId = bin2hex(random_bytes(8));
        } catch (Throwable $randomError) {
            $conversationId = uniqid('conv-', true);
        }
    }

    $model = MultiModal::fromConfig(__DIR__ . '/../data/config.json', __DIR__ . '/../data/interconnect.json');
    $inputSize = $model->getInputSize($entry) ?: 32;
    $vector = encode_text($message, $inputSize);

    $store = null;
    $history = [];
    if ($conversationId !== null) {
        $store = ConversationStore::forPath(__DIR__ . '/../data/conversations');
        $history = $store->load($conversationId);
    }
    if (!empty($seedHistory)) {
        $history = array_merge($history, $seedHistory);
    }

    if (!empty($history)) {
        $memoryVector = summarise_history($history, $inputSize);
        $vector = blend_with_memory($vector, $memoryVector);
    }

    $outputs = $model->propagate($entry, $vector);
    $reply = synthesise_reply($outputs['language'] ?? []);

    if ($conversationId !== null && $store !== null) {
        if ($message !== '') {
            $history[] = ['role' => 'user', 'message' => $message];
            $history[] = ['role' => 'assistant', 'message' => $reply];
        }
        $store->save($conversationId, $history);
    }

    echo json_encode([
        'message' => $reply,
        'outputs' => $outputs,
        'conversationId' => $conversationId,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Encode text into normalized ASCII vector.
 *
 * @param string $text
 * @param int $length
 * @return array<float>
 */
function encode_text(string $text, int $length): array
{
    $text = substr($text, 0, $length);
    $vector = [];
    for ($i = 0; $i < $length; $i++) {
        $char = $text[$i] ?? "\0";
        $vector[$i] = ord($char) / 127;
    }
    return $vector;
}

/**
 * Generate a tone-based reply from language stack outputs.
 *
 * @param array<float> $language
 */
function synthesise_reply(array $language): string
{
    if (empty($language)) {
        return '...';
    }
    $tone = array_sum($language) / max(count($language), 1);
    if ($tone > 0.6) {
        return 'I am feeling positive about that!';
    }
    if ($tone < -0.2) {
        return 'That makes me a bit uncertain, but I am learning.';
    }
    return 'Thanks for sharing—let me think on it.';
}

/**
 * @param array<int,array{role:string,message:string}> $history
 * @return array<float>
 */
function summarise_history(array $history, int $length): array
{
    if (empty($history)) {
        return array_fill(0, $length, 0.0);
    }

    $aggregate = array_fill(0, $length, 0.0);
    $weightTotal = 0.0;

    foreach ($history as $entry) {
        $encoded = encode_text($entry['message'], $length);
        $weight = $entry['role'] === 'assistant' ? 0.8 : 1.0;
        foreach ($encoded as $i => $value) {
            $aggregate[$i] += $value * $weight;
        }
        $weightTotal += $weight;
    }

    if ($weightTotal <= 0.0) {
        return array_fill(0, $length, 0.0);
    }

    foreach ($aggregate as $i => $value) {
        $aggregate[$i] = $value / $weightTotal;
    }

    return $aggregate;
}

/**
 * @param array<float> $current
 * @param array<float> $memory
 * @return array<float>
 */
function blend_with_memory(array $current, array $memory): array
{
    $blend = [];
    $count = max(count($current), count($memory));
    for ($i = 0; $i < $count; $i++) {
        $c = $current[$i] ?? 0.0;
        $m = $memory[$i] ?? 0.0;
        $blend[$i] = 0.7 * $c + 0.3 * $m;
    }
    return $blend;
}

/**
 * @param mixed $rawHistory
 * @return array<int,array{role:string,message:string}>
 */
function normalise_history_seed($rawHistory): array
{
    if (!is_array($rawHistory)) {
        return [];
    }

    $normalised = [];
    foreach ($rawHistory as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $role = isset($entry['role']) ? (string)$entry['role'] : '';
        $message = isset($entry['message']) ? (string)$entry['message'] : '';
        if ($role === '' || $message === '') {
            continue;
        }
        if ($role !== 'user' && $role !== 'assistant') {
            continue;
        }
        $normalised[] = ['role' => $role, 'message' => $message];
    }
    return $normalised;
}
