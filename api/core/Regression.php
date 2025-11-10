<?php

namespace MSNCB\Core;

/**
 * Simple linear regression neuron supporting incremental updates.
 */
class Regression
{
    /** @var array<float> */
    private array $weights;

    private float $bias;

    private float $learningRate;

    public function __construct(int $inputSize, float $learningRate = 0.05, ?array $weights = null, float $bias = 0.0)
    {
        if ($inputSize <= 0) {
            throw new \InvalidArgumentException('inputSize must be positive.');
        }

        $this->learningRate = $learningRate;
        $this->weights = $weights ?? array_fill(0, $inputSize, 0.0);
        if (count($this->weights) !== $inputSize) {
            throw new \InvalidArgumentException('weights size must match inputSize.');
        }
        $this->bias = $bias;
    }

    public function predict(array $input): float
    {
        $sum = $this->bias;
        foreach ($this->weights as $i => $weight) {
            $value = $input[$i] ?? 0.0;
            $sum += $weight * $value;
        }
        return $sum;
    }

    public function update(array $input, float $target): void
    {
        $prediction = $this->predict($input);
        $error = $target - $prediction;
        foreach ($this->weights as $i => $weight) {
            $value = $input[$i] ?? 0.0;
            $this->weights[$i] = $weight + $this->learningRate * $error * $value;
        }
        $this->bias += $this->learningRate * $error;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'weights' => $this->weights,
            'bias' => $this->bias,
            'learningRate' => $this->learningRate,
        ];
    }

    public static function fromArray(array $payload, int $inputSize): self
    {
        $weights = $payload['weights'] ?? array_fill(0, $inputSize, 0.0);
        $bias = $payload['bias'] ?? 0.0;
        $lr = $payload['learningRate'] ?? 0.05;
        return new self($inputSize, (float)$lr, $weights, (float)$bias);
    }

    public function getLearningRate(): float
    {
        return $this->learningRate;
    }

    public function setLearningRate(float $learningRate): void
    {
        $this->learningRate = max(0.0, $learningRate);
    }
}
