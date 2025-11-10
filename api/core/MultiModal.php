<?php

namespace MSNCB\Core;

class MultiModal
{
    /** @var array<string, Stack> */
    private array $stacks = [];

    /** @var array<string,array<string,float>> */
    private array $interconnect = [];

    /** @var array<string,int> */
    private array $inputSizes = [];

    /** @var array<string,int> */
    private array $outputSizes = [];

    public static function fromConfig(string $configPath, string $interconnectPath): self
    {
        $config = json_decode((string)file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
        $interconnect = json_decode((string)file_get_contents($interconnectPath), true, 512, JSON_THROW_ON_ERROR);

        $instance = new self();
        foreach ($config['stacks'] as $name => $definition) {
            $stack = Stack::fromArray($name, $definition);
            $instance->stacks[$name] = $stack;
            $instance->inputSizes[$name] = (int)$definition['inputSize'];
            $instance->outputSizes[$name] = (int)$definition['outputSize'];
        }

        $instance->interconnect = $interconnect['connections'] ?? [];
        return $instance;
    }

    public function getInputSize(string $stackName): int
    {
        return $this->inputSizes[$stackName] ?? 0;
    }

    public function getOutputSize(string $stackName): int
    {
        return $this->outputSizes[$stackName] ?? 0;
    }

    /**
     * Process input for a target stack and collect all stack outputs.
     *
     * @param string $entryStack
     * @param array<float> $input
     * @return array<string,array<float>>
     */
    public function propagate(string $entryStack, array $input): array
    {
        if (!isset($this->stacks[$entryStack])) {
            throw new \InvalidArgumentException("Unknown stack {$entryStack}");
        }

        $visited = [];
        $context = [$entryStack => $this->stacks[$entryStack]->forward($input)];
        $this->propagateRecursive($entryStack, $context, $visited);
        return $context;
    }

    /**
     * @param string $stackName
     * @param array<string,array<float>> $context
     */
    private function propagateRecursive(string $stackName, array &$context, array &$visited): void
    {
        $visited[$stackName] = true;
        $outputs = $context[$stackName];
        foreach ($this->interconnect[$stackName] ?? [] as $target => $weight) {
            if (!isset($this->stacks[$target])) {
                continue;
            }
            if (isset($visited[$target])) {
                continue;
            }
            $augmentedInput = $this->buildInput($target, $outputs, $weight, $context);
            $context[$target] = $this->stacks[$target]->forward($augmentedInput);
            $this->propagateRecursive($target, $context, $visited);
        }
    }

    /**
     * @param string $target
     * @param array<float> $sourceOutputs
     * @param float $weight
     * @param array<string,array<float>> $context
     * @return array<float>
     */
    private function buildInput(string $target, array $sourceOutputs, float $weight, array $context): array
    {
        $inputSize = $this->inputSizes[$target];
        $base = $context[$target] ?? [];
        if (!is_array($base)) {
            $base = [];
        }

        $base = array_values($base);
        if (count($base) < $inputSize) {
            $base = array_merge($base, array_fill(0, $inputSize - count($base), 0.0));
        }

        foreach ($sourceOutputs as $value) {
            $base[] = $value * $weight;
        }

        if (count($base) > $inputSize) {
            $base = array_slice($base, -$inputSize);
        }

        return $base;
    }

    /**
     * Train a specific stack given an input and target vector.
     *
     * @param string $stackName
     * @param array<float> $input
     * @param array<float> $target
     */
    public function trainStack(string $stackName, array $input, array $target): void
    {
        if (!isset($this->stacks[$stackName])) {
            throw new \InvalidArgumentException("Unknown stack {$stackName}");
        }
        $this->stacks[$stackName]->train($input, $target);
    }

    /**
     * Persist current stack states.
     */
    public function save(string $configPath): void
    {
        $payload = ['stacks' => []];
        foreach ($this->stacks as $name => $stack) {
            $payload['stacks'][$name] = $stack->toArray();
        }
        file_put_contents($configPath, json_encode($payload, JSON_PRETTY_PRINT));
    }
}
