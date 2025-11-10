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

const MSNCB_NGRAM_WINDOW = 3;

/**
 * Return stable frequency mapping for the latin alphabet using binary spacing.
 *
 * @return array<string,int>
 */
function letter_frequency_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $alphabet = range('a', 'z');
    $map = [];
    foreach ($alphabet as $index => $letter) {
        $map[$letter] = 1 << $index; // unique power of two per symbol
    }
    return $map;
}

function max_ngram_frequency(): float
{
    static $max = null;
    if ($max !== null) {
        return $max;
    }

    $frequencies = letter_frequency_map();
    arsort($frequencies);
    $max = 0.0;
    $count = 0;
    foreach ($frequencies as $value) {
        $max += $value;
        $count++;
        if ($count >= MSNCB_NGRAM_WINDOW) {
            break;
        }
    }
    if ($max <= 0.0) {
        $max = 1.0;
    }
    return $max;
}

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
 * Encode text into an FFT-based n-gram frequency vector.
 *
 * @param string $text
 * @param int $length
 * @return array<float>
 */
function encode_text(string $text, int $length): array
{
    $text = strtolower($text);
    $series = build_ngram_series($text, MSNCB_NGRAM_WINDOW);
    $spectrum = fft_magnitude($series, $length);

    if (count($spectrum) < $length) {
        $spectrum = array_merge($spectrum, array_fill(0, $length - count($spectrum), 0.0));
    }

    return array_slice($spectrum, 0, $length);
}

/**
 * @param string $text
 * @param int $window
 * @return array<float>
 */
function build_ngram_series(string $text, int $window): array
{
    $map = letter_frequency_map();
    $length = strlen($text);
    if ($length === 0) {
        return [0.0];
    }

    $series = [];
    for ($i = 0; $i < $length; $i++) {
        $sum = 0.0;
        for ($j = 0; $j < $window; $j++) {
            $index = $i + $j;
            if ($index >= $length) {
                break;
            }
            $char = $text[$index];
            if (isset($map[$char])) {
                $sum += $map[$char];
            }
        }
        $series[] = $sum;
    }

    return $series;
}

/**
 * @param array<float> $series
 * @param int $length
 * @return array<float>
 */
function fft_magnitude(array $series, int $length): array
{
    $window = max($length, count($series));
    if ($window <= 0) {
        return array_fill(0, $length, 0.0);
    }

    $padded = $series;
    if (count($padded) < $window) {
        $padded = array_merge($padded, array_fill(0, $window - count($padded), 0.0));
    }

    $normaliser = max_ngram_frequency();
    $result = [];

    for ($k = 0; $k < $length; $k++) {
        $real = 0.0;
        $imag = 0.0;
        for ($n = 0; $n < $window; $n++) {
            $value = $padded[$n];
            $angle = -2 * pi() * $k * $n / $window;
            $real += $value * cos($angle);
            $imag += $value * sin($angle);
        }
        $magnitude = sqrt($real * $real + $imag * $imag) / $window;
        $result[$k] = $magnitude / $normaliser;
    }

    return $result;
}

/**
 * @param array<float> $spectrum
 * @param int $sequenceLength
 * @return array<float>
 */
function ifft_sequence(array $spectrum, int $sequenceLength): array
{
    $size = max(count($spectrum), 1);
    $sequence = [];
    for ($n = 0; $n < $sequenceLength; $n++) {
        $value = 0.0;
        for ($k = 0; $k < $size; $k++) {
            $angle = 2 * pi() * $k * $n / $size;
            $value += $spectrum[$k] * cos($angle);
        }
        $sequence[$n] = $value;
    }
    return $sequence;
}

/**
 * @param int $sum
 * @param array<string,int> $map
 * @return string
 */
function sum_to_letters(int $sum, array $map): string
{
    if ($sum <= 0) {
        return '';
    }

    arsort($map);
    $letters = [];
    foreach ($map as $letter => $value) {
        if ($sum >= $value) {
            $letters[] = $letter;
            $sum -= $value;
        }
    }

    if (empty($letters)) {
        return '';
    }

    sort($letters);
    return implode('', $letters);
}

function decode_language_outputs(array $language): string
{
    if (empty($language)) {
        return '';
    }

    $sequence = ifft_sequence($language, count($language));
    $map = letter_frequency_map();
    $max = max_ngram_frequency();

    $parts = [];
    foreach ($sequence as $value) {
        $scaled = (int)round(abs($value) * $max);
        $letters = sum_to_letters($scaled, $map);
        if ($letters !== '') {
            $parts[] = $letters;
        }
    }

    return implode(' ', array_slice($parts, 0, 4));
}

/**
 * Generate a decoded reply from language stack outputs.
 *
 * @param array<float> $language
 */
function synthesise_reply(array $language): string
{
    if (empty($language)) {
        return 'Decoded resonance: (silence)';
    }

    $decoded = decode_language_outputs($language);
    if ($decoded === '') {
        return 'Decoded resonance: (silence)';
    }

    return 'Decoded resonance: ' . $decoded;
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
