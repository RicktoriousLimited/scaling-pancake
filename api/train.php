<?php

declare(strict_types=1);

require_once __DIR__ . '/core/Regression.php';
require_once __DIR__ . '/core/Stack.php';
require_once __DIR__ . '/core/MultiModal.php';
require_once __DIR__ . '/core/TextCodec.php';

use MSNCB\Core\MultiModal;
use function MSNCB\Core\encode_text;

header('Content-Type: application/json');

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    $stackName = $payload['stack'] ?? 'language';
    $samples = $payload['samples'] ?? [];

    $model = MultiModal::fromConfig(__DIR__ . '/../data/config.json', __DIR__ . '/../data/interconnect.json');

    $inputSize = $model->getInputSize($stackName);
    $outputSize = $model->getOutputSize($stackName);

    if ($inputSize <= 0 || $outputSize <= 0) {
        throw new InvalidArgumentException("Stack {$stackName} has invalid input/output dimensions.");
    }

    foreach ($samples as $index => $sample) {
        if (!is_array($sample)) {
            throw new InvalidArgumentException("Sample at index {$index} is not an object.");
        }

        $hasTextFields = array_key_exists('prompt', $sample) || array_key_exists('targetText', $sample);

        if ($hasTextFields) {
            $prompt = isset($sample['prompt']) ? (string)$sample['prompt'] : '';
            $targetText = isset($sample['targetText']) ? (string)$sample['targetText'] : '';

            if ($prompt === '' || $targetText === '') {
                throw new InvalidArgumentException("Sample {$index} requires non-empty 'prompt' and 'targetText' fields.");
            }

            $input = encode_text($prompt, $inputSize);
            $target = encode_text($targetText, $outputSize);
        } else {
            $input = $sample['input'] ?? null;
            $target = $sample['target'] ?? null;

            if (!is_array($input) || !is_array($target)) {
                throw new InvalidArgumentException("Sample {$index} must provide 'prompt'/'targetText' or numeric 'input'/'target' arrays.");
            }

            if (count($input) !== $inputSize) {
                throw new InvalidArgumentException("Sample {$index} input length does not match stack requirement ({$inputSize}).");
            }

            if (count($target) !== $outputSize) {
                throw new InvalidArgumentException("Sample {$index} target length does not match stack requirement ({$outputSize}).");
            }

            $input = array_map('floatval', $input);
            $target = array_map('floatval', $target);
        }

        $model->trainStack($stackName, $input, $target);
    }

    $model->save(__DIR__ . '/../data/config.json');

    echo json_encode(['status' => 'ok', 'trained' => count($samples)]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
