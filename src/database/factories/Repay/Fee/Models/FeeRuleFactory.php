<?php

namespace Database\Factories\Repay\Fee\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Repay\Fee\Models\FeeRule;

class FeeRuleFactory extends Factory
{
    protected $model = FeeRule::class;

    public function definition(): array
    {
        return [
            'entity_id' => null,
            'entity_type' => null,
            'fee_type' => 'markup',
            'item_type' => 'product',
            'calculation_type' => 'percentage',
            'value' => $this->faker->randomFloat(2, 1, 30),
            'min_amount' => null,
            'max_amount' => null,
            'is_exclusive' => false,
            'priority' => 0,
            'conditions' => null,
            'effective_from' => null,
            'deactivated_at' => null, // NEW
        ];
    }

    public function forProduct(): self
    {
        return $this->state([
            'item_type' => 'product',
            'fee_type' => 'markup',
        ]);

    }

    public function forService(): self
    {
        return $this->state([
            'item_type' => 'service',
            'fee_type' => $this->faker->randomElement(['commission', 'convenience']),
        ]);
    }

    public function commission(): self
    {
        return $this->state([
            'item_type' => 'service',
            'fee_type' => 'commission',

        ]);
    }

    public function convenience(): self
    {
        return $this->state([
            'item_type' => 'service',
            'fee_type' => 'convenience',
        ]);
    }

    public function percentage(?float $value = null): self
    {
        return $this->state([
            'calculation_type' => 'percentage',

            'value' => $value ?? $this->faker->randomFloat(2, 1, 30),
        ]);
    }

    public function fixed(?float $value = null): self
    {
        return $this->state([
            'calculation_type' => 'fixed',
            'value' => $value ?? $this->faker->randomFloat(2, 1, 10),
        ]);
    }

    public function exclusive(): self
    {
        return $this->state([
            'is_exclusive' => true,
            'priority' => 100,
        ]);
    }

    public function forEntity(string $entityType, int $entityId): self
    {

        return $this->state([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    public function global(): self
    {
        return $this->state([
            'entity_type' => null,
            'entity_id' => null,
        ]);
    }

    public function active(): self
    {
        return $this->state([
            'effective_from' => null,
        ]);
    }

    public function inactive(): self
    {
        return $this->state([
            'deactivated_at' => now(),
        ]);
    }

    public function future(): self
    {
        return $this->state([
            'effective_from' => now()->addDays(rand(1, 30)),

        ]);
    }

    public function scheduled(string $date): self
    {
        return $this->state([
            'effective_from' => $date,
        ]);
    }
}
