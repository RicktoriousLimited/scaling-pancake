<?php

declare(strict_types=1);

require_once __DIR__ . '/core/Regression.php';
require_once __DIR__ . '/core/Stack.php';
require_once __DIR__ . '/core/MultiModal.php';
require_once __DIR__ . '/core/Signal.php';

use MSNCB\Core\MultiModal;

header('Content-Type: application/json');

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    $stackName = $payload['stack'] ?? 'language';
    $samples = $payload['samples'] ?? [];

    $model = MultiModal::fromConfig(__DIR__ . '/../data/config.json', __DIR__ . '/../data/interconnect.json');

    $inputSize = $model->getInputSize($stackName);
    $outputSize = $model->getOutputSize($stackName);

    foreach ($samples as $index => $sample) {
        if (!is_array($sample)) {
            throw new InvalidArgumentException("Sample at index {$index} is not an object.");
        }

        $input = $sample['input'] ?? null;
        $target = $sample['target'] ?? null;

        if (!is_array($input)) {
            $prompt = isset($sample['prompt']) ? (string)$sample['prompt'] : '';
            if ($prompt === '') {
                throw new InvalidArgumentException("Sample {$index} is missing an input vector or prompt text.");
            }
            if ($inputSize <= 0) {
                throw new InvalidArgumentException("Stack {$stackName} has no configured input size.");
            }
            $input = encode_text($prompt, $inputSize);
        } elseif ($inputSize > 0 && count($input) !== $inputSize) {
            throw new InvalidArgumentException("Sample {$index} input length (" . count($input) . ") does not match stack requirement ({$inputSize}).");
        }

        if (!is_array($target)) {
            $targetText = '';
            if (isset($sample['targetText'])) {
                $targetText = (string)$sample['targetText'];
            } elseif (isset($sample['target']) && is_string($sample['target'])) {
                $targetText = $sample['target'];
            }
            if ($targetText === '') {
                throw new InvalidArgumentException("Sample {$index} is missing a target vector or target text.");
            }
            if ($outputSize <= 0) {
                throw new InvalidArgumentException("Stack {$stackName} has no configured output size.");
            }
            $target = encode_text($targetText, $outputSize);
        } elseif ($outputSize > 0 && count($target) !== $outputSize) {
            throw new InvalidArgumentException("Sample {$index} target length (" . count($target) . ") does not match stack requirement ({$outputSize}).");
        }

        $model->trainStack($stackName, $input, $target);
    }

    $model->save(__DIR__ . '/../data/config.json');

    echo json_encode(['status' => 'ok', 'trained' => count($samples)]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
