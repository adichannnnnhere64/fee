<?php

// src/database/factories/Repay/Fee/Models/FeeHistoryFactory.php

namespace Database\Factories\Repay\Fee\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Repay\Fee\Models\FeeHistory;
use Repay\Fee\Models\FeeRule;

class FeeHistoryFactory extends Factory
{
    protected $model = FeeHistory::class;

    public function definition(): array
    {
        $previousRule = FeeRule::factory()->create();

        return [
            'fee_rule_id' => FeeRule::factory(),
            'previous_fee_rule_id' => $previousRule->id,
            'entity_id' => $previousRule->entity_id,
            'entity_type' => $previousRule->entity_type,

            'previous_fee_type' => $previousRule->fee_type,
            'previous_value' => $previousRule->value,
            'previous_calculation_type' => $previousRule->calculation_type,
            'reason' => $this->faker->sentence(),
            'effective_date' => $this->faker->dateTimeBetween('now', '+30 days'),
        ];
    }

    public function forRule(FeeRule $rule): self
    {
        return $this->state([
            'fee_rule_id' => $rule->id,
            'entity_id' => $rule->entity_id,
            'entity_type' => $rule->entity_type,
        ]);

    }

    public function withPreviousRule(FeeRule $previousRule): self
    {
        return $this->state([
            'previous_fee_rule_id' => $previousRule->id,
            'previous_fee_type' => $previousRule->fee_type,
            'previous_value' => $previousRule->value,
            'previous_calculation_type' => $previousRule->calculation_type,
        ]);
    }
}
