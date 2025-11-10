<?php

declare(strict_types=1);

require_once __DIR__ . '/core/Regression.php';
require_once __DIR__ . '/core/Stack.php';
require_once __DIR__ . '/core/MultiModal.php';

use MSNCB\Core\MultiModal;

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input') ?: '[]', true);
$message = $input['message'] ?? '';
$entry = $input['entryStack'] ?? 'language';

try {
    $model = MultiModal::fromConfig(__DIR__ . '/../data/config.json', __DIR__ . '/../data/interconnect.json');
    $inputSize = $model->getInputSize($entry) ?: 32;
    $vector = encode_text($message, $inputSize);
    $outputs = $model->propagate($entry, $vector);
    $reply = synthesise_reply($outputs['language'] ?? []);

    echo json_encode([
        'message' => $reply,
        'outputs' => $outputs,
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
