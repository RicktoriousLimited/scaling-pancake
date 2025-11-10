<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MSNCB\Core\ConversationStore;
use MSNCB\Core\MultiModal;
use MSNCB\Core\Regression;
use MSNCB\Core\Stack;

class AssertionFailed extends \RuntimeException {}

final class Assert
{
    public function equals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $msg = $message ?: sprintf('Failed asserting that %s matches expected %s.', var_export($actual, true), var_export($expected, true));
            throw new AssertionFailed($msg);
        }
    }

    public function floatEquals(float $expected, float $actual, float $tolerance = 1e-6, string $message = ''): void
    {
        if (abs($expected - $actual) > $tolerance) {
            $msg = $message ?: sprintf('Failed asserting that %.10f matches expected %.10f (±%.10f).', $actual, $expected, $tolerance);
            throw new AssertionFailed($msg);
        }
    }

    public function true(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new AssertionFailed($message ?: 'Failed asserting that condition is true.');
        }
    }

    public function greaterThan(float $threshold, float $actual, string $message = ''): void
    {
        if (!($actual > $threshold)) {
            $msg = $message ?: sprintf('Failed asserting that %.10f is greater than %.10f.', $actual, $threshold);
            throw new AssertionFailed($msg);
        }
    }

    public function lessThan(float $threshold, float $actual, string $message = ''): void
    {
        if (!($actual < $threshold)) {
            $msg = $message ?: sprintf('Failed asserting that %.10f is less than %.10f.', $actual, $threshold);
            throw new AssertionFailed($msg);
        }
    }

    public function stringContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            $msg = $message ?: sprintf('Failed asserting that "%s" contains "%s".', $haystack, $needle);
            throw new AssertionFailed($msg);
        }
    }

    public function fail(string $message): void
    {
        throw new AssertionFailed($message);
    }
}

final class TestRunner
{
    /** @var array<int,array{string,callable(Assert):void}> */
    private array $tests = [];

    public function add(string $name, callable $test): void
    {
        $this->tests[] = [$name, $test];
    }

    public function run(): bool
    {
        $assert = new Assert();
        $failures = [];

        foreach ($this->tests as [$name, $test]) {
            try {
                $test($assert);
                printf("✔ %s\n", $name);
            } catch (AssertionFailed $failure) {
                $failures[] = [$name, $failure->getMessage()];
                printf("✘ %s\n  ↳ %s\n", $name, $failure->getMessage());
            } catch (\Throwable $throwable) {
                $failures[] = [$name, $throwable->getMessage()];
                printf("✘ %s\n  ↳ Unexpected error: %s\n", $name, $throwable->getMessage());
            }
        }

        if ($failures) {
            printf("\n%d test(s) failed.\n", count($failures));
            return false;
        }

        printf("\nAll %d test(s) passed.\n", count($this->tests));
        return true;
    }
}

$runner = new TestRunner();

$runner->add('Regression updates reduce absolute error', function (Assert $assert): void {
    $regression = new Regression(2, 0.5);
    $input = [0.5, -0.25];
    $target = 0.75;
    $before = abs($target - $regression->predict($input));
    $regression->update($input, $target);
    $after = abs($target - $regression->predict($input));
    $assert->true($after < $before, 'Regression update should reduce absolute error for the training sample.');
});

$runner->add('Regression constructor validates positive input size', function (Assert $assert): void {
    try {
        new Regression(0);
        $assert->fail('Regression should reject a zero input size.');
    } catch (\InvalidArgumentException $exception) {
        $assert->stringContains('inputSize', $exception->getMessage());
    }
});

$runner->add('Stack forward applies decay to stateful output', function (Assert $assert): void {
    $stack = Stack::fromArray('decay', [
        'inputSize' => 1,
        'outputSize' => 1,
        'learningRate' => 0.05,
        'decay' => 0.5,
        'state' => [0.0],
        'regressors' => [
            [
                'weights' => [1.0],
                'bias' => 0.0,
                'learningRate' => 0.05,
            ],
        ],
    ]);

    $first = $stack->forward([1.0])[0];
    $assert->floatEquals(0.5, $first, 1e-6, 'First forward pass should blend prediction with empty state.');
    $second = $stack->forward([0.0])[0];
    $assert->floatEquals(0.25, $second, 1e-6, 'Second forward pass should decay the stored activation.');
});

$runner->add('MultiModal propagation shares activations across stacks', function (Assert $assert): void {
    $configPath = __DIR__ . '/fixtures/config.json';
    $interconnectPath = __DIR__ . '/fixtures/interconnect.json';
    $engine = MultiModal::fromConfig($configPath, $interconnectPath);
    $result = $engine->propagate('language', [1.0, 1.0]);

    $assert->true(isset($result['language']), 'Language stack output should be present.');
    $assert->true(isset($result['context']), 'Context stack output should be present.');
    $assert->floatEquals(1.0, $result['language'][0], 1e-6, 'Language stack should emit averaged activation.');
    $assert->floatEquals(0.5, $result['context'][0], 1e-6, 'Context stack should receive weighted input from language.');
});

$runner->add('MultiModal training updates stack predictions', function (Assert $assert): void {
    $configPath = __DIR__ . '/fixtures/config.json';
    $interconnectPath = __DIR__ . '/fixtures/interconnect.json';
    $engine = MultiModal::fromConfig($configPath, $interconnectPath);

    $input = [1.0, 1.0];
    $target = [2.0];
    $before = $engine->propagate('language', $input)['language'][0];
    $engine->trainStack('language', $input, $target);
    $after = $engine->propagate('language', $input)['language'][0];
    $assert->greaterThan($before, $after, 'Language stack prediction should increase after seeing a larger target.');
});

$runner->add('MultiModal save writes stack configuration to disk', function (Assert $assert): void {
    $configPath = __DIR__ . '/fixtures/config.json';
    $interconnectPath = __DIR__ . '/fixtures/interconnect.json';
    $engine = MultiModal::fromConfig($configPath, $interconnectPath);

    $tempFile = tempnam(sys_get_temp_dir(), 'msncb_config_');
    if ($tempFile === false) {
        $assert->fail('Failed to create temporary file for save test.');
    }

    $engine->save($tempFile);
    $contents = json_decode((string)file_get_contents($tempFile), true);
    unlink($tempFile);

    $assert->true(isset($contents['stacks']['language']), 'Saved configuration must include the language stack.');
    $assert->true(isset($contents['stacks']['context']), 'Saved configuration must include the context stack.');
});

$runner->add('ConversationStore saves and loads sanitized transcripts', function (Assert $assert): void {
    $tempDir = sys_get_temp_dir() . '/msncb_conversations_' . bin2hex(random_bytes(4));
    $store = ConversationStore::forPath($tempDir);

    $history = [
        ['role' => 'user', 'message' => 'Hello there'],
        ['role' => 'assistant', 'message' => 'General Kenobi'],
    ];

    $conversationId = 'test/../strange';
    $store->save($conversationId, $history);
    $loaded = $store->load($conversationId);
    $assert->equals($history, $loaded, 'Loaded history should match what was saved.');

    $files = glob($tempDir . '/*.json') ?: [];
    $assert->equals(1, count($files), 'Conversation directory should contain exactly one JSON file.');

    // Clean up temporary directory.
    foreach ($files as $file) {
        unlink($file);
    }
    rmdir($tempDir);
});

if (!$runner->run()) {
    exit(1);
}
