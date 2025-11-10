<?php

namespace MSNCB\Core;

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

/**
 * Build a numeric series representing rolling n-gram sums for the provided text.
 *
 * @param string $text
 * @param int $window
 * @return array<float>
 */
function build_ngram_series(string $text, int $window = MSNCB_NGRAM_WINDOW): array
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
 * Calculate an FFT magnitude spectrum for the provided series.
 *
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
            $angle = -2 * \pi() * $k * $n / $window;
            $real += $value * \cos($angle);
            $imag += $value * \sin($angle);
        }
        $magnitude = \sqrt($real * $real + $imag * $imag) / $window;
        $result[$k] = $normaliser > 0.0 ? $magnitude / $normaliser : 0.0;
    }

    if (count($result) < $length) {
        $result = array_merge($result, array_fill(0, $length - count($result), 0.0));
    }

    return array_slice($result, 0, $length);
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
    $text = \strtolower($text);
    $series = build_ngram_series($text);
    $spectrum = fft_magnitude($series, $length);

    if (count($spectrum) < $length) {
        $spectrum = array_merge($spectrum, array_fill(0, $length - count($spectrum), 0.0));
    }

    return array_slice($spectrum, 0, $length);
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
            $angle = 2 * \pi() * $k * $n / $size;
            $value += $spectrum[$k] * \cos($angle);
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
