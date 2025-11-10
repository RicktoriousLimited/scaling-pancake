<?php

declare(strict_types=1);

require_once __DIR__ . '/core/Regression.php';
require_once __DIR__ . '/core/Stack.php';
require_once __DIR__ . '/core/MultiModal.php';

use MSNCB\Core\MultiModal;

header('Content-Type: application/json');

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    $stackName = $payload['stack'] ?? 'language';
    $samples = $payload['samples'] ?? [];

    $model = MultiModal::fromConfig(__DIR__ . '/../data/config.json', __DIR__ . '/../data/interconnect.json');

    foreach ($samples as $sample) {
        $input = $sample['input'] ?? [];
        $target = $sample['target'] ?? [];
        $model->trainStack($stackName, $input, $target);
    }

    $model->save(__DIR__ . '/../data/config.json');

    echo json_encode(['status' => 'ok', 'trained' => count($samples)]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
