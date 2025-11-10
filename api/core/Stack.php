<?php

namespace MSNCB\Core;

/**
 * Represents a stack of regression neurons arranged as a matrix.
 */
class Stack
{
    private string $name;

    private int $inputSize;

    private int $outputSize;

    /** @var array<Regression> */
    private array $regressors = [];

    private float $decay;

    /** @var array<float> */
    private array $state;

    public function __construct(string $name, int $inputSize, int $outputSize, float $learningRate, float $decay = 0.8)
    {
        $this->name = $name;
        $this->inputSize = $inputSize;
        $this->outputSize = $outputSize;
        $this->decay = $decay;
        $this->state = array_fill(0, $outputSize, 0.0);
        for ($i = 0; $i < $outputSize; $i++) {
            $this->regressors[$i] = new Regression($inputSize, $learningRate);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param array<float> $input
     * @return array<float>
     */
    public function forward(array $input): array
    {
        $outputs = [];
        foreach ($this->regressors as $i => $regressor) {
            $prediction = $regressor->predict($input);
            $this->state[$i] = $this->decay * $this->state[$i] + (1 - $this->decay) * $prediction;
            $outputs[$i] = $this->state[$i];
        }
        return $outputs;
    }

    /**
     * @param array<float> $input
     * @param array<float> $target
     */
    public function train(array $input, array $target): void
    {
        foreach ($this->regressors as $i => $regressor) {
            $regressor->update($input, $target[$i] ?? 0.0);
        }
    }

    /**
     * @return array<float>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $regressorArray = [];
        foreach ($this->regressors as $i => $regressor) {
            $regressorArray[$i] = $regressor->toArray();
        }
        return [
            'name' => $this->name,
            'inputSize' => $this->inputSize,
            'outputSize' => $this->outputSize,
            'decay' => $this->decay,
            'regressors' => $regressorArray,
            'state' => $this->state,
        ];
    }

    public static function fromArray(string $name, array $payload): self
    {
        $inputSize = (int)$payload['inputSize'];
        $outputSize = (int)$payload['outputSize'];
        $learningRate = (float)($payload['learningRate'] ?? 0.05);
        $decay = (float)($payload['decay'] ?? 0.8);
        $instance = new self($name, $inputSize, $outputSize, $learningRate, $decay);
        $state = $payload['state'] ?? array_fill(0, $outputSize, 0.0);
        $instance->state = $state;
        if (isset($payload['regressors'])) {
            foreach ($payload['regressors'] as $i => $regData) {
                $instance->regressors[$i] = Regression::fromArray($regData, $inputSize);
            }
        }
        return $instance;
    }
}
