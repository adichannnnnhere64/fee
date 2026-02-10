<?php

use Repay\Fee\Enums\CalculationType;
use Repay\Fee\Models\FeeHistory;

it('returns raw changes with formatted value for flat rate', function () {
    $history = new FeeHistory([
        'old_data' => [
            'value' => '45.0000',
            'fee_type' => 'markup',
            'item_type' => 'product',
            'calculation_type' => CalculationType::FLAT->value,
        ],
        'new_data' => [
            'value' => '50.0000',
            'fee_type' => 'markdown',
            'item_type' => 'service',
            'calculation_type' => CalculationType::FLAT->value,
        ],
    ]);

    $changes = $history->changes;

    expect($changes)->toBeArray()
        ->toHaveKeys(['value', 'fee_type', 'item_type'])
        ->and($changes['value']['value_formatted']['old'])->toBe('₱45.00')
        ->and($changes['value']['value_formatted']['new'])->toBe('₱50.00');
});

it('returns raw changes with formatted value for percentage', function () {
    $history = new FeeHistory([
        'old_data' => [
            'value' => '0.0000',
            'fee_type' => 'markup',
            'item_type' => 'product',
            'calculation_type' => CalculationType::PERCENTAGE->value,
        ],
        'new_data' => [
            'value' => '12.3456',
            'fee_type' => 'markup',
            'item_type' => 'product',
            'calculation_type' => CalculationType::PERCENTAGE->value,
        ],
    ]);

    $changes = $history->changes;

    expect($changes['value']['value_formatted']['old'])->toBe('0.00%')
        ->and($changes['value']['value_formatted']['new'])->toBe('12.35%');
});

it('handles null old_data correctly', function () {
    $history = new FeeHistory([
        'old_data' => null,
        'new_data' => [
            'value' => '50.0000',
            'fee_type' => 'markdown',
            'item_type' => 'service',
            'calculation_type' => CalculationType::FLAT->value,
        ],
    ]);

    $changes = $history->changes;

    expect($changes['value']['value_formatted']['old'])->toBeNull()
        ->and($changes['value']['value_formatted']['new'])->toBe('₱50.00');
});

it('returns empty array when new_data is empty', function () {
    $history = new FeeHistory([
        'old_data' => [
            'value' => '45.0000',
            'fee_type' => 'markup',
            'item_type' => 'product',
            'calculation_type' => CalculationType::FLAT->value,
        ],
        'new_data' => [],
    ]);

    expect($history->changes)->toBeArray()
        ->toBeEmpty();
});

