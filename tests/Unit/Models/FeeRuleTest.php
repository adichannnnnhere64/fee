<?php

use Repay\Fee\Enums\CalculationType;
use Repay\Fee\Models\FeeRule;

it('formats flat value correctly', function () {

    $fee = FeeRule::factory()->create([
        'value' => 20,
        'calculation_type' => CalculationType::FLAT,
    ]);

    expect($fee->formatted_value)->toBe('20.00');
});

it('formats percentage value correctly', function () {
    $fee = FeeRule::factory()->create([
        'value' => 5,
        'calculation_type' => CalculationType::PERCENTAGE,
    ]);

    expect($fee->formatted_value)->toBe('5%');
});

it('formats decimal percentage value correctly', function () {
    $fee = FeeRule::factory()->create([
        'value' => 12.5,
        'calculation_type' => CalculationType::PERCENTAGE,
    ]);

    expect($fee->formatted_value)->toBe('12.5%');
});
